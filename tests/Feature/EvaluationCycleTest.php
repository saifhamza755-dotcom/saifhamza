<?php

namespace Tests\Feature;

use App\Models\EvaluationCycle;
use App\Models\StandardsVersion;
use App\Models\University;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluationCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_central_user_creates_cycle_with_university_and_college_snapshot(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $version = StandardsVersion::where('status', 'active')->firstOrFail();
        $university = University::where('code', 'SU')->firstOrFail();

        $this->actingAs($central)->post(route('cycles.store'), [
            'name' => 'دورة التقييم 2027', 'code' => 'CYCLE-2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
            'standards_version_id' => $version->id, 'university_ids' => [$university->id],
        ])->assertRedirect();

        $cycle = EvaluationCycle::where('code', 'CYCLE-2027')->firstOrFail();
        $this->assertSame('draft', $cycle->status);
        $this->assertCount(1, $cycle->universities);
        $this->assertCount(3, $cycle->colleges);
    }

    public function test_cycle_cannot_use_draft_standards_version(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $draft = StandardsVersion::create([
            'name' => 'مسودة', 'version' => 'DRAFT', 'status' => 'draft', 'created_by' => $central->id,
        ]);

        $this->actingAs($central)->post(route('cycles.store'), [
            'name' => 'دورة مرفوضة', 'code' => 'INVALID',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
            'standards_version_id' => $draft->id,
            'university_ids' => [University::where('code', 'SU')->value('id')],
        ])->assertSessionHasErrors('standards_version_id');

        $this->assertDatabaseMissing('evaluation_cycles', ['code' => 'INVALID']);
    }

    public function test_cycle_follows_draft_open_closed_workflow(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $university = University::where('code', 'SU')->firstOrFail();
        $cycle = EvaluationCycle::create([
            'name' => 'دورة الحالات', 'code' => 'STATUS-1', 'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31', 'status' => 'draft',
            'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'),
            'created_by' => $central->id,
        ]);
        $cycle->universities()->attach($university->id);
        foreach ($university->colleges as $college) {
            $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);
        }

        $this->actingAs($central)->patch(route('cycles.status', $cycle), ['status' => 'open'])->assertRedirect();
        $this->assertDatabaseHas('evaluation_cycles', ['id' => $cycle->id, 'status' => 'open']);

        $this->patch(route('cycles.status', $cycle), ['status' => 'closed'])->assertRedirect();
        $this->assertDatabaseHas('evaluation_cycles', ['id' => $cycle->id, 'status' => 'closed']);

        $this->patch(route('cycles.status', $cycle), ['status' => 'open'])->assertSessionHasErrors('status');
    }
}
