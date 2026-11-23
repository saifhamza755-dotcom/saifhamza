<?php

namespace Tests\Feature;

use App\Models\AssessmentResponse;
use App\Models\EvaluationCycle;
use App\Models\SelfAssessment;
use App\Models\StandardsVersion;
use App\Models\University;
use App\Models\UniversityReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformUniversityReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_university_generates_and_submits_report_from_original_interface(): void
    {
        $cycle = $this->completeCycle();
        $universityUser = User::where('username', 'university')->firstOrFail();

        $generated = $this->actingAs($universityUser)
            ->postJson(route('platform.api.university-reports.generate', $cycle))
            ->assertCreated()
            ->assertJsonPath('report.status', 'مسودة')
            ->assertJsonPath('report.collegeCount', 3);

        $reportId = $generated->json('report.reportId');
        $this->putJson(route('platform.api.university-reports.save', $reportId), [
            'executive_summary' => 'ملخص تنفيذي موثق لنتائج الجامعة.',
            'recommendations' => 'تطوير خطط التحسين ومتابعة مؤشرات الأداء.',
        ])->assertOk()->assertJsonPath('report.overallScore', 75);

        $this->postJson(route('platform.api.university-reports.submit', $reportId))
            ->assertOk()->assertJsonPath('report.status', 'بانتظار الإدارة المركزية');

        $this->assertDatabaseHas('audit_logs', ['action' => 'university_report.submitted']);
    }

    public function test_central_returns_then_approves_resubmitted_university_report(): void
    {
        $cycle = $this->completeCycle();
        $universityUser = User::where('username', 'university')->firstOrFail();
        $central = User::where('username', 'central')->firstOrFail();
        $this->actingAs($universityUser)->postJson(route('platform.api.university-reports.generate', $cycle));
        $report = UniversityReport::firstOrFail();
        $this->putJson(route('platform.api.university-reports.save', $report), [
            'executive_summary' => 'ملخص التقرير.', 'recommendations' => 'توصيات التقرير.',
        ]);
        $this->postJson(route('platform.api.university-reports.submit', $report));

        $this->actingAs($central)->postJson(route('platform.api.university-reports.decision', $report), [
            'decision' => 'returned', 'review_notes' => 'يرجى توسيع التوصيات.',
        ])->assertOk()->assertJsonPath('report.status', 'معاد للاستكمال');

        $this->actingAs($universityUser)->postJson(route('platform.api.university-reports.submit', $report))
            ->assertOk();
        $this->actingAs($central)->postJson(route('platform.api.university-reports.decision', $report), [
            'decision' => 'approved', 'review_notes' => '',
        ])->assertOk()->assertJsonPath('report.status', 'معتمد');
    }

    public function test_report_generation_requires_all_colleges_and_correct_role(): void
    {
        $cycle = $this->cycle();
        $universityUser = User::where('username', 'university')->firstOrFail();
        $college = User::where('username', 'college')->firstOrFail();

        $this->actingAs($universityUser)
            ->postJson(route('platform.api.university-reports.generate', $cycle))
            ->assertUnprocessable();

        $this->actingAs($college)
            ->postJson(route('platform.api.university-reports.generate', $cycle))
            ->assertForbidden();
    }

    private function cycle(): EvaluationCycle
    {
        $university = University::where('code', 'SU')->firstOrFail();
        $cycle = EvaluationCycle::create([
            'name' => 'دورة التقرير الجامعي الأصلية', 'code' => 'SPA-UNIVERSITY-2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'status' => 'open',
            'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'),
            'created_by' => User::where('username', 'central')->value('id'),
        ]);
        $cycle->universities()->attach($university->id);
        foreach ($university->colleges as $college) {
            $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);
        }

        return $cycle;
    }

    private function completeCycle(): EvaluationCycle
    {
        $cycle = $this->cycle();
        $university = University::where('code', 'SU')->firstOrFail();
        foreach ($university->colleges as $college) {
            $assessment = SelfAssessment::create([
                'evaluation_cycle_id' => $cycle->id, 'university_id' => $university->id,
                'college_id' => $college->id, 'status' => 'approved', 'total_score' => 75,
                'created_by' => User::where('username', 'college')->value('id'),
            ]);
            foreach ($cycle->standardsVersion->standards()->with('indicators')->get()->flatMap->indicators as $indicator) {
                AssessmentResponse::create([
                    'self_assessment_id' => $assessment->id, 'indicator_id' => $indicator->id, 'score' => 3,
                ]);
            }
        }

        return $cycle;
    }
}
