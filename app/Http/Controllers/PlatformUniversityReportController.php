<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EvaluationCycle;
use App\Models\SelfAssessment;
use App\Models\UniversityReport;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformUniversityReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        $query = UniversityReport::with(['cycle', 'university'])->latest();
        if ($assignment->role->key === 'university') {
            $query->where('university_id', $assignment->university_id);
        } elseif ($assignment->role->key !== 'central') {
            $query->whereRaw('1 = 0');
        }

        return response()->json(['reports' => $query->get()->map(fn (UniversityReport $report) => $this->reportData($report))]);
    }

    public function generate(Request $request, EvaluationCycle $cycle): JsonResponse
    {
        $assignment = $this->universityAssignment($request);
        abort_unless($cycle->universities()->whereKey($assignment->university_id)->exists(), 403);
        $aggregation = $this->aggregate($cycle, $assignment->university_id);
        $report = UniversityReport::updateOrCreate(
            ['evaluation_cycle_id' => $cycle->id, 'university_id' => $assignment->university_id],
            $aggregation + ['status' => 'draft', 'prepared_by' => $request->user()->id]
        );
        $this->audit($request, 'university_report.generated', $report);

        return response()->json(['report' => $this->reportData($report->fresh()->load(['cycle', 'university']))], 201);
    }

    public function save(Request $request, UniversityReport $report): JsonResponse
    {
        $assignment = $this->universityAssignment($request);
        abort_unless($assignment->university_id === $report->university_id, 403);
        abort_unless(in_array($report->status, ['draft', 'returned'], true), 409);
        $data = $request->validate([
            'executive_summary' => ['nullable', 'string', 'max:10000'],
            'recommendations' => ['nullable', 'string', 'max:10000'],
        ]);
        $report->update($data + $this->aggregate($report->cycle, $report->university_id));
        $this->audit($request, 'university_report.saved', $report);

        return response()->json(['report' => $this->reportData($report->fresh()->load(['cycle', 'university']))]);
    }

    public function submit(Request $request, UniversityReport $report): JsonResponse
    {
        $assignment = $this->universityAssignment($request);
        abort_unless($assignment->university_id === $report->university_id, 403);
        abort_unless(in_array($report->status, ['draft', 'returned'], true), 409);
        if (! filled($report->executive_summary) || ! filled($report->recommendations)) {
            throw ValidationException::withMessages(['report' => 'يجب كتابة الملخص التنفيذي والتوصيات قبل إرسال التقرير.']);
        }
        $report->update($this->aggregate($report->cycle, $report->university_id) + [
            'status' => 'submitted', 'submitted_by' => $request->user()->id, 'submitted_at' => now(),
            'reviewed_by' => null, 'reviewed_at' => null,
        ]);
        $this->audit($request, 'university_report.submitted', $report);
        $notifications = app(NotificationService::class);
        $notifications->send($notifications->usersWithRole('central'), 'تقرير جامعي بانتظار الاعتماد', 'أرسلت جامعة تقريرها الموحد للمراجعة.', 'university-reports.show', ['report' => $report->id]);

        return response()->json(['report' => $this->reportData($report->fresh()->load(['cycle', 'university']))]);
    }

    public function decide(Request $request, UniversityReport $report): JsonResponse
    {
        abort_unless($request->user()->hasRole('central'), 403);
        abort_unless($report->status === 'submitted', 409);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'returned'])],
            'review_notes' => ['nullable', 'string', 'max:5000', Rule::requiredIf($request->input('decision') === 'returned')],
        ]);
        $report->update([
            'status' => $data['decision'], 'review_notes' => $data['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ]);
        $this->audit($request, 'university_report.'.$data['decision'], $report);
        app(NotificationService::class)->send($report->preparer, $data['decision'] === 'approved' ? 'تم اعتماد التقرير الجامعي' : 'أعيد التقرير الجامعي للتعديل', $data['review_notes'] ?? 'اكتملت مراجعة التقرير.', 'university-reports.show', ['report' => $report->id]);

        return response()->json(['report' => $this->reportData($report->fresh()->load(['cycle', 'university']))]);
    }

    private function aggregate(EvaluationCycle $cycle, int $universityId): array
    {
        $collegeIds = $cycle->colleges()->wherePivot('university_id', $universityId)->pluck('colleges.id');
        abort_if($collegeIds->isEmpty(), 409, 'لا توجد كليات مشاركة لهذه الجامعة.');
        $assessments = SelfAssessment::with(['responses.indicator.standard'])
            ->where('evaluation_cycle_id', $cycle->id)->whereIn('college_id', $collegeIds)
            ->where('status', 'approved')->get();
        if ($assessments->count() !== $collegeIds->count()) {
            throw ValidationException::withMessages(['coverage' => 'لا يمكن إنشاء التقرير حتى تعتمد تقويمات جميع الكليات المشاركة.']);
        }
        $standards = $cycle->standardsVersion->standards()->with('indicators')->get();
        $snapshot = $standards->map(function ($standard) use ($assessments) {
            $collegeScores = $assessments->map(function ($assessment) use ($standard) {
                $responses = $assessment->responses->keyBy('indicator_id');

                return round($standard->indicators->sum(fn ($indicator) => ((float) ($responses->get($indicator->id)?->score ?? 0) / 4) * ((float) $indicator->weight / 100) * 100), 2);
            });

            return ['standard_id' => $standard->id, 'code' => $standard->code, 'name' => $standard->name_ar, 'weight' => (float) $standard->weight, 'score' => round($collegeScores->avg(), 2)];
        })->all();

        return [
            'overall_score' => round($assessments->avg(fn ($assessment) => (float) $assessment->total_score), 2),
            'college_count' => $assessments->count(), 'standards_snapshot' => $snapshot,
        ];
    }

    private function reportData(UniversityReport $report): array
    {
        $statuses = ['draft' => 'مسودة', 'submitted' => 'بانتظار الإدارة المركزية', 'returned' => 'معاد للاستكمال', 'approved' => 'معتمد'];

        return [
            'reportId' => $report->id, 'cycleId' => 'cy'.$report->evaluation_cycle_id,
            'universityId' => 'u'.$report->university_id, 'status' => $statuses[$report->status],
            'executiveSummary' => $report->executive_summary ?? '', 'recommendations' => $report->recommendations ?? '',
            'reviewNote' => $report->review_notes ?? '', 'overallScore' => (float) $report->overall_score,
            'collegeCount' => $report->college_count, 'standardsSnapshot' => $report->standards_snapshot ?? [],
            'editable' => in_array($report->status, ['draft', 'returned'], true), 'history' => [],
        ];
    }

    private function universityAssignment(Request $request)
    {
        $assignment = $request->user()->activeAssignments()->whereHas('role', fn ($query) => $query->where('key', 'university'))->first();
        abort_unless($assignment?->university_id, 403);

        return $assignment;
    }

    private function audit(Request $request, string $action, UniversityReport $report): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action,
            'target_type' => UniversityReport::class, 'target_id' => $report->id, 'ip_address' => $request->ip(),
        ]);
    }
}
