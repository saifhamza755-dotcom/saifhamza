<?php

namespace Tests\Feature;

use App\Models\AssessmentResponse;
use App\Models\College;
use App\Models\EvaluationCycle;
use App\Models\EvaluationTeam;
use App\Models\ExternalEvaluation;
use App\Models\ExternalEvaluationResponse;
use App\Models\SelfAssessment;
use App\Models\StandardsVersion;
use App\Models\University;
use App\Models\UniversityReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformReportDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_college_download_is_built_from_server_data(): void
    {
        $university = University::where('code', 'SU')->firstOrFail();
        $college = College::where('code', 'ENG')->firstOrFail();
        $cycle = EvaluationCycle::create([
            'name' => 'دورة تقرير التنزيل',
            'code' => 'DOWNLOAD-2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'status' => 'open',
            'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'),
            'created_by' => User::where('username', 'central')->value('id'),
        ]);
        $cycle->universities()->attach($university->id);
        $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);
        $assessment = SelfAssessment::create([
            'evaluation_cycle_id' => $cycle->id,
            'university_id' => $university->id,
            'college_id' => $college->id,
            'status' => 'approved',
            'report_notes' => 'ملاحظة محفوظة على الخادم',
            'created_by' => User::where('username', 'college')->value('id'),
        ]);
        AssessmentResponse::create([
            'self_assessment_id' => $assessment->id,
            'indicator_id' => $cycle->standardsVersion->standards()->with('indicators')->firstOrFail()->indicators->firstOrFail()->id,
            'score' => 4,
        ]);

        $this->actingAs(User::where('username', 'college')->firstOrFail())
            ->get(route('platform.api.reports.download', 'college'))
            ->assertOk()
            ->assertHeader('content-type', 'application/msword; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename="college-quality-report.doc"')
            ->assertSee('ملاحظة محفوظة على الخادم');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reports.college_downloaded',
            'user_id' => User::where('username', 'college')->value('id'),
        ]);
    }

    public function test_other_roles_cannot_download_college_report(): void
    {
        $this->actingAs(User::where('username', 'central')->firstOrFail())
            ->get(route('platform.api.reports.download', 'college'))
            ->assertForbidden();
    }

    public function test_evaluator_downloads_external_report_from_server_data(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $evaluator = User::where('username', 'evaluator')->firstOrFail();
        $university = University::create(['name_ar' => 'جامعة تقرير خارجي', 'code' => 'ER', 'type' => 'public', 'active' => true]);
        $college = $university->colleges()->create(['name_ar' => 'كلية تقرير خارجي', 'code' => 'EC', 'active' => true]);
        $cycle = EvaluationCycle::create([
            'name' => 'دورة التقرير الخارجي',
            'code' => 'EXTERNAL-DOWNLOAD-2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'status' => 'open',
            'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'),
            'created_by' => $central->id,
        ]);
        $cycle->universities()->attach($university->id);
        $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);
        $report = UniversityReport::create([
            'evaluation_cycle_id' => $cycle->id,
            'university_id' => $university->id,
            'status' => 'approved',
            'overall_score' => 80,
            'college_count' => 1,
            'standards_snapshot' => [],
            'prepared_by' => $central->id,
        ]);
        $team = EvaluationTeam::create([
            'evaluation_cycle_id' => $cycle->id,
            'university_id' => $university->id,
            'name' => 'فريق التقرير الخارجي',
            'created_by' => $central->id,
        ]);
        $team->members()->attach($evaluator->id);
        $evaluation = ExternalEvaluation::create([
            'university_report_id' => $report->id,
            'evaluation_team_id' => $team->id,
            'evaluator_id' => $evaluator->id,
            'status' => 'submitted',
            'total_score' => 88,
            'assigned_by' => $central->id,
            'assigned_at' => now(),
            'strengths' => 'قوة محفوظة على الخادم',
        ]);
        ExternalEvaluationResponse::create([
            'external_evaluation_id' => $evaluation->id,
            'college_id' => $college->id,
            'indicator_id' => $cycle->standardsVersion->standards()->with('indicators')->firstOrFail()->indicators->firstOrFail()->id,
            'score' => 4,
            'notes' => 'ملاحظة من قاعدة البيانات',
        ]);

        $this->actingAs($evaluator)
            ->get(route('platform.api.reports.download', 'external'))
            ->assertOk()
            ->assertHeader('content-type', 'application/msword; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename="external-evaluation-report.doc"')
            ->assertSee('قوة محفوظة على الخادم')
            ->assertSee('ملاحظة من قاعدة البيانات');
    }

    public function test_central_downloads_university_summary_as_csv_from_server_data(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $university = University::where('code', 'SU')->firstOrFail();
        $college = College::where('code', 'ENG')->firstOrFail();
        $cycle = EvaluationCycle::create([
            'name' => 'دورة ملخص الجودة',
            'code' => 'SUMMARY-DOWNLOAD-2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'status' => 'open',
            'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'),
            'created_by' => $central->id,
        ]);
        $cycle->universities()->attach($university->id);
        $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);
        SelfAssessment::create([
            'evaluation_cycle_id' => $cycle->id,
            'university_id' => $university->id,
            'college_id' => $college->id,
            'status' => 'approved',
            'total_score' => 87.5,
            'created_by' => User::where('username', 'college')->value('id'),
        ]);
        UniversityReport::create([
            'evaluation_cycle_id' => $cycle->id,
            'university_id' => $university->id,
            'status' => 'approved',
            'overall_score' => 87.5,
            'college_count' => 1,
            'standards_snapshot' => [],
            'prepared_by' => $central->id,
        ]);

        $this->actingAs($central)
            ->get(route('platform.api.reports.summary-download', [
                'format' => 'csv',
                'cycle_id' => $cycle->id,
                'university_id' => $university->id,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename="quality-summary.csv"')
            ->assertSee('87.5');
    }
}
