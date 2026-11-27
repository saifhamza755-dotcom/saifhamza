<?php

namespace App\Http\Controllers;

use App\Models\AssessmentResponse;
use App\Models\AuditLog;
use App\Models\EvaluationCycle;
use App\Models\EvidenceFile;
use App\Models\SelfAssessment;
use App\Models\UniversityReport;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformAssessmentController extends Controller
{
    /** يعيد التقويمات المسموح للمستخدم برؤيتها حسب دوره ونطاق تكليفه. */
    public function index(Request $request): JsonResponse
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        $query = SelfAssessment::with([
            'cycle.standardsVersion.standards.indicators', 'responses.indicator',
            'responses.evidenceFiles', 'college', 'university',
        ])->latest();

        if (in_array($assignment->role->key, ['college', 'dean'], true)) {
            $query->where('college_id', $assignment->college_id);
        } elseif ($assignment->role->key === 'university') {
            $query->where('university_id', $assignment->university_id);
        } elseif ($assignment->role->key !== 'central') {
            $query->whereRaw('1 = 0');
        }

        return response()->json(['assessments' => $query->get()->map(fn (SelfAssessment $assessment) => $this->assessmentData($assessment))]);
    }

    /** ينشئ تقويمًا ذاتيًا للكلية في دورة مفتوحة ويولد استجابات المؤشرات الناقصة. */
    public function start(Request $request, EvaluationCycle $cycle): JsonResponse
    {
        $assignment = $this->collegeAssignment($request, 'college');
        abort_unless($cycle->status === 'open', 409, 'لا يمكن بدء تقويم في دورة غير مفتوحة.');
        abort_unless($cycle->colleges()->whereKey($assignment->college_id)->exists(), 403);

        $assessment = SelfAssessment::firstOrCreate(
            ['evaluation_cycle_id' => $cycle->id, 'college_id' => $assignment->college_id],
            ['university_id' => $assignment->university_id, 'status' => 'draft', 'created_by' => $request->user()->id]
        );
        $this->createMissingResponses($assessment);
        $this->audit($request, 'self_assessment.started', $assessment);

        return response()->json(['assessment' => $this->assessmentData($assessment->fresh()->load($this->relations()))], 201);
    }

    /** يحفظ الدرجات والتعليقات ويعيد احتساب النتيجة الموزونة للتقويم. */
    public function save(Request $request, SelfAssessment $assessment): JsonResponse
    {
        $this->authorizeCollege($request, $assessment, 'college');
        abort_unless($assessment->load('cycle')->isEditable(), 409, 'التقويم غير متاح للتعديل.');
        $data = $request->validate([
            'scores' => ['required', 'array'], 'scores.*' => ['nullable', 'integer', 'between:0,4'],
            'comments' => ['nullable', 'array'], 'comments.*' => ['nullable', 'string', 'max:2000'],
            'report_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $validIndicators = $this->indicatorIds($assessment);

        DB::transaction(function () use ($data, $validIndicators, $assessment, $request) {
            foreach ($validIndicators as $indicatorId) {
                $key = 'i'.$indicatorId;
                AssessmentResponse::updateOrCreate(
                    ['self_assessment_id' => $assessment->id, 'indicator_id' => $indicatorId],
                    ['score' => $data['scores'][$key] ?? null, 'notes' => $data['comments'][$key] ?? null, 'updated_by' => $request->user()->id]
                );
            }
            $assessment->update([
                'report_notes' => $data['report_notes'] ?? $assessment->report_notes,
                'total_score' => $this->calculateTotal($assessment->fresh()),
            ]);
        });
        $this->audit($request, 'self_assessment.saved', $assessment);

        return response()->json(['assessment' => $this->assessmentData($assessment->fresh()->load($this->relations()))]);
    }

    /** يرسل التقرير للعميد بعد التأكد من اكتمال الدرجات والأدلة المطلوبة. */
    public function submit(Request $request, SelfAssessment $assessment): JsonResponse
    {
        $this->authorizeCollege($request, $assessment, 'college');
        abort_unless($assessment->load('cycle')->isEditable(), 409, 'التقويم غير متاح للإرسال.');
        $this->createMissingResponses($assessment);
        if ($assessment->responses()->whereNull('score')->exists()) {
            throw ValidationException::withMessages(['responses' => 'يجب إدخال درجات جميع المؤشرات قبل الإرسال.']);
        }
        $assessment->load('responses.indicator', 'responses.evidenceFiles');
        $missingEvidence = $assessment->responses->filter(fn ($response) => $response->indicator->evidence_required
            && $response->evidenceFiles->where('status', '!=', 'returned')->isEmpty())->count();
        if ($missingEvidence) {
            throw ValidationException::withMessages(['evidence' => "يجب إرفاق دليل صالح لكل مؤشر مطلوب. المتبقي: {$missingEvidence}."]);
        }
        $assessment->update([
            'status' => 'submitted', 'total_score' => $this->calculateTotal($assessment),
            'submitted_by' => $request->user()->id, 'submitted_at' => now(),
            'reviewed_by' => null, 'reviewed_at' => null,
        ]);
        $this->audit($request, 'self_assessment.submitted', $assessment);
        $notifications = app(NotificationService::class);
        $notifications->send($notifications->usersWithRole('dean', $assessment->university_id, $assessment->college_id), 'تقويم بانتظار المراجعة', 'أرسلت الكلية تقويمها الذاتي للمراجعة.', 'assessment-reviews.show', ['assessment' => $assessment->id]);

        return response()->json(['assessment' => $this->assessmentData($assessment->fresh()->load($this->relations()))]);
    }

    /** يرفع دليلًا حقيقيًا إلى التخزين الخاص ويربطه باستجابة مؤشر محدد. */
    public function uploadEvidence(Request $request, AssessmentResponse $response): JsonResponse
    {
        $response->load('assessment.cycle');
        $this->authorizeCollege($request, $response->assessment, 'college');
        abort_unless($response->assessment->isEditable(), 409, 'لا يمكن رفع دليل في الحالة الحالية.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ]);
        $file = $request->file('file');
        $path = $file->store("evidence/{$response->assessment->id}/{$response->id}", 'local');
        abort_unless($path, 500, 'تعذر حفظ الملف.');
        $evidence = EvidenceFile::create([
            'assessment_response_id' => $response->id, 'title' => $data['title'],
            'original_name' => $file->getClientOriginalName(), 'stored_path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()), 'status' => 'pending',
            'uploaded_by' => $request->user()->id,
        ]);
        $this->audit($request, 'evidence.uploaded', $evidence);

        return response()->json(['evidence' => $this->evidenceData($evidence, $response->indicator_id)], 201);
    }

    public function deleteEvidence(Request $request, EvidenceFile $evidence): JsonResponse
    {
        $evidence->load('response.assessment.cycle');
        $this->authorizeCollege($request, $evidence->response->assessment, 'college');
        abort_unless($evidence->response->assessment->isEditable(), 409, 'لا يمكن حذف الدليل في الحالة الحالية.');
        Storage::disk('local')->delete($evidence->stored_path);
        $this->audit($request, 'evidence.deleted', $evidence);
        $evidence->delete();

        return response()->json(['message' => 'تم حذف الدليل.']);
    }

    /** يسمح للعميد باعتماد الدليل أو إعادته مع ملاحظة توضح المطلوب. */
    public function reviewEvidence(Request $request, EvidenceFile $evidence): JsonResponse
    {
        $evidence->load('response.assessment');
        $this->authorizeCollege($request, $evidence->response->assessment, 'dean');
        abort_unless($evidence->response->assessment->status === 'submitted', 409);
        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'returned'])],
            'review_notes' => ['nullable', 'string', 'max:2000', Rule::requiredIf($request->input('status') === 'returned')],
        ]);
        $evidence->update([
            'status' => $data['status'], 'review_notes' => $data['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ]);
        $this->audit($request, 'evidence.'.$data['status'], $evidence);

        if ($data['status'] === 'returned') {
            $assessment = $evidence->response->assessment;
            $assessment->update([
                'status' => 'returned',
                'review_notes' => 'أعيد دليل للاستكمال: '.$data['review_notes'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            $this->audit($request, 'self_assessment.returned_for_evidence', $assessment);
            $notifications = app(NotificationService::class);
            $notifications->send(
                $notifications->usersWithRole('college', $assessment->university_id, $assessment->college_id),
                'أعيد دليل للاستكمال',
                'أعاد العميد أحد الأدلة مع ملاحظة. افتح الأدلة والشواهد واستبدل الدليل ثم أرسل التقرير للمراجعة.',
                'platform'
            );
        }

        return response()->json(['message' => 'تمت مراجعة الدليل.']);
    }

    /** يسجل قرار العميد على تقرير الكلية بعد التحقق من حالة الأدلة. */
    public function decide(Request $request, SelfAssessment $assessment): JsonResponse
    {
        $this->authorizeCollege($request, $assessment, 'dean');
        abort_unless($assessment->status === 'submitted', 409, 'تمت معالجة هذا التقرير مسبقًا.');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'returned'])],
            'review_notes' => ['nullable', 'string', 'max:3000', Rule::requiredIf($request->input('decision') === 'returned')],
        ]);
        if ($data['decision'] === 'approved') {
            $assessment->load('responses.indicator', 'responses.evidenceFiles');
            $unaccepted = $assessment->responses->filter(fn ($response) => $response->indicator->evidence_required
                && $response->evidenceFiles->where('status', 'accepted')->isEmpty())->count();
            if ($unaccepted) {
                throw ValidationException::withMessages(['decision' => "يجب قبول جميع الأدلة المطلوبة أولًا. المتبقي: {$unaccepted}."]);
            }
        }
        $assessment->update([
            'status' => $data['decision'], 'review_notes' => $data['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ]);
        $this->audit($request, 'self_assessment.'.$data['decision'], $assessment);

        return response()->json(['assessment' => $this->assessmentData($assessment->fresh()->load($this->relations()))]);
    }

    /** يعيد مسؤول الجامعة تقرير كلية معتمدًا للاستكمال قبل إرسال التقرير الجامعي. */
    public function reopen(Request $request, SelfAssessment $assessment): JsonResponse
    {
        $assignment = $request->user()->activeAssignments()
            ->whereHas('role', fn ($query) => $query->where('key', 'university'))
            ->whereNotNull('university_id')
            ->firstOrFail();
        abort_unless($assignment->university_id === $assessment->university_id, 403);
        abort_unless($assessment->status === 'approved', 409, 'لا يمكن إعادة تقرير غير معتمد.');

        $reportStatus = UniversityReport::where('evaluation_cycle_id', $assessment->evaluation_cycle_id)
            ->where('university_id', $assessment->university_id)
            ->value('status');
        abort_unless(! $reportStatus || in_array($reportStatus, ['draft', 'returned'], true), 409, 'لا يمكن إعادة تقرير كلية بعد إرسال التقرير الجامعي أو اعتماده.');

        $data = $request->validate([
            'review_notes' => ['required', 'string', 'max:3000'],
        ]);
        $assessment->update([
            'status' => 'returned',
            'review_notes' => $data['review_notes'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        $this->audit($request, 'self_assessment.reopened_by_university', $assessment);
        $notifications = app(NotificationService::class);
        $notifications->send(
            $notifications->usersWithRole('college', $assessment->university_id, $assessment->college_id),
            'أعيد تقرير الكلية للاستكمال',
            $data['review_notes'],
            'assessments.edit',
            ['assessment' => $assessment->id]
        );

        return response()->json(['assessment' => $this->assessmentData($assessment->fresh()->load($this->relations()))]);
    }

    /** يبسط نموذج التقويم إلى JSON عربي تفهمه واجهة المنصة. */
    private function assessmentData(SelfAssessment $assessment): array
    {
        $statuses = ['draft' => 'مسودة', 'submitted' => 'بانتظار مراجعة العميد', 'returned' => 'معاد للاستكمال', 'approved' => 'معتمد'];
        $scores = [];
        $comments = [];
        $evidence = [];
        foreach ($assessment->responses as $response) {
            $indicatorId = 'i'.$response->indicator_id;
            if ($response->score !== null) {
                $scores[$indicatorId] = (int) $response->score;
            }
            $comments[$indicatorId] = $response->notes ?? '';
            foreach ($response->evidenceFiles as $file) {
                $evidence[] = $this->evidenceData($file, $response->indicator_id, $response->id);
            }
        }

        return [
            'assessmentId' => $assessment->id, 'cycleId' => 'cy'.$assessment->evaluation_cycle_id,
            'universityId' => 'u'.$assessment->university_id, 'collegeId' => 'c'.$assessment->college_id,
            'status' => $statuses[$assessment->status], 'scores' => $scores, 'comments' => $comments,
            'evidence' => $evidence, 'note' => $assessment->report_notes ?? '',
            'reviewNote' => $assessment->review_notes ?? '', 'totalScore' => (float) $assessment->total_score,
            'editable' => $assessment->isEditable(),
            'responses' => $assessment->responses->mapWithKeys(fn ($response) => ['i'.$response->indicator_id => 'r'.$response->id]),
            'history' => [],
        ];
    }

    private function evidenceData(EvidenceFile $evidence, int $indicatorId, ?int $responseId = null): array
    {
        $statuses = ['pending' => 'قيد المراجعة', 'accepted' => 'معتمد', 'returned' => 'معاد للاستكمال'];

        return [
            'id' => 'ev'.$evidence->id, 'responseId' => 'r'.($responseId ?? $evidence->assessment_response_id),
            'indicatorId' => 'i'.$indicatorId, 'name' => $evidence->original_name,
            'title' => $evidence->title, 'size' => $evidence->size_bytes,
            'status' => $statuses[$evidence->status], 'reviewNote' => $evidence->review_notes ?? '',
            'downloadUrl' => route('evidence.download', $evidence),
        ];
    }

    private function createMissingResponses(SelfAssessment $assessment): void
    {
        foreach ($this->indicatorIds($assessment) as $indicatorId) {
            $assessment->responses()->firstOrCreate(['indicator_id' => $indicatorId]);
        }
        $assessment->load($this->relations());
    }

    private function indicatorIds(SelfAssessment $assessment): array
    {
        $assessment->loadMissing('cycle.standardsVersion.standards.indicators');

        return $assessment->cycle->standardsVersion->standards->flatMap->indicators->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** يحسب الدرجة النهائية باستخدام وزن المعيار ووزن المؤشر والدرجة من أربعة. */
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

    private function collegeAssignment(Request $request, string $role)
    {
        return $request->user()->activeAssignments()->whereHas('role', fn ($query) => $query->where('key', $role))
            ->whereNotNull('college_id')->firstOrFail();
    }

    /** يمنع أي كلية أو عميد من التعامل مع تقرير كلية مختلفة. */
    private function authorizeCollege(Request $request, SelfAssessment $assessment, string $role): void
    {
        abort_unless($this->collegeAssignment($request, $role)->college_id === $assessment->college_id, 403);
    }

    private function relations(): array
    {
        return ['cycle.standardsVersion.standards.indicators', 'responses.indicator', 'responses.evidenceFiles', 'college', 'university'];
    }

    private function audit(Request $request, string $action, $target): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action,
            'target_type' => $target::class, 'target_id' => $target->getKey(), 'ip_address' => $request->ip(),
        ]);
    }
}
