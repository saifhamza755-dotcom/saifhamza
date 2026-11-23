<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\EvaluationCycle;
use App\Models\ExternalEvaluation;
use App\Models\ImprovementAction;
use App\Models\ImprovementPlan;
use App\Models\SelfAssessment;
use App\Models\StandardsVersion;
use App\Models\University;
use App\Models\UniversityReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImprovementPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_college_creates_plan_only_from_approved_self_assessment(): void
    {
        $assessment = $this->selfAssessment('approved');

        $this->actingAs(User::where('username', 'college')->firstOrFail())->post(route('improvement-plans.store'), [
            'source_type' => 'self_assessment', 'source_id' => $assessment->id, 'title' => 'خطة تحسين الكلية',
        ])->assertRedirect();

        $this->assertDatabaseHas('improvement_plans', [
            'source_type' => 'self_assessment', 'source_id' => $assessment->id,
            'college_id' => $assessment->college_id, 'status' => 'draft',
        ]);
    }

    public function test_plan_follows_action_activation_progress_and_completion_workflow(): void
    {
        $plan = $this->collegePlan();
        $standard = $plan->cycle->standardsVersion->standards()->firstOrFail();
        $user = User::where('username', 'college')->firstOrFail();

        $this->actingAs($user)->post(route('improvement-actions.store', $plan), [
            'standard_id' => $standard->id, 'gap_description' => 'انخفاض نتيجة المعيار',
            'action_description' => 'تنفيذ برنامج تطوير', 'responsible_party' => 'وحدة الجودة',
            'priority' => 'high', 'starts_on' => '2027-01-01', 'due_on' => '2027-06-30',
        ])->assertRedirect();
        $this->patch(route('improvement-plans.activate', $plan))->assertRedirect();
        $action = ImprovementAction::firstOrFail();
        $this->patch(route('improvement-actions.update', $action), ['progress' => 100, 'notes' => 'تم التنفيذ'])->assertRedirect();
        $this->patch(route('improvement-plans.complete', $plan))->assertRedirect();

        $this->assertSame('completed', $plan->fresh()->status);
        $this->assertSame('completed', $action->fresh()->status);
    }

    public function test_university_creates_plan_from_approved_external_evaluation(): void
    {
        $evaluation = $this->externalEvaluation();

        $this->actingAs(User::where('username', 'university')->firstOrFail())->post(route('improvement-plans.store'), [
            'source_type' => 'external_evaluation', 'source_id' => $evaluation->id, 'title' => 'خطة تحسين الجامعة',
        ])->assertRedirect();

        $this->assertDatabaseHas('improvement_plans', ['source_id' => $evaluation->id, 'college_id' => null]);
    }

    public function test_other_college_cannot_open_plan(): void
    {
        $plan = $this->collegePlan();
        $other = College::create(['university_id' => $plan->university_id, 'name_ar' => 'كلية أخرى', 'code' => 'OTH-PLAN', 'active' => true]);
        $user = User::where('username', 'college')->firstOrFail();
        $user->activeAssignments()->update(['college_id' => $other->id]);

        $this->actingAs($user)->get(route('improvement-plans.show', $plan))->assertForbidden();
    }

    private function cycle(): EvaluationCycle
    {
        return EvaluationCycle::create([
            'name' => 'دورة التحسين', 'code' => 'IMPROVE-1', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
            'status' => 'closed', 'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'),
            'created_by' => User::where('username', 'central')->value('id'),
        ]);
    }

    private function selfAssessment(string $status): SelfAssessment
    {
        $college = College::where('code', 'ENG')->firstOrFail();

        return SelfAssessment::create([
            'evaluation_cycle_id' => $this->cycle()->id, 'university_id' => $college->university_id,
            'college_id' => $college->id, 'status' => $status, 'total_score' => 70,
            'created_by' => User::where('username', 'college')->value('id'),
        ]);
    }

    private function collegePlan(): ImprovementPlan
    {
        $assessment = $this->selfAssessment('approved');
        $this->actingAs(User::where('username', 'college')->firstOrFail())->post(route('improvement-plans.store'), [
            'source_type' => 'self_assessment', 'source_id' => $assessment->id, 'title' => 'خطة الكلية',
        ]);

        return ImprovementPlan::with('cycle.standardsVersion')->firstOrFail();
    }

    private function externalEvaluation(): ExternalEvaluation
    {
        $cycle = $this->cycle();
        $university = University::where('code', 'SU')->firstOrFail();
        $report = UniversityReport::create([
            'evaluation_cycle_id' => $cycle->id, 'university_id' => $university->id, 'status' => 'approved',
            'overall_score' => 75, 'college_count' => 3, 'standards_snapshot' => [],
            'prepared_by' => User::where('username', 'university')->value('id'),
        ]);

        return ExternalEvaluation::create([
            'university_report_id' => $report->id, 'evaluator_id' => User::where('username', 'evaluator')->value('id'),
            'status' => 'approved', 'total_score' => 72, 'assigned_by' => User::where('username', 'central')->value('id'),
            'assigned_at' => now(),
        ]);
    }
}
