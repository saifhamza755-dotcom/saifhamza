<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_loads_real_users_and_institutions_for_original_interface(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)
            ->getJson(route('platform.api.directory'))
            ->assertOk()
            ->assertJsonPath('users.0.username', 'admin')
            ->assertJsonFragment(['id' => 'u1', 'name' => 'جامعة صنعاء'])
            ->assertJsonFragment(['id' => 'c1', 'name' => 'كلية الهندسة']);
    }

    public function test_admin_creates_and_updates_user_from_original_interface(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $created = $this->actingAs($admin)->postJson(route('platform.api.users.store'), [
            'name' => 'مسؤول جودة جديد', 'username' => 'quality.new',
            'email' => 'quality.new@example.test', 'phone' => '777000000',
            'password' => 'Temporary@123', 'role' => 'college',
            'universityId' => 'u1', 'collegeId' => 'c1',
        ])->assertCreated()->assertJsonPath('user.role', 'college');

        $userId = $created->json('user.id');
        $this->putJson(route('platform.api.users.update', $userId), [
            'name' => 'مسؤول جودة محدث', 'username' => 'quality.new',
            'email' => 'quality.new@example.test', 'phone' => '777000001',
            'role' => 'university', 'universityId' => 'u1', 'collegeId' => '',
        ])->assertOk()->assertJsonPath('user.role', 'university');

        $this->patchJson(route('platform.api.users.status', $userId), ['active' => false])
            ->assertOk()->assertJsonPath('user.active', false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'users.updated']);
    }

    public function test_central_creates_university_and_university_manager_cannot(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $payload = [
            'type' => 'جامعة', 'name' => 'جامعة اختبار', 'code' => 'TEST-U',
            'university_type' => 'public', 'city' => 'صنعاء',
        ];

        $this->actingAs($central)
            ->postJson(route('platform.api.institutions.store'), $payload)
            ->assertCreated();

        $this->assertDatabaseHas('universities', ['code' => 'TEST-U', 'name_ar' => 'جامعة اختبار']);

        $universityManager = User::where('username', 'university')->firstOrFail();
        $this->actingAs($universityManager)
            ->postJson(route('platform.api.institutions.store'), array_merge($payload, ['code' => 'DENIED-U']))
            ->assertForbidden();
    }

    public function test_user_without_permission_cannot_manage_platform_users(): void
    {
        $college = User::where('username', 'college')->firstOrFail();

        $this->actingAs($college)
            ->postJson(route('platform.api.users.store'), [])
            ->assertForbidden();
    }
}
