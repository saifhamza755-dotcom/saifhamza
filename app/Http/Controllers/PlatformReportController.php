<?php

namespace App\Http\Controllers;

use App\Models\AccreditationDecision;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\EvaluationCycle;
use App\Models\ExternalEvaluation;
use App\Models\ImprovementAction;
use App\Models\ImprovementPlan;
use App\Models\SelfAssessment;
use App\Models\UniversityReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlatformReportController extends Controller
{
    /** ينشئ ملف Word من بيانات قاعدة البيانات الفعلية، وليس من تخزين المتصفح. */
    public function download(Request $request, string $kind): Response
    {
        $response = match ($kind) {
            'college' => $this->collegeDownload($request),
            'external' => $this->externalDownload($request),
            default => abort(404),
        };
        $this->audit($request, 'reports.'.$kind.'_downloaded', ['format' => 'word']);

        return $response;
    }

    /** ينشئ ملخص الجامعة للتصدير من السجلات المشتركة في قاعدة البيانات. */
    public function summaryDownload(Request $request, string $format): Response
    {
        abort_unless(in_array($format, ['csv', 'word'], true), 404);
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        abort_unless(in_array($assignment->role->key, ['central', 'university'], true), 403);

        $data = $request->validate([
            'cycle_id' => ['required', 'integer'],
            'university_id' => ['required', 'integer'],
        ]);
        abort_unless($assignment->role->key === 'central' || $assignment->university_id === (int) $data['university_id'], 403);

        $cycle = EvaluationCycle::with('standardsVersion')->findOrFail($data['cycle_id']);
        $university = $cycle->universities()->whereKey($data['university_id'])->firstOrFail();
        $collegeIds = $cycle->colleges()->wherePivot('university_id', $university->id)->pluck('colleges.id');
        $colleges = College::whereIn('id', $collegeIds)->orderBy('name_ar')->get();
        $assessments = SelfAssessment::where('evaluation_cycle_id', $cycle->id)
            ->where('university_id', $university->id)->whereIn('college_id', $collegeIds)->get()->keyBy('college_id');
        $universityReport = UniversityReport::where([
            'evaluation_cycle_id' => $cycle->id,
            'university_id' => $university->id,
        ])->latest()->first();
        $external = $universityReport?->externalEvaluation;
        $decision = AccreditationDecision::where([
            'evaluation_cycle_id' => $cycle->id,
            'university_id' => $university->id,
        ])->latest('issued_at')->first();
        $rows = $colleges->map(fn (College $college) => [
            $college->name_ar,
            $assessments->get($college->id)?->total_score ?? 'غير مكتمل',
            $this->assessmentStatus($assessments->get($college->id)?->status ?? ''),
        ]);

        if ($format === 'csv') {
            $csvRows = collect([['الكلية', 'التقويم الذاتي %', 'حالة التقرير']])->merge($rows);
            $csv = "\xEF\xBB\xBF".$csvRows->map(fn ($row) => collect($row)->map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"')->implode(','))->implode("\r\n");

            $this->audit($request, 'reports.summary_exported', ['format' => 'csv', 'cycle_id' => $cycle->id, 'university_id' => $university->id]);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="quality-summary.csv"',
            ]);
        }

        $tableRows = $rows->map(fn ($row) => '<tr><td>'.$this->escape($row[0]).'</td><td>'.$this->escape($row[1]).'</td><td>'.$this->escape($row[2]).'</td></tr>')->implode('');
        $html = $this->document('ملخص نتائج الجودة', [
            'الجامعة' => $university->name_ar,
            'دورة التقييم' => $cycle->name,
            'نسخة المعايير' => $cycle->standardsVersion->name,
            'حالة التقرير الجامعي' => $universityReport?->status ?? 'غير منشأ',
            'حالة التقييم الخارجي' => $external?->status ?? 'غير مكتمل',
            'قرار الاعتماد' => $decision?->decision_type ?? 'غير صادر',
        ], '<table><thead><tr><th>الكلية</th><th>التقويم الذاتي %</th><th>حالة التقرير</th></tr></thead><tbody>'.$tableRows.'</tbody></table>');

        $this->audit($request, 'reports.summary_exported', ['format' => 'word', 'cycle_id' => $cycle->id, 'university_id' => $university->id]);

        return $this->wordResponse($html, 'quality-summary.doc');
    }

    public function index(Request $request): JsonResponse
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        abort_unless(in_array($assignment->role->key, ['central', 'university'], true), 403);
        $cycles = EvaluationCycle::with('universities')->get();
        $rows = $cycles->flatMap(function (EvaluationCycle $cycle) use ($assignment) {
            $universities = $assignment->role->key === 'central' ? $cycle->universities : $cycle->universities->where('id', $assignment->university_id);

            return $universities->map(function ($university) use ($cycle) {
                $collegeIds = $cycle->colleges()->wherePivot('university_id', $university->id)->pluck('colleges.id');
                $assessments = SelfAssessment::where('evaluation_cycle_id', $cycle->id)->whereIn('college_id', $collegeIds);
                $external = ExternalEvaluation::whereHas('report', fn ($report) => $report->where(['evaluation_cycle_id' => $cycle->id, 'university_id' => $university->id]));
                $plans = ImprovementPlan::where(['evaluation_cycle_id' => $cycle->id, 'university_id' => $university->id]);
                $actions = ImprovementAction::whereIn('improvement_plan_id', (clone $plans)->pluck('id'));
                $decision = AccreditationDecision::where(['evaluation_cycle_id' => $cycle->id, 'university_id' => $university->id])->latest('issued_at')->first();

                return ['cycleId' => 'cy'.$cycle->id, 'universityId' => 'u'.$university->id, 'collegeCount' => $collegeIds->count(), 'approvedColleges' => (clone $assessments)->where('status', 'approved')->count(), 'externalStatus' => (clone $external)->latest()->value('status') ?? '', 'externalScore' => (float) ((clone $external)->latest()->value('total_score') ?? 0), 'plans' => (clone $plans)->count(), 'completedActions' => (clone $actions)->where('status', 'completed')->count(), 'actions' => $actions->count(), 'decision' => $decision?->decision_type ?? '', 'decisionNumber' => $decision?->decision_number ?? ''];
            });
        })->values();

        return response()->json(['rows' => $rows, 'summary' => ['universities' => $rows->pluck('universityId')->unique()->count(), 'approvedExternal' => $rows->where('externalStatus', 'approved')->count(), 'decisions' => $rows->where('decision', '!=', '')->count(), 'completedActions' => $rows->sum('completedActions'), 'actions' => $rows->sum('actions')]]);
    }

    /** يبني تقرير كلية خاصًا بصاحب التكليف الحالي فقط. */
    private function collegeDownload(Request $request): Response
    {
        $assignment = $request->user()->activeAssignments()->with(['role', 'university', 'college'])->firstOrFail();
        abort_unless(in_array($assignment->role->key, ['college', 'dean'], true), 403);
        $assessment = SelfAssessment::with(['cycle', 'university', 'college', 'responses.indicator', 'responses.evidenceFiles'])
            ->where('college_id', $assignment->college_id)
            ->latest('updated_at')
            ->firstOrFail();
        $rows = $assessment->responses->map(fn ($response) => '<tr><td>'.$this->escape($response->indicator->name_ar).'</td><td>'.$this->escape($response->score ?? 'غير مقيم').'</td><td>'.$response->evidenceFiles->count().'</td></tr>')->implode('');
        $html = $this->document('تقرير الكلية', [
            'الجامعة' => $assessment->university->name_ar,
            'الكلية' => $assessment->college->name_ar,
            'دورة التقييم' => $assessment->cycle->name,
            'حالة التقرير' => $this->assessmentStatus($assessment->status),
        ], '<table><thead><tr><th>المؤشر</th><th>الدرجة من 4</th><th>عدد الأدلة</th></tr></thead><tbody>'.$rows.'</tbody></table><p>ملاحظات التقرير: '.$this->escape($assessment->report_notes ?: 'لا توجد ملاحظات').'</p>');

        return $this->wordResponse($html, 'college-quality-report.doc');
    }

    /** يبني تقرير التقييم الخارجي للمقيم المكلف أو للإدارة المركزية. */
    private function externalDownload(Request $request): Response
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        abort_unless(in_array($assignment->role->key, ['central', 'evaluator'], true), 403);
        $query = ExternalEvaluation::with(['report.cycle', 'report.university', 'team.members', 'responses.indicator']);
        if ($assignment->role->key === 'evaluator') {
            $query->whereHas('team.members', fn ($members) => $members->whereKey($request->user()->id));
        }
        $evaluation = $query->latest('updated_at')->firstOrFail();
        $collegeNames = College::whereIn('id', $evaluation->responses->pluck('college_id')->filter())->pluck('name_ar', 'id');
        $rows = $evaluation->responses->map(fn ($response) => '<tr><td>'.$this->escape($collegeNames->get($response->college_id, '—')).'</td><td>'.$this->escape($response->indicator->name_ar).'</td><td>'.$this->escape($response->score ?? 'غير مقيم').'</td><td>'.$this->escape($response->notes ?? '—').'</td></tr>')->implode('');
        $html = $this->document('تقرير التقييم الخارجي', [
            'الجامعة' => $evaluation->report->university->name_ar,
            'دورة التقييم' => $evaluation->report->cycle->name,
            'حالة التقييم' => $evaluation->status,
        ], '<table><thead><tr><th>الكلية</th><th>المؤشر</th><th>الدرجة</th><th>ملاحظة المقيم</th></tr></thead><tbody>'.$rows.'</tbody></table><h3>نقاط القوة</h3><p>'.$this->escape($evaluation->strengths ?: '—').'</p><h3>نقاط الضعف</h3><p>'.$this->escape($evaluation->weaknesses ?: '—').'</p><h3>التوصيات</h3><p>'.$this->escape($evaluation->recommendations ?: '—').'</p>');

        return $this->wordResponse($html, 'external-evaluation-report.doc');
    }

    /** يلف محتوى التقرير بتنسيق HTML بسيط يفهمه Microsoft Word. */
    private function document(string $title, array $details, string $body): string
    {
        $meta = collect($details)->map(fn ($value, $label) => '<p><strong>'.$this->escape($label).':</strong> '.$this->escape($value).'</p>')->implode('');

        return '<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><style>body{font-family:Arial;padding:28px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #777;padding:8px;text-align:right}th{background:#eee}</style></head><body><h1>'.$this->escape($title).'</h1>'.$meta.$body.'</body></html>';
    }

    /** يرجع استجابة تنزيل Word آمنة باسم ملف ثابت. */
    private function wordResponse(string $html, string $fileName): Response
    {
        return response("\xEF\xBB\xBF".$html, 200, [
            'Content-Type' => 'application/msword; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    /** يمنع إدخال HTML غير آمن من بيانات المستخدم داخل التقرير المصدر. */
    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** يحول حالة تقرير الكلية الداخلية إلى وصف عربي. */
    private function assessmentStatus(string $status): string
    {
        return ['draft' => 'مسودة', 'submitted' => 'بانتظار مراجعة العميد', 'returned' => 'معاد للاستكمال', 'approved' => 'معتمد'][$status] ?? $status;
    }

    /** يسجل عمليات تنزيل التقارير لتبقى قابلة للمراجعة في سجل التدقيق. */
    private function audit(Request $request, string $action, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
        ]);
    }
}
