<?php

namespace App\Http\Controllers;

use App\Models\AssessmentResponse;
use App\Models\AuditLog;
use App\Models\EvaluationCycle;
use App\Models\SelfAssessment;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SelfAssessmentController extends Controller
{
    public function index(Request $request): View
    {
        $assignment = $this->collegeAssignment($request);
        $cycles = EvaluationCycle::whereHas('colleges', fn ($query) => $query->whereKey($assignment->college_id))
            ->with(['standardsVersion', 'selfAssessments' => fn ($query) => $query->where('college_id', $assignment->college_id)])
            ->latest()->get();

        return view('assessments.index', compact('cycles', 'assignment'));
    }

    public function start(Request $request, EvaluationCycle $cycle): RedirectResponse
    {
        $assignment = $this->collegeAssignment($request);
        abort_unless($cycle->status === 'open', 409, 'لا يمكن بدء تقويم في دورة غير مفتوحة.');
        abort_unless($cycle->colleges()->whereKey($assignment->college_id)->exists(), 403);

        $assessment = SelfAssessment::firstOrCreate(
            ['evaluation_cycle_id' => $cycle->id, 'college_id' => $assignment->college_id],
            ['university_id' => $assignment->university_id, 'status' => 'draft', 'created_by' => $request->user()->id]
        );
        $this->createMissingResponses($assessment);
        $this->audit($request, 'self_assessment.started', $assessment);

        return redirect()->route('assessments.edit', $assessment);
    }

    public function edit(Request $request, SelfAssessment $assessment): View
    {
        $this->authorizeCollege($request, $assessment);
        $this->createMissingResponses($assessment);
        $assessment->load(['cycle.standardsVersion.standards.indicators', 'responses.evidenceFiles', 'college', 'reviewer']);
        $responses = $assessment->responses->keyBy('indicator_id');
        $standardScores = $this->standardScores($assessment);

        return view('assessments.edit', compact('assessment', 'responses', 'standardScores'));
    }

    public function save(Request $request, SelfAssessment $assessment): RedirectResponse
    {
        $this->authorizeCollege($request, $assessment);
        abort_unless($assessment->load('cycle')->isEditable(), 409, 'التقويم غير متاح للتعديل.');
        $indicatorIds = $this->indicatorIds($assessment);
        $data = $request->validate([
            'responses' => ['required', 'array'],
            'responses.*.score' => ['nullable', 'integer', 'between:0,4'],
            'responses.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($data, $indicatorIds, $assessment, $request) {
            foreach ($data['responses'] as $indicatorId => $response) {
                if (! in_array((int) $indicatorId, $indicatorIds, true)) {
                    continue;
                }
                AssessmentResponse::updateOrCreate(
                    ['self_assessment_id' => $assessment->id, 'indicator_id' => $indicatorId],
                    ['score' => $response['score'] ?? null, 'notes' => $response['notes'] ?? null, 'updated_by' => $request->user()->id]
                );
            }
            $assessment->update(['total_score' => $this->calculateTotal($assessment->fresh())]);
        });
        $this->audit($request, 'self_assessment.saved', $assessment);

        return back()->with('success', 'تم حفظ التقويم كمسودة واحتساب النتيجة الحالية.');
    }

    public function submit(Request $request, SelfAssessment $assessment): RedirectResponse
    {
        $this->authorizeCollege($request, $assessment);
        abort_unless($assessment->load('cycle')->isEditable(), 409, 'التقويم غير متاح للإرسال.');
        $this->createMissingResponses($assessment);
        $missing = $assessment->responses()->whereNull('score')->count();
        if ($missing > 0) {
            throw ValidationException::withMessages(['responses' => "يجب إدخال درجات جميع المؤشرات قبل الإرسال. المتبقي: {$missing}."]);
        }
        $assessment->load('responses.indicator', 'responses.evidenceFiles');
        $missingEvidence = $assessment->responses->filter(fn ($response) => $response->indicator->evidence_required
            && $response->evidenceFiles->where('status', '!=', 'returned')->isEmpty())->count();
        if ($missingEvidence > 0) {
            throw ValidationException::withMessages(['evidence' => "يجب إرفاق دليل صالح لكل مؤشر يتطلب دليلاً. المتبقي: {$missingEvidence}."]);
        }
        $assessment->update([
            'status' => 'submitted', 'total_score' => $this->calculateTotal($assessment),
            'submitted_by' => $request->user()->id, 'submitted_at' => now(),
            'reviewed_by' => null, 'reviewed_at' => null,
        ]);
        $this->audit($request, 'self_assessment.submitted', $assessment);
        $notifications = app(NotificationService::class);
        $notifications->send($notifications->usersWithRole('dean', $assessment->university_id, $assessment->college_id), 'تقويم بانتظار المراجعة', 'أرسلت الكلية تقويمها الذاتي للمراجعة.', 'assessment-reviews.show', ['assessment' => $assessment->id]);

        return redirect()->route('assessments.index')->with('success', 'تم إرسال التقويم إلى العميد للمراجعة.');
    }

    public function reviewIndex(Request $request): View
    {
        $assignment = $this->collegeAssignment($request);
        $assessments = SelfAssessment::with(['cycle', 'college', 'submitter'])
            ->where('college_id', $assignment->college_id)->whereIn('status', ['submitted', 'approved'])->latest()->get();

        return view('assessments.review-index', compact('assessments'));
    }

    public function review(Request $request, SelfAssessment $assessment): View
    {
        $this->authorizeCollege($request, $assessment);
        abort_unless(in_array($assessment->status, ['submitted', 'approved'], true), 409);
        $assessment->load(['cycle.standardsVersion.standards.indicators', 'responses.evidenceFiles', 'college', 'submitter']);
        $responses = $assessment->responses->keyBy('indicator_id');
        $standardScores = $this->standardScores($assessment);

        return view('assessments.review', compact('assessment', 'responses', 'standardScores'));
    }

    public function decide(Request $request, SelfAssessment $assessment): RedirectResponse
    {
        $this->authorizeCollege($request, $assessment);
        abort_unless($assessment->status === 'submitted', 409, 'تمت معالجة هذا التقويم مسبقًا.');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'returned'])],
            'review_notes' => ['nullable', 'string', 'max:3000', Rule::requiredIf($request->input('decision') === 'returned')],
        ]);
        if ($data['decision'] === 'approved') {
            $assessment->load('responses.indicator', 'responses.evidenceFiles');
            $unaccepted = $assessment->responses->filter(fn ($response) => $response->indicator->evidence_required
                && $response->evidenceFiles->where('status', 'accepted')->isEmpty())->count();
            if ($unaccepted > 0) {
                throw ValidationException::withMessages(['decision' => "يجب قبول أدلة جميع المؤشرات قبل اعتماد التقويم. المتبقي: {$unaccepted}."]);
            }
        }
        $assessment->update([
            'status' => $data['decision'], 'review_notes' => $data['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ]);
        $this->audit($request, 'self_assessment.'.$data['decision'], $assessment);
        if ($assessment->submitter) {
            app(NotificationService::class)->send($assessment->submitter, $data['decision'] === 'approved' ? 'تم اعتماد تقويم الكلية' : 'أعيد التقويم للتعديل', $data['review_notes'] ?? 'اكتملت مراجعة التقويم.', 'assessments.edit', ['assessment' => $assessment->id]);
        }

        return redirect()->route('assessment-reviews.index')->with('success', $data['decision'] === 'approved' ? 'تم اعتماد تقويم الكلية.' : 'تمت إعادة التقويم إلى مسؤول الجودة للتعديل.');
    }

    private function collegeAssignment(Request $request)
    {
        return $request->user()->activeAssignments()->whereNotNull('college_id')->with(['college', 'university'])->firstOrFail();
    }

    private function authorizeCollege(Request $request, SelfAssessment $assessment): void
    {
        abort_unless($this->collegeAssignment($request)->college_id === $assessment->college_id, 403);
    }

    private function indicatorIds(SelfAssessment $assessment): array
    {
        return $assessment->cycle->standardsVersion->standards->flatMap->indicators->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function createMissingResponses(SelfAssessment $assessment): void
    {
        $assessment->loadMissing('cycle.standardsVersion.standards.indicators');
        foreach ($this->indicatorIds($assessment) as $indicatorId) {
            $assessment->responses()->firstOrCreate(['indicator_id' => $indicatorId]);
        }
    }

    private function calculateTotal(SelfAssessment $assessment): float
    {
        $assessment->loadMissing(['cycle.standardsVersion.standards.indicators', 'responses']);
        $scores = $assessment->responses->keyBy('indicator_id');
        $total = 0;
        foreach ($assessment->cycle->standardsVersion->standards as $standard) {
            foreach ($standard->indicators as $indicator) {
                $score = (float) ($scores->get($indicator->id)?->score ?? 0);
                $total += ($score / 4) * ((float) $indicator->weight / 100) * ((float) $standard->weight / 100) * 100;
            }
        }

        return round($total, 2);
    }

    private function standardScores(SelfAssessment $assessment): array
    {
        $scores = $assessment->responses->keyBy('indicator_id');

        return $assessment->cycle->standardsVersion->standards->mapWithKeys(function ($standard) use ($scores) {
            $score = $standard->indicators->sum(fn ($indicator) => ((float) ($scores->get($indicator->id)?->score ?? 0) / 4) * ((float) $indicator->weight / 100) * 100);

            return [$standard->id => round($score, 2)];
        })->all();
    }

    private function audit(Request $request, string $action, SelfAssessment $assessment): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'target_type' => SelfAssessment::class, 'target_id' => $assessment->id, 'ip_address' => $request->ip()]);
    }
}
