<?php

namespace Tests\Feature;

use App\Models\StandardsVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformQualityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_original_interface_loads_standards_from_database(): void
    {
        $college = User::where('username', 'college')->firstOrFail();

        $this->actingAs($college)
            ->getJson(route('platform.api.quality.index'))
            ->assertOk()
            ->assertJsonPath('versions.0.name', 'الإطار الوطني لضمان الجودة')
            ->assertJsonCount(9, 'versions.0.standards');
    }

    public function test_central_copies_edits_and_activates_standards_version(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $source = StandardsVersion::where('status', 'active')->firstOrFail();

        $created = $this->actingAs($central)->postJson(route('platform.api.quality.versions.store'), [
            'name' => 'الإطار الوطني المحدث', 'version' => '2.0', 'source' => 'v'.$source->id,
        ])->assertCreated()->assertJsonPath('version.status', 'draft');

        $versionId = (int) substr($created->json('version.id'), 1);
        $standard = StandardsVersion::findOrFail($versionId)->standards()->firstOrFail();

        $this->putJson(route('platform.api.quality.standards.update', $standard), [
            'code' => $standard->code, 'name_ar' => 'التخطيط الاستراتيجي المحدث',
            'weight' => $standard->weight, 'sort_order' => $standard->sort_order,
        ])->assertOk();

        $this->patchJson(route('platform.api.quality.versions.activate', $versionId))
            ->assertOk();

        $this->assertDatabaseHas('standards_versions', ['id' => $versionId, 'status' => 'active']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'standards.version.activated']);
    }

    public function test_central_creates_opens_and_closes_cycle_from_original_interface(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $version = StandardsVersion::where('status', 'active')->firstOrFail();

        $created = $this->actingAs($central)->postJson(route('platform.api.quality.cycles.store'), [
            'name' => 'دورة الاعتماد 2027', 'code' => 'QA-2027',
            'start' => '2027-01-01', 'end' => '2027-12-31',
            'versionId' => 'v'.$version->id, 'universityIds' => ['u1'],
        ])->assertCreated()
            ->assertJsonPath('cycle.status', 'مسودة')
            ->assertJsonPath('cycle.universityIds.0', 'u1');

        $cycleId = (int) substr($created->json('cycle.id'), 2);
        $this->patchJson(route('platform.api.quality.cycles.status', $cycleId), ['status' => 'open'])
            ->assertOk();
        $this->patchJson(route('platform.api.quality.cycles.status', $cycleId), ['status' => 'closed'])
            ->assertOk();

        $this->assertDatabaseHas('evaluation_cycles', ['id' => $cycleId, 'status' => 'closed']);
        $this->assertDatabaseCount('evaluation_cycle_college', 3);
    }

    public function test_college_cannot_modify_standards_or_cycles(): void
    {
        $college = User::where('username', 'college')->firstOrFail();

        $this->actingAs($college)
            ->postJson(route('platform.api.quality.versions.store'), [])
            ->assertForbidden();
        $this->postJson(route('platform.api.quality.cycles.store'), [])
            ->assertForbidden();
    }
}
