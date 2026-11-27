<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAssignment;
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

    public function test_admin_adds_and_disables_an_additional_assignment(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $user = User::where('username', 'college')->firstOrFail();

        $created = $this->actingAs($admin)->postJson(route('platform.api.users.assignments.store', $user), [
            'role' => 'university',
            'universityId' => 'u1',
            'collegeId' => '',
        ])->assertCreated()
            ->assertJsonCount(2, 'user.assignments')
            ->assertJsonFragment(['role' => 'university', 'active' => true]);

        $assignmentId = collect($created->json('user.assignments'))
            ->firstWhere('role', 'university')['id'];

        $this->patchJson(route('platform.api.users.assignments.status', [$user, $assignmentId]), [
            'active' => false,
        ])->assertOk()->assertJsonFragment([
            'id' => $assignmentId,
            'active' => false,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'users.assignment_created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.assignment_deactivated']);
    }

    public function test_last_active_assignment_cannot_be_disabled(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $user = User::where('username', 'college')->firstOrFail();
        $assignment = $user->activeAssignments()->firstOrFail();

        $this->actingAs($admin)
            ->patchJson(route('platform.api.users.assignments.status', [$user, $assignment]), [
                'active' => false,
            ])->assertUnprocessable()->assertJsonValidationErrors('active');

        $this->assertTrue($assignment->fresh()->active);
    }

    public function test_editing_user_preserves_other_assignments(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $user = User::where('username', 'college')->firstOrFail();
        $extra = UserAssignment::create([
            'user_id' => $user->id,
            'role_id' => Role::where('key', 'university')->value('id'),
            'university_id' => 1,
            'active' => true,
            'assigned_at' => now(),
        ]);

        $this->actingAs($admin)->putJson(route('platform.api.users.update', $user), [
            'name' => 'اسم المستخدم المحدث',
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => 'college',
            'universityId' => 'u1',
            'collegeId' => 'c1',
        ])->assertOk();

        $this->assertTrue($extra->fresh()->active);
        $this->assertSame(2, $user->activeAssignments()->count());
    }

    public function test_authorized_users_can_update_institution_name_and_code(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $universityManager = User::where('username', 'university')->firstOrFail();

        $this->actingAs($central)
            ->putJson(route('platform.api.institutions.update', ['type' => 'u', 'id' => 1]), [
                'name' => 'جامعة صنعاء الجديدة',
                'code' => 'SANA-NEW',
            ])
            ->assertOk();

        $this->actingAs($universityManager)
            ->putJson(route('platform.api.institutions.update', ['type' => 'c', 'id' => 1]), [
                'name' => 'كلية الهندسة والتقنية',
                'code' => 'ENG-TECH',
            ])
            ->assertOk();

        $this->assertDatabaseHas('universities', ['id' => 1, 'name_ar' => 'جامعة صنعاء الجديدة', 'code' => 'SANA-NEW']);
        $this->assertDatabaseHas('colleges', ['id' => 1, 'name_ar' => 'كلية الهندسة والتقنية', 'code' => 'ENG-TECH']);
    }

    public function test_duplicate_institution_code_is_rejected_within_its_scope(): void
    {
        $universityManager = User::where('username', 'university')->firstOrFail();
        $existingCode = College::findOrFail(2)->code;

        $this->actingAs($universityManager)
            ->putJson(route('platform.api.institutions.update', ['type' => 'c', 'id' => 1]), [
                'name' => 'كلية الهندسة',
                'code' => $existingCode,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }
}
