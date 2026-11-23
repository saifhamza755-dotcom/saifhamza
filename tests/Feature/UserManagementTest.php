<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\University;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_can_open_users_page(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('إدارة المستخدمين')
            ->assertSee('مسؤول جودة الكلية');
    }

    public function test_user_without_permission_cannot_open_users_page(): void
    {
        $this->actingAs(User::where('username', 'college')->firstOrFail())
            ->get(route('users.index'))
            ->assertForbidden();
    }

    public function test_admin_can_create_university_scoped_user(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $university = University::firstOrFail();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'مسؤول جودة جديد',
            'username' => 'new-university-manager',
            'email' => 'new-manager@example.test',
            'password' => 'StrongPass@123',
            'password_confirmation' => 'StrongPass@123',
            'role_key' => 'university',
            'university_id' => $university->id,
        ])->assertRedirect();

        $user = User::where('username', 'new-university-manager')->firstOrFail();
        $this->assertDatabaseHas('user_assignments', [
            'user_id' => $user->id,
            'role_id' => Role::where('key', 'university')->value('id'),
            'university_id' => $university->id,
        ]);
        $this->assertTrue($user->must_change_password);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('users.status', $admin), ['active' => 0])
            ->assertSessionHasErrors('active');

        $this->assertTrue($admin->fresh()->active);
    }
}
