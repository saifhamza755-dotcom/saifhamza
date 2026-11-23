<?php

namespace App\Http\Controllers;

use App\Models\AccreditationDecision;
use App\Models\AuditLog;
use App\Models\ExternalEvaluation;
use App\Models\ImprovementPlan;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccreditationDecisionController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireCentral($request);
        $eligibleEvaluations = ExternalEvaluation::with(['report.university', 'report.cycle'])
            ->where('status', 'approved')->whereDoesntHave('accreditationDecision')->latest()->get();
        $decisions = AccreditationDecision::with(['university', 'cycle', 'evaluation.report', 'issuer'])
            ->latest('issued_at')->get();

        return view('accreditation.index', compact('eligibleEvaluations', 'decisions'));
    }

    public function store(Request $request, ExternalEvaluation $evaluation): RedirectResponse
    {
        $this->requireCentral($request);
        abort_unless($evaluation->status === 'approved', 409, 'يجب اعتماد التقييم الخارجي أولاً.');
        abort_if($evaluation->accreditationDecision()->exists(), 409, 'صدر قرار لهذا التقييم مسبقًا.');
        $data = $request->validate([
            'decision_type' => ['required', Rule::in(['accredited', 'conditional', 'not_accredited', 'deferred'])],
            'valid_from' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'rationale' => ['required', 'string', 'max:10000'], 'conditions' => ['nullable', 'string', 'max:10000'],
        ]);
        if (in_array($data['decision_type'], ['accredited', 'conditional'], true)
            && (empty($data['valid_from']) || empty($data['valid_until']))) {
            throw ValidationException::withMessages(['valid_from' => 'تاريخا بداية ونهاية السريان مطلوبان لقرار الاعتماد.']);
        }
        if ($data['decision_type'] === 'conditional' && empty($data['conditions'])) {
            throw ValidationException::withMessages(['conditions' => 'يجب تحديد شروط الاعتماد المشروط.']);
        }
        $evaluation->load('report');
        $decision = AccreditationDecision::create($data + [
            'external_evaluation_id' => $evaluation->id, 'university_id' => $evaluation->report->university_id,
            'evaluation_cycle_id' => $evaluation->report->evaluation_cycle_id,
            'decision_number' => $this->decisionNumber(), 'status' => 'issued',
            'final_score' => $evaluation->total_score, 'issued_by' => $request->user()->id, 'issued_at' => now(),
        ]);
        $this->audit($request, 'accreditation_decision.issued', $decision);
        $notifications = app(NotificationService::class);
        $notifications->send($notifications->usersWithRole('university', $decision->university_id), 'صدر قرار الاعتماد', "صدر القرار رقم {$decision->decision_number} ويمكن مراجعة الإدارة المركزية للتفاصيل.");

        return redirect()->route('accreditation.show', $decision)->with('success', 'تم إصدار قرار الاعتماد وتثبيت نتائجه.');
    }

    public function show(Request $request, AccreditationDecision $decision): View
    {
        $this->requireCentral($request);
        $decision->load([
            'university', 'cycle.standardsVersion.standards.indicators', 'issuer', 'revoker',
            'evaluation.responses', 'evaluation.evaluator', 'evaluation.report',
        ]);
        $externalResponses = $decision->evaluation->responses->keyBy('indicator_id');
        $externalScores = $decision->cycle->standardsVersion->standards->mapWithKeys(fn ($standard) => [
            $standard->id => round($standard->indicators->sum(fn ($indicator) => (float) ($externalResponses->get($indicator->id)?->score ?? 0) * ((float) $indicator->weight / 100)), 2),
        ]);
        $selfScores = collect($decision->evaluation->report->standards_snapshot)->keyBy('standard_id');
        $improvementPlan = ImprovementPlan::with('actions')->where('source_type', 'external_evaluation')
            ->where('source_id', $decision->external_evaluation_id)->first();

        return view('accreditation.show', compact('decision', 'externalScores', 'selfScores', 'improvementPlan'));
    }

    public function revoke(Request $request, AccreditationDecision $decision): RedirectResponse
    {
        $this->requireCentral($request);
        abort_unless($decision->status === 'issued', 409, 'القرار ملغى مسبقًا.');
        $data = $request->validate(['revocation_reason' => ['required', 'string', 'max:5000']]);
        $decision->update([
            'status' => 'revoked', 'revocation_reason' => $data['revocation_reason'],
            'revoked_by' => $request->user()->id, 'revoked_at' => now(),
        ]);
        $this->audit($request, 'accreditation_decision.revoked', $decision);

        return back()->with('success', 'تم إلغاء القرار وتسجيل السبب في سجل التدقيق.');
    }

    private function decisionNumber(): string
    {
        do {
            $number = 'QA-'.now()->format('Y').'-'.Str::upper(Str::random(7));
        } while (AccreditationDecision::where('decision_number', $number)->exists());

        return $number;
    }

    private function requireCentral(Request $request): void
    {
        abort_unless($request->user()->hasRole('central'), 403);
    }

    private function audit(Request $request, string $action, AccreditationDecision $decision): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'target_type' => AccreditationDecision::class, 'target_id' => $decision->id, 'ip_address' => $request->ip()]);
    }
}
