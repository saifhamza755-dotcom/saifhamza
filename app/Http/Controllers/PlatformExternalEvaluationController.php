<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EvaluationTeam;
use App\Models\ExternalEvaluation;
use App\Models\ExternalEvaluationResponse;
use App\Models\UniversityReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformExternalEvaluationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ExternalEvaluation::with(['report.cycle.standardsVersion.standards.indicators', 'report.cycle.colleges', 'team.members', 'responses']);
        if (! $request->user()->hasRole('central')) {
            abort_unless($request->user()->hasRole('evaluator'), 403);
            $query->whereHas('team.members', fn ($members) => $members->whereKey($request->user()->id));
        }

        return response()->json(['evaluations' => $query->latest()->get()->map(fn (ExternalEvaluation $evaluation) => $this->data($evaluation, $request))]);
    }

    public function assign(Request $request, UniversityReport $report): JsonResponse
    {
        $this->central($request);
        abort_unless($report->status === 'approved', 409, 'يجب اعتماد التقرير الجامعي قبل التكليف الخارجي.');
        abort_if($report->externalEvaluation()->exists(), 409, 'يوجد تقييم خارجي لهذا التقرير بالفعل.');
        $data = $request->validate(['teamId' => ['required', 'string']]);
        $team = EvaluationTeam::with(['members', 'visits'])->findOrFail($this->id($data['teamId'], 't'));
        abort_unless($team->evaluation_cycle_id === $report->evaluation_cycle_id && $team->university_id === $report->university_id, 422, 'الفريق لا يطابق الجامعة والدورة.');
        abort_unless($team->members->isNotEmpty(), 422, 'يجب أن يحتوي فريق التقييم على عضو واحد على الأقل.');
        abort_unless($team->visits->contains('status', 'completed'), 422, 'أكمل زيارة ميدانية موثقة قبل بدء التقييم الخارجي.');

        $evaluation = ExternalEvaluation::create([
            'university_report_id' => $report->id, 'evaluation_team_id' => $team->id,
            'evaluator_id' => $team->members->first()->id, 'status' => 'assigned',
            'assigned_by' => $request->user()->id, 'assigned_at' => now(),
        ]);
        $this->audit($request, 'platform_external.assigned', $evaluation);

        return response()->json(['evaluation' => $this->data($evaluation->load(['report.cycle.standardsVersion.standards.indicators', 'report.cycle.colleges', 'team.members', 'responses']), $request)], 201);
    }

    public function saveScores(Request $request, ExternalEvaluation $evaluation): JsonResponse
    {
        $this->evaluator($request, $evaluation);
        $this->editable($evaluation);
        $data = $request->validate(['collegeId' => ['required', 'string'], 'scores' => ['required', 'array'], 'comments' => ['nullable', 'array']]);
        $collegeId = $this->id($data['collegeId'], 'c');
        abort_unless($evaluation->report->cycle->colleges()->wherePivot('university_id', $evaluation->report->university_id)->whereKey($collegeId)->exists(), 422, 'الكلية خارج نطاق التقرير.');
        $indicatorIds = $evaluation->report->cycle->standardsVersion->standards()->with('indicators')->get()->flatMap->indicators->pluck('id')->map(fn ($id) => (string) $id);
        DB::transaction(function () use ($data, $collegeId, $indicatorIds, $evaluation) {
            foreach ($data['scores'] as $indicatorId => $score) {
                if (! $indicatorIds->contains((string) $indicatorId)) {
                    continue;
                }
                validator(['score' => $score], ['score' => ['nullable', 'integer', 'between:0,4']])->validate();
                ExternalEvaluationResponse::updateOrCreate(
                    ['external_evaluation_id' => $evaluation->id, 'college_id' => $collegeId, 'indicator_id' => $indicatorId],
                    ['score' => $score, 'notes' => $data['comments'][$indicatorId] ?? null]
                );
            }
            $evaluation->update(['total_score' => $this->total($evaluation)]);
        });
        $this->audit($request, 'platform_external.scores_saved', $evaluation);

        return response()->json(['evaluation' => $this->data($evaluation->fresh()->load(['report.cycle.standardsVersion.standards.indicators', 'report.cycle.colleges', 'team.members', 'responses']), $request)]);
    }

    public function saveReport(Request $request, ExternalEvaluation $evaluation): JsonResponse
    {
        $this->evaluator($request, $evaluation);
        $this->editable($evaluation);
        $data = $request->validate(['strengths' => ['nullable', 'string', 'max:10000'], 'weaknesses' => ['nullable', 'string', 'max:10000'], 'recommendations' => ['nullable', 'string', 'max:10000']]);
        $evaluation->update($data);
        $this->audit($request, 'platform_external.report_saved', $evaluation);

        return response()->json(['evaluation' => $this->data($evaluation->fresh()->load(['report.cycle.standardsVersion.standards.indicators', 'report.cycle.colleges', 'team.members', 'responses']), $request)]);
    }

    public function submit(Request $request, ExternalEvaluation $evaluation): JsonResponse
    {
        $this->evaluator($request, $evaluation);
        $this->editable($evaluation);
        $collegeIds = $evaluation->report->cycle->colleges()->wherePivot('university_id', $evaluation->report->university_id)->pluck('colleges.id');
        $indicatorCount = $evaluation->report->cycle->standardsVersion->standards()->withCount('indicators')->get()->sum('indicators_count');
        if ($evaluation->responses()->whereNotNull('score')->count() !== $collegeIds->count() * $indicatorCount || blank($evaluation->strengths) || blank($evaluation->weaknesses) || blank($evaluation->recommendations)) {
            throw ValidationException::withMessages(['evaluation' => 'أكمل درجات جميع مؤشرات الكليات، ونقاط القوة والضعف والتوصيات قبل الإرسال.']);
        }
        $evaluation->update(['status' => 'submitted', 'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null, 'total_score' => $this->total($evaluation)]);
        $this->audit($request, 'platform_external.submitted', $evaluation);

        return response()->json(['evaluation' => $this->data($evaluation->fresh()->load(['report.cycle.standardsVersion.standards.indicators', 'report.cycle.colleges', 'team.members', 'responses']), $request)]);
    }

    public function decide(Request $request, ExternalEvaluation $evaluation): JsonResponse
    {
        $this->central($request);
        abort_unless($evaluation->status === 'submitted', 409, 'التقييم ليس بانتظار المراجعة.');
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'returned'])], 'reviewNote' => ['nullable', 'string', 'max:5000', Rule::requiredIf($request->input('decision') === 'returned')]]);
        $evaluation->update(['status' => $data['decision'], 'review_notes' => $data['reviewNote'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        $this->audit($request, 'platform_external.'.$data['decision'], $evaluation);

        return response()->json(['evaluation' => $this->data($evaluation->fresh()->load(['report.cycle.standardsVersion.standards.indicators', 'report.cycle.colleges', 'team.members', 'responses']), $request)]);
    }

    private function data(ExternalEvaluation $e, Request $request): array
    {
        $labels = ['assigned' => 'مسودة', 'submitted' => 'بانتظار الإدارة المركزية', 'returned' => 'معاد للاستكمال', 'approved' => 'معتمد'];
        $scores = [];
        $comments = [];
        foreach ($e->responses as $response) {
            $key = 'c'.$response->college_id.'|'.$response->indicator_id;
            $scores[$key] = $response->score === null ? null : (int) $response->score;
            $comments[$key] = $response->notes ?? '';
        }

        return ['evaluationId' => $e->id, 'cycleId' => 'cy'.$e->report->evaluation_cycle_id, 'universityId' => 'u'.$e->report->university_id, 'teamId' => $e->evaluation_team_id ? 't'.$e->evaluation_team_id : '', 'status' => $labels[$e->status], 'scores' => $scores, 'comments' => $comments, 'strengths' => $e->strengths ?? '', 'weaknesses' => $e->weaknesses ?? '', 'recommendations' => $e->recommendations ?? '', 'reviewNote' => $e->review_notes ?? '', 'editable' => in_array($e->status, ['assigned', 'returned'], true) && $e->team?->members->contains('id', $request->user()->id), 'history' => []];
    }

    private function total(ExternalEvaluation $e): float
    {
        return round((float) $e->responses()->whereNotNull('score')->avg('score'), 2);
    }

    private function editable(ExternalEvaluation $e): void
    {
        abort_unless(in_array($e->status, ['assigned', 'returned'], true), 409, 'التقييم غير قابل للتعديل الآن.');
    }

    private function central(Request $request): void
    {
        abort_unless($request->user()->hasRole('central'), 403);
    }

    private function evaluator(Request $request, ExternalEvaluation $e): void
    {
        abort_unless($request->user()->hasRole('evaluator') && $e->team?->members()->whereKey($request->user()->id)->exists(), 403);
    }

    private function id(string $value, string $prefix): int
    {
        abort_unless(str_starts_with($value, $prefix) && ctype_digit(substr($value, strlen($prefix))), 422, 'المعرف غير صحيح.');

        return (int) substr($value, strlen($prefix));
    }

    private function audit(Request $request, string $action, ExternalEvaluation $e): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'target_type' => ExternalEvaluation::class, 'target_id' => $e->id, 'ip_address' => $request->ip()]);
    }
}
