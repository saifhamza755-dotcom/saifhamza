<?php

namespace App\Http\Controllers;

use App\Models\AccreditationDecision;
use App\Models\AccreditationRequest;
use App\Models\AuditLog;
use App\Models\ExternalEvaluation;
use App\Models\ImprovementPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformAccreditationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AccreditationRequest::with(['evaluation.report', 'university'])->latest();
        if (! $request->user()->hasRole('central')) {
            $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
            abort_unless($assignment->role->key === 'university', 403);
            $query->where('university_id', $assignment->university_id);
        }

        return response()->json(['requests' => $query->get()->map(fn (AccreditationRequest $item) => $this->data($item))]);
    }

    /** ينشئ طلب اعتماد بعد اكتمال التقييم الخارجي وخطة التحسين المطلوبة. */
    public function submit(Request $request, ExternalEvaluation $evaluation): JsonResponse
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        abort_unless($assignment->role->key === 'university', 403);
        $evaluation->load('report');
        abort_unless($evaluation->status === 'approved' && $evaluation->report->university_id === $assignment->university_id, 422, 'يلزم تقييم خارجي معتمد ضمن جامعتك.');
        $plan = ImprovementPlan::where(['source_type' => 'external_evaluation', 'source_id' => $evaluation->id])->first();
        if (! $plan || $plan->status !== 'completed') {
            throw ValidationException::withMessages(['improvement' => 'يلزم إكمال خطة التحسين المرتبطة بالتقييم الخارجي قبل تقديم الطلب.']);
        }
        $data = $request->validate(['rationale' => ['required', 'string', 'max:10000']]);
        $item = AccreditationRequest::updateOrCreate(['external_evaluation_id' => $evaluation->id], ['university_id' => $evaluation->report->university_id, 'evaluation_cycle_id' => $evaluation->report->evaluation_cycle_id, 'rationale' => $data['rationale'], 'status' => 'submitted', 'submitted_by' => $request->user()->id, 'submitted_at' => now(), 'review_notes' => null]);
        $this->audit($request, 'accreditation_request.submitted', $item);

        return response()->json(['request' => $this->data($item->fresh()->load(['evaluation.report', 'university']))], 201);
    }

    /** يسجل قرار الاعتماد النهائي وأسبابه وشروطه إن وجدت. */
    public function decide(Request $request, AccreditationRequest $item): JsonResponse
    {
        abort_unless($request->user()->hasRole('central'), 403);
        abort_unless($item->status === 'submitted', 409, 'الطلب ليس قيد المراجعة.');
        $data = $request->validate(['decisionType' => ['required', Rule::in(['accredited', 'conditional', 'not_accredited', 'deferred'])], 'rationale' => ['required', 'string', 'max:10000'], 'conditions' => ['nullable', 'string', 'max:10000'], 'validFrom' => ['nullable', 'date'], 'validUntil' => ['nullable', 'date', 'after:validFrom']]);
        if (in_array($data['decisionType'], ['accredited', 'conditional'], true) && (! $data['validFrom'] || ! $data['validUntil'])) {
            throw ValidationException::withMessages(['validFrom' => 'تاريخا السريان مطلوبان للاعتماد.']);
        }
        if ($data['decisionType'] === 'conditional' && blank($data['conditions'] ?? null)) {
            throw ValidationException::withMessages(['conditions' => 'شروط الاعتماد المشروط مطلوبة.']);
        }
        $evaluation = $item->evaluation;
        $decision = AccreditationDecision::create(['external_evaluation_id' => $evaluation->id, 'university_id' => $item->university_id, 'evaluation_cycle_id' => $item->evaluation_cycle_id, 'decision_number' => 'QA-'.now()->format('Y').'-'.Str::upper(Str::random(7)), 'decision_type' => $data['decisionType'], 'status' => 'issued', 'final_score' => $evaluation->total_score, 'valid_from' => $data['validFrom'], 'valid_until' => $data['validUntil'], 'rationale' => $data['rationale'], 'conditions' => $data['conditions'] ?? null, 'issued_by' => $request->user()->id, 'issued_at' => now()]);
        $item->update(['status' => 'issued', 'review_notes' => $data['rationale']]);
        $this->audit($request, 'accreditation_decision.issued', $item);

        return response()->json(['request' => $this->data($item->fresh()->load(['evaluation.report', 'university'])), 'decisionId' => $decision->id]);
    }

    private function data(AccreditationRequest $item): array
    {
        $labels = ['submitted' => 'قيد المراجعة', 'issued' => 'صدر القرار', 'returned' => 'معاد للاستكمال'];
        $decision = AccreditationDecision::where('external_evaluation_id', $item->external_evaluation_id)->first();

        return ['requestId' => $item->id, 'cycleId' => 'cy'.$item->evaluation_cycle_id, 'universityId' => 'u'.$item->university_id, 'evaluationId' => $item->external_evaluation_id, 'status' => $labels[$item->status], 'note' => $item->rationale, 'reviewNote' => $item->review_notes ?? '', 'decisionType' => $decision?->decision_type ?? '', 'decisionNumber' => $decision?->decision_number ?? '', 'history' => []];
    }

    private function audit(Request $request, string $action, AccreditationRequest $item): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'target_type' => AccreditationRequest::class, 'target_id' => $item->id, 'ip_address' => $request->ip()]);
    }
}
