<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExternalEvaluation;
use App\Models\ExternalEvaluationResponse;
use App\Models\UniversityReport;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExternalEvaluationController extends Controller
{
    public function index(Request $request): View
    {
        $isCentral = $request->user()->hasRole('central');
        if ($isCentral) {
            $reports = UniversityReport::with(['university', 'cycle', 'externalEvaluation.evaluator'])
                ->where('status', 'approved')->latest()->get();
            $evaluators = User::where('active', true)->whereHas('activeAssignments.role', fn ($query) => $query->where('key', 'evaluator'))->orderBy('name')->get();
            $evaluations = collect();
        } else {
            abort_unless($request->user()->hasRole('evaluator'), 403);
            $reports = collect();
            $evaluators = collect();
            $evaluations = ExternalEvaluation::with(['report.university', 'report.cycle'])
                ->where('evaluator_id', $request->user()->id)->latest()->get();
        }

        return view('external-evaluations.index', compact('isCentral', 'reports', 'evaluators', 'evaluations'));
    }

    public function assign(Request $request, UniversityReport $report): RedirectResponse
    {
        $this->requireCentral($request);
        abort_unless($report->status === 'approved', 409, 'يجب اعتماد التقرير الجامعي أولاً.');
        abort_if($report->externalEvaluation()->exists(), 409, 'تم إنشاء تقييم خارجي لهذا التقرير مسبقًا.');
        $data = $request->validate([
            'evaluator_id' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)],
        ]);
        $evaluator = User::findOrFail($data['evaluator_id']);
        abort_unless($evaluator->hasRole('evaluator'), 422, 'المستخدم المحدد ليس مقيمًا خارجيًا.');

        $evaluation = DB::transaction(function () use ($report, $evaluator, $request) {
            $evaluation = ExternalEvaluation::create([
                'university_report_id' => $report->id, 'evaluator_id' => $evaluator->id,
                'status' => 'assigned', 'assigned_by' => $request->user()->id, 'assigned_at' => now(),
            ]);
            $report->cycle->standardsVersion->standards()->with('indicators')->get()->flatMap->indicators
                ->each(fn ($indicator) => $evaluation->responses()->create(['indicator_id' => $indicator->id]));

            return $evaluation;
        });
        $this->audit($request, 'external_evaluation.assigned', $evaluation);
        app(NotificationService::class)->send($evaluator, 'مهمة تقييم خارجي جديدة', 'تم تعيينك لتقييم تقرير جامعي معتمد.', 'external-evaluations.show', ['evaluation' => $evaluation->id]);

        return redirect()->route('external-evaluations.show', $evaluation)->with('success', 'تم تعيين المقيم وإنشاء نموذج التقييم الخارجي.');
    }

    public function show(Request $request, ExternalEvaluation $evaluation): View
    {
        $this->authorizeEvaluation($request, $evaluation);
        $evaluation->load(['report.university', 'report.cycle.standardsVersion.standards.indicators', 'responses', 'evaluator', 'reviewer']);
        $responses = $evaluation->responses->keyBy('indicator_id');
        $standardScores = $this->standardScores($evaluation);
        $selfScores = collect($evaluation->report->standards_snapshot)->keyBy('standard_id');
        $editable = $request->user()->id === $evaluation->evaluator_id && in_array($evaluation->status, ['assigned', 'returned'], true);

        return view('external-evaluations.show', compact('evaluation', 'responses', 'standardScores', 'selfScores', 'editable'));
    }

    public function save(Request $request, ExternalEvaluation $evaluation): RedirectResponse
    {
        $this->requireEvaluator($request, $evaluation);
        abort_unless(in_array($evaluation->status, ['assigned', 'returned'], true), 409);
        $indicatorIds = $this->indicatorIds($evaluation);
        $data = $request->validate([
            'responses' => ['required', 'array'],
            'responses.*.score' => ['nullable', 'numeric', 'between:0,100'],
            'responses.*.notes' => ['nullable', 'string', 'max:3000'],
        ]);
        DB::transaction(function () use ($data, $indicatorIds, $evaluation) {
            foreach ($data['responses'] as $indicatorId => $response) {
                if (! in_array((int) $indicatorId, $indicatorIds, true)) {
                    continue;
                }
                ExternalEvaluationResponse::updateOrCreate(
                    ['external_evaluation_id' => $evaluation->id, 'indicator_id' => $indicatorId],
                    ['score' => $response['score'] ?? null, 'notes' => $response['notes'] ?? null]
                );
            }
            $evaluation->update(['total_score' => $this->calculateTotal($evaluation->fresh())]);
        });
        $this->audit($request, 'external_evaluation.saved', $evaluation);

        return back()->with('success', 'تم حفظ التقييم الخارجي واحتساب النتائج.');
    }

    public function submit(Request $request, ExternalEvaluation $evaluation): RedirectResponse
    {
        $this->requireEvaluator($request, $evaluation);
        abort_unless(in_array($evaluation->status, ['assigned', 'returned'], true), 409);
        if ($evaluation->responses()->whereNull('score')->exists()) {
            throw ValidationException::withMessages(['responses' => 'يجب إدخال درجات جميع المؤشرات قبل الإرسال.']);
        }
        $evaluation->update([
            'status' => 'submitted', 'total_score' => $this->calculateTotal($evaluation),
            'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null,
        ]);
        $this->audit($request, 'external_evaluation.submitted', $evaluation);
        $notifications = app(NotificationService::class);
        $notifications->send($notifications->usersWithRole('central'), 'تقييم خارجي بانتظار الاعتماد', 'أرسل المقيم الخارجي نتائج التقييم للمراجعة.', 'external-evaluations.show', ['evaluation' => $evaluation->id]);

        return redirect()->route('external-evaluations.index')->with('success', 'تم إرسال التقييم الخارجي إلى الإدارة المركزية.');
    }

    public function decide(Request $request, ExternalEvaluation $evaluation): RedirectResponse
    {
        $this->requireCentral($request);
        abort_unless($evaluation->status === 'submitted', 409);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'returned'])],
            'review_notes' => ['nullable', 'string', 'max:5000', Rule::requiredIf($request->input('decision') === 'returned')],
        ]);
        $evaluation->update([
            'status' => $data['decision'], 'review_notes' => $data['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ]);
        $this->audit($request, 'external_evaluation.'.$data['decision'], $evaluation);
        app(NotificationService::class)->send($evaluation->evaluator, $data['decision'] === 'approved' ? 'تم اعتماد التقييم الخارجي' : 'أعيد التقييم الخارجي للتعديل', $data['review_notes'] ?? 'اكتملت مراجعة التقييم.', 'external-evaluations.show', ['evaluation' => $evaluation->id]);

        return redirect()->route('external-evaluations.index')->with('success', $data['decision'] === 'approved' ? 'تم اعتماد التقييم الخارجي.' : 'تمت إعادة التقييم إلى المقيم للتعديل.');
    }

    private function indicatorIds(ExternalEvaluation $evaluation): array
    {
        return $evaluation->report->cycle->standardsVersion->standards->flatMap->indicators->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function calculateTotal(ExternalEvaluation $evaluation): float
    {
        $evaluation->loadMissing(['report.cycle.standardsVersion.standards.indicators', 'responses']);
        $responses = $evaluation->responses->keyBy('indicator_id');

        return round($evaluation->report->cycle->standardsVersion->standards->sum(function ($standard) use ($responses) {
            return $standard->indicators->sum(fn ($indicator) => (float) ($responses->get($indicator->id)?->score ?? 0) * ((float) $indicator->weight / 100)) * ((float) $standard->weight / 100);
        }), 2);
    }

    private function standardScores(ExternalEvaluation $evaluation): array
    {
        $responses = $evaluation->responses->keyBy('indicator_id');

        return $evaluation->report->cycle->standardsVersion->standards->mapWithKeys(fn ($standard) => [
            $standard->id => round($standard->indicators->sum(fn ($indicator) => (float) ($responses->get($indicator->id)?->score ?? 0) * ((float) $indicator->weight / 100)), 2),
        ])->all();
    }

    private function authorizeEvaluation(Request $request, ExternalEvaluation $evaluation): void
    {
        abort_unless($request->user()->hasRole('central') || $request->user()->id === $evaluation->evaluator_id, 403);
    }

    private function requireCentral(Request $request): void
    {
        abort_unless($request->user()->hasRole('central'), 403);
    }

    private function requireEvaluator(Request $request, ExternalEvaluation $evaluation): void
    {
        abort_unless($request->user()->hasRole('evaluator') && $request->user()->id === $evaluation->evaluator_id, 403);
    }

    private function audit(Request $request, string $action, ExternalEvaluation $evaluation): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'target_type' => ExternalEvaluation::class, 'target_id' => $evaluation->id, 'ip_address' => $request->ip()]);
    }
}
