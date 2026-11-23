<?php

namespace Tests\Feature;

use App\Models\AccreditationDecision;
use App\Models\EvaluationCycle;
use App\Models\ExternalEvaluation;
use App\Models\StandardsVersion;
use App\Models\University;
use App\Models\UniversityReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccreditationDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_central_issues_accreditation_decision_from_approved_evaluation(): void
    {
        $evaluation = $this->evaluation();

        $this->actingAs(User::where('username', 'central')->firstOrFail())->post(route('accreditation.store', $evaluation), [
            'decision_type' => 'accredited', 'valid_from' => '2027-01-01', 'valid_until' => '2030-01-01',
            'rationale' => 'استيفاء متطلبات الاعتماد.',
        ])->assertRedirect();

        $decision = AccreditationDecision::firstOrFail();
        $this->assertSame('78.00', $decision->final_score);
        $this->assertStringStartsWith('QA-', $decision->decision_number);
        $this->assertSame('active', $decision->displayStatus());
    }

    public function test_conditional_decision_requires_conditions_and_validity_dates(): void
    {
        $evaluation = $this->evaluation();

        $this->actingAs(User::where('username', 'central')->firstOrFail())->post(route('accreditation.store', $evaluation), [
            'decision_type' => 'conditional', 'rationale' => 'توجد متطلبات إضافية.',
        ])->assertSessionHasErrors('valid_from');

        $this->assertDatabaseCount('accreditation_decisions', 0);
    }

    public function test_decision_is_unique_per_evaluation_and_can_be_revoked_with_reason(): void
    {
        $evaluation = $this->evaluation();
        $central = User::where('username', 'central')->firstOrFail();
        $payload = ['decision_type' => 'not_accredited', 'rationale' => 'عدم استيفاء المتطلبات.'];
        $this->actingAs($central)->post(route('accreditation.store', $evaluation), $payload)->assertRedirect();
        $decision = AccreditationDecision::firstOrFail();

        $this->post(route('accreditation.store', $evaluation), $payload)->assertStatus(409);
        $this->patch(route('accreditation.revoke', $decision), ['revocation_reason' => 'صدور قرار بديل.'])->assertRedirect();

        $this->assertSame('revoked', $decision->fresh()->displayStatus());
        $this->assertSame('صدور قرار بديل.', $decision->fresh()->revocation_reason);
    }

    public function test_non_central_user_cannot_access_accreditation_decisions(): void
    {
        $this->actingAs(User::where('username', 'university')->firstOrFail())
            ->get(route('accreditation.index'))->assertForbidden();
    }

    private function evaluation(): ExternalEvaluation
    {
        $version = StandardsVersion::where('status', 'active')->with('standards.indicators')->firstOrFail();
        $cycle = EvaluationCycle::create([
            'name' => 'دورة قرار الاعتماد', 'code' => 'ACCREDIT-1', 'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31', 'status' => 'closed', 'standards_version_id' => $version->id,
            'created_by' => User::where('username', 'central')->value('id'),
        ]);
        $snapshot = $version->standards->map(fn ($standard) => [
            'standard_id' => $standard->id, 'code' => $standard->code, 'name' => $standard->name_ar,
            'weight' => (float) $standard->weight, 'score' => 80,
        ])->all();
        $report = UniversityReport::create([
            'evaluation_cycle_id' => $cycle->id, 'university_id' => University::where('code', 'SU')->value('id'),
            'status' => 'approved', 'overall_score' => 80, 'college_count' => 3, 'standards_snapshot' => $snapshot,
            'prepared_by' => User::where('username', 'university')->value('id'),
        ]);
        $evaluation = ExternalEvaluation::create([
            'university_report_id' => $report->id, 'evaluator_id' => User::where('username', 'evaluator')->value('id'),
            'status' => 'approved', 'total_score' => 78, 'assigned_by' => User::where('username', 'central')->value('id'),
            'assigned_at' => now(),
        ]);
        foreach ($version->standards->flatMap->indicators as $indicator) {
            $evaluation->responses()->create(['indicator_id' => $indicator->id, 'score' => 78]);
        }

        return $evaluation;
    }
}
