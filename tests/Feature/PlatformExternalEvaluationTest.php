<?php

namespace Tests\Feature;

use App\Models\EvaluationCycle;
use App\Models\EvaluationTeam;
use App\Models\EvaluationVisit;
use App\Models\ExternalEvaluation;
use App\Models\StandardsVersion;
use App\Models\University;
use App\Models\UniversityReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformExternalEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_platform_external_evaluation_requires_completed_visit_and_saves_college_scores(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $evaluator = User::where('username', 'evaluator')->firstOrFail();
        $university = University::create(['name_ar' => 'جامعة الفحص', 'code' => 'PX', 'type' => 'public', 'active' => true]);
        $college = $university->colleges()->create(['name_ar' => 'كلية الفحص', 'code' => 'PC', 'active' => true]);
        $cycle = EvaluationCycle::create([
            'name' => 'دورة التقييم الخارجي', 'code' => 'PEX-2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
            'status' => 'open', 'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'), 'created_by' => $central->id,
        ]);
        $cycle->universities()->attach($university->id);
        $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);
        $report = UniversityReport::create([
            'evaluation_cycle_id' => $cycle->id, 'university_id' => $university->id, 'status' => 'approved',
            'overall_score' => 80, 'college_count' => 1, 'standards_snapshot' => [], 'prepared_by' => $central->id,
        ]);
        $team = EvaluationTeam::create(['evaluation_cycle_id' => $cycle->id, 'university_id' => $university->id, 'name' => 'فريق الفحص', 'created_by' => $central->id]);
        $team->members()->attach($evaluator->id);

        $this->actingAs($central)->postJson(route('platform.api.external-evaluations.assign', $report), ['teamId' => 't'.$team->id])->assertUnprocessable();

        EvaluationVisit::create(['evaluation_team_id' => $team->id, 'visit_date' => '2027-06-10', 'visit_time' => '09:00', 'agenda' => 'زيارة ميدانية', 'results' => 'تم التحقق من الأدلة', 'status' => 'completed', 'updated_by' => $central->id]);
        $assigned = $this->postJson(route('platform.api.external-evaluations.assign', $report), ['teamId' => 't'.$team->id])->assertCreated();
        $evaluation = ExternalEvaluation::findOrFail($assigned->json('evaluation.evaluationId'));
        $indicatorId = $cycle->standardsVersion->standards()->with('indicators')->firstOrFail()->indicators->firstOrFail()->id;

        $this->actingAs($evaluator)->putJson(route('platform.api.external-evaluations.scores', $evaluation), [
            'collegeId' => 'c'.$college->id, 'scores' => [$indicatorId => 4], 'comments' => [$indicatorId => 'دليل مكتمل'],
        ])->assertOk()->assertJsonPath('evaluation.scores.c'.$college->id.'|'.$indicatorId, 4);

        $this->assertDatabaseHas('external_evaluation_responses', ['external_evaluation_id' => $evaluation->id, 'college_id' => $college->id, 'indicator_id' => $indicatorId, 'score' => 4]);
    }
}
