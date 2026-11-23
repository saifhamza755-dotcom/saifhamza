<?php

namespace Tests\Feature;

use App\Models\EvaluationCycle;
use App\Models\ExternalEvaluation;
use App\Models\StandardsVersion;
use App\Models\University;
use App\Models\UniversityReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_central_assigns_evaluator_to_approved_report(): void
    {
        $report = $this->report();
        $evaluator = User::where('username', 'evaluator')->firstOrFail();

        $this->actingAs(User::where('username', 'central')->firstOrFail())
            ->post(route('external-evaluations.assign', $report), ['evaluator_id' => $evaluator->id])
            ->assertRedirect();

        $evaluation = ExternalEvaluation::firstOrFail();
        $this->assertSame($evaluator->id, $evaluation->evaluator_id);
        $this->assertCount(18, $evaluation->responses);
    }

    public function test_evaluator_scores_are_weighted_and_calculated(): void
    {
        $evaluation = $this->evaluation();
        $payload = [];
        foreach ($evaluation->responses as $response) {
            $payload[$response->indicator_id] = ['score' => 75, 'notes' => 'تحقق ميداني'];
        }

        $this->actingAs(User::where('username', 'evaluator')->firstOrFail())
            ->put(route('external-evaluations.save', $evaluation), ['responses' => $payload])->assertRedirect();

        $this->assertSame('75.00', $evaluation->fresh()->total_score);
    }

    public function test_external_evaluation_follows_submit_return_and_approve_workflow(): void
    {
        $evaluation = $this->evaluation();
        $evaluation->responses()->update(['score' => 85]);
        $evaluator = User::where('username', 'evaluator')->firstOrFail();
        $central = User::where('username', 'central')->firstOrFail();

        $this->actingAs($evaluator)->post(route('external-evaluations.submit', $evaluation))->assertRedirect();
        $this->actingAs($central)->post(route('external-evaluations.decide', $evaluation), [
            'decision' => 'returned', 'review_notes' => 'استكمال الملاحظات النوعية.',
        ])->assertRedirect();
        $this->assertSame('returned', $evaluation->fresh()->status);

        $this->actingAs($evaluator)->post(route('external-evaluations.submit', $evaluation))->assertRedirect();
        $this->actingAs($central)->post(route('external-evaluations.decide', $evaluation), ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $evaluation->fresh()->status);
    }

    public function test_unassigned_user_cannot_open_external_evaluation(): void
    {
        $evaluation = $this->evaluation();

        $this->actingAs(User::where('username', 'college')->firstOrFail())
            ->get(route('external-evaluations.show', $evaluation))->assertForbidden();
    }

    private function report(): UniversityReport
    {
        $version = StandardsVersion::where('status', 'active')->with('standards')->firstOrFail();
        $university = University::where('code', 'SU')->firstOrFail();
        $cycle = EvaluationCycle::create([
            'name' => 'دورة التقييم الخارجي', 'code' => 'EXTERNAL-1', 'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31', 'status' => 'closed', 'standards_version_id' => $version->id,
            'created_by' => User::where('username', 'central')->value('id'),
        ]);
        $snapshot = $version->standards->map(fn ($standard) => [
            'standard_id' => $standard->id, 'code' => $standard->code, 'name' => $standard->name_ar,
            'weight' => (float) $standard->weight, 'score' => 80,
        ])->all();

        return UniversityReport::create([
            'evaluation_cycle_id' => $cycle->id, 'university_id' => $university->id, 'status' => 'approved',
            'overall_score' => 80, 'college_count' => 3, 'standards_snapshot' => $snapshot,
            'executive_summary' => 'ملخص', 'recommendations' => 'توصيات',
            'prepared_by' => User::where('username', 'university')->value('id'),
        ]);
    }

    private function evaluation(): ExternalEvaluation
    {
        $report = $this->report();
        $this->actingAs(User::where('username', 'central')->firstOrFail())
            ->post(route('external-evaluations.assign', $report), ['evaluator_id' => User::where('username', 'evaluator')->value('id')]);

        return ExternalEvaluation::with('responses')->firstOrFail();
    }
}
