<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EvaluationCycle;
use App\Models\SelfAssessment;
use App\Models\UniversityReport;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UniversityReportController extends Controller
{
    public function index(Request $request): View
    {
        $assignment = $request->user()->activeAssignments()->with(['role', 'university'])->firstOrFail();
        if ($assignment->role->key === 'university') {
            $universityId = $assignment->university_id;
            $cycles = EvaluationCycle::whereHas('universities', fn ($query) => $query->whereKey($universityId))
                ->with(['standardsVersion', 'universityReports' => fn ($query) => $query->where('university_id', $universityId)])
                ->latest()->get()->map(function ($cycle) use ($universityId) {
                    $collegeIds = $cycle->colleges()->wherePivot('university_id', $universityId)->pluck('colleges.id');
                    $cycle->participating_count = $collegeIds->count();
                    $cycle->approved_count = SelfAssessment::where('evaluation_cycle_id', $cycle->id)
                        ->whereIn('college_id', $collegeIds)->where('status', 'approved')->count();

                    return $cycle;
                });
            $reports = collect();
        } else {
            abort_unless($assignment->role->key === 'central', 403);
            $cycles = collect();
            $reports = UniversityReport::with(['cycle', 'university', 'submitter', 'reviewer'])->latest()->get();
        }

        return view('university-reports.index', compact('assignment', 'cycles', 'reports'));
    }

    public function generate(Request $request, EvaluationCycle $cycle): RedirectResponse
    {
        $assignment = $this->universityAssignment($request);
        abort_unless($cycle->universities()->whereKey($assignment->university_id)->exists(), 403);
        $aggregation = $this->aggregate($cycle, $assignment->university_id);
        $report = UniversityReport::updateOrCreate(
            ['evaluation_cycle_id' => $cycle->id, 'university_id' => $assignment->university_id],
            $aggregation + ['status' => 'draft', 'prepared_by' => $request->user()->id]
        );
        $this->audit($request, 'university_report.generated', $report);

        return redirect()->route('university-reports.show', $report)->with('success', 'تم تجميع نتائج الكليات وإنشاء مسودة التقرير الجامعي.');
    }

    public function show(Request $request, UniversityReport $report): View
    {
        $this->authorizeScope($request, $report);
        $report->load(['cycle', 'university', 'preparer', 'submitter', 'reviewer']);
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        $editable = $assignment->role->key === 'university' && in_array($report->status, ['draft', 'returned'], true);

        return view('university-reports.show', compact('report', 'assignment', 'editable'));
    }

    public function save(Request $request, UniversityReport $report): RedirectResponse
    {
        $assignment = $this->universityAssignment($request);
        abort_unless($assignment->university_id === $report->university_id, 403);
        abort_unless(in_array($report->status, ['draft', 'returned'], true), 409);
        $data = $request->validate([
            'executive_summary' => ['nullable', 'string', 'max:10000'],
            'recommendations' => ['nullable', 'string', 'max:10000'],
        ]);
        $aggregation = $this->aggregate($report->cycle, $report->university_id);
        $report->update($data + $aggregation);
        $this->audit($request, 'university_report.saved', $report);

        return back()->with('success', 'تم حفظ التقرير وتحديث النتائج المجمعة.');
    }

    public function submit(Request $request, UniversityReport $report): RedirectResponse
    {
        $assignment = $this->universityAssignment($request);
        abort_unless($assignment->university_id === $report->university_id, 403);
        abort_unless(in_array($report->status, ['draft', 'returned'], true), 409);
        if (! filled($report->executive_summary) || ! filled($report->recommendations)) {
            throw ValidationException::withMessages(['report' => 'يجب كتابة الملخص التنفيذي والتوصيات قبل إرسال التقرير.']);
        }
        $aggregation = $this->aggregate($report->cycle, $report->university_id);
        $report->update($aggregation + [
            'status' => 'submitted', 'submitted_by' => $request->user()->id, 'submitted_at' => now(),
            'reviewed_by' => null, 'reviewed_at' => null,
        ]);
        $this->audit($request, 'university_report.submitted', $report);
        $notifications = app(NotificationService::class);
        $notifications->send($notifications->usersWithRole('central'), 'تقرير جامعي بانتظار الاعتماد', 'أرسلت جامعة تقريرها الموحد للمراجعة.', 'university-reports.show', ['report' => $report->id]);

        return redirect()->route('university-reports.index')->with('success', 'تم إرسال التقرير الجامعي إلى الإدارة المركزية.');
    }

    public function decide(Request $request, UniversityReport $report): RedirectResponse
    {
        $this->centralAssignment($request);
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

        return redirect()->route('university-reports.index')->with('success', $data['decision'] === 'approved' ? 'تم اعتماد التقرير الجامعي.' : 'تمت إعادة التقرير إلى الجامعة للتعديل.');
    }

    private function aggregate(EvaluationCycle $cycle, int $universityId): array
    {
        $collegeIds = $cycle->colleges()->wherePivot('university_id', $universityId)->pluck('colleges.id');
        abort_if($collegeIds->isEmpty(), 409, 'لا توجد كليات مشاركة لهذه الجامعة.');
        $assessments = SelfAssessment::with(['college', 'responses.indicator.standard'])
            ->where('evaluation_cycle_id', $cycle->id)->whereIn('college_id', $collegeIds)
            ->where('status', 'approved')->get();
        if ($assessments->count() !== $collegeIds->count()) {
            throw ValidationException::withMessages(['coverage' => 'لا يمكن إنشاء التقرير حتى تعتمد تقويمات جميع الكليات المشاركة.']);
        }
        $standards = $cycle->standardsVersion->standards()->with('indicators')->get();
        $standardSnapshot = $standards->map(function ($standard) use ($assessments) {
            $collegeScores = $assessments->map(function ($assessment) use ($standard) {
                $responses = $assessment->responses->keyBy('indicator_id');

                return round($standard->indicators->sum(fn ($indicator) => ((float) ($responses->get($indicator->id)?->score ?? 0) / 4) * ((float) $indicator->weight / 100) * 100), 2);
            });

            return ['standard_id' => $standard->id, 'code' => $standard->code, 'name' => $standard->name_ar, 'weight' => (float) $standard->weight, 'score' => round($collegeScores->avg(), 2)];
        })->all();

        return [
            'overall_score' => round($assessments->avg(fn ($assessment) => (float) $assessment->total_score), 2),
            'college_count' => $assessments->count(), 'standards_snapshot' => $standardSnapshot,
        ];
    }

    private function universityAssignment(Request $request)
    {
        $assignment = $request->user()->activeAssignments()->whereHas('role', fn ($query) => $query->where('key', 'university'))->first();
        abort_unless($assignment && $assignment->university_id, 403);

        return $assignment;
    }

    private function centralAssignment(Request $request): void
    {
        abort_unless($request->user()->hasRole('central'), 403);
    }

    private function authorizeScope(Request $request, UniversityReport $report): void
    {
        if ($request->user()->hasRole('central')) {
            return;
        }
        abort_unless($request->user()->activeAssignments()->where('university_id', $report->university_id)->whereHas('role', fn ($query) => $query->where('key', 'university'))->exists(), 403);
    }

    private function audit(Request $request, string $action, UniversityReport $report): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'target_type' => UniversityReport::class, 'target_id' => $report->id, 'ip_address' => $request->ip()]);
    }
}
