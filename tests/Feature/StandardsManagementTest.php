<?php

namespace Tests\Feature;

use App\Models\Standard;
use App\Models\StandardsVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_central_user_can_view_seeded_national_standards(): void
    {
        $this->actingAs(User::where('username', 'central')->firstOrFail())
            ->get(route('standards.index'))
            ->assertOk()
            ->assertSee('الإطار الوطني لضمان الجودة')
            ->assertSee('التخطيط الاستراتيجي');
    }

    public function test_university_user_cannot_manage_standards(): void
    {
        $this->actingAs(User::where('username', 'university')->firstOrFail())
            ->get(route('standards.index'))
            ->assertForbidden();
    }

    public function test_incomplete_version_cannot_be_activated(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $version = StandardsVersion::create([
            'name' => 'نسخة غير مكتملة', 'version' => '2.0', 'status' => 'draft', 'created_by' => $central->id,
        ]);
        $version->standards()->create([
            'code' => '1', 'name_ar' => 'معيار تجريبي', 'weight' => 90, 'sort_order' => 1, 'active' => true,
        ]);

        $this->actingAs($central)
            ->patch(route('standards.versions.activate', $version))
            ->assertSessionHasErrors('version');

        $this->assertDatabaseHas('standards_versions', ['id' => $version->id, 'status' => 'draft']);
    }

    public function test_complete_version_is_activated_and_previous_one_archived(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $version = StandardsVersion::create([
            'name' => 'نسخة مكتملة', 'version' => '2.0', 'status' => 'draft', 'created_by' => $central->id,
        ]);
        $standard = Standard::create([
            'standards_version_id' => $version->id, 'code' => '1', 'name_ar' => 'معيار شامل',
            'weight' => 100, 'sort_order' => 1, 'active' => true,
        ]);
        $standard->indicators()->create([
            'code' => '1.1', 'name_ar' => 'مؤشر شامل', 'weight' => 100,
            'evidence_required' => true, 'sort_order' => 1, 'active' => true,
        ]);

        $this->actingAs($central)
            ->patch(route('standards.versions.activate', $version))
            ->assertRedirect();

        $this->assertDatabaseHas('standards_versions', ['id' => $version->id, 'status' => 'active']);
        $this->assertDatabaseHas('standards_versions', ['version' => '1.0', 'status' => 'archived']);
    }
}
