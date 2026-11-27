<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_updates_profile_in_database(): void
    {
        $this->seed();
        $user = User::where('username', 'college')->firstOrFail();

        $response = $this->actingAs($user)->putJson('/platform/api/profile', [
            'name' => 'مسؤول جودة الكلية',
            'email' => 'college.updated@example.com',
            'phone' => '777123456',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.name', 'مسؤول جودة الكلية')
            ->assertJsonPath('user.email', 'college.updated@example.com');
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'مسؤول جودة الكلية',
            'email' => 'college.updated@example.com',
            'phone' => '777123456',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'profile.updated',
            'target_id' => $user->id,
        ]);
    }

    public function test_user_changes_temporary_password_with_current_password(): void
    {
        $this->seed();
        $user = User::where('username', 'college')->firstOrFail();
        $user->update(['must_change_password' => true]);

        $response = $this->actingAs($user)->putJson('/platform/api/profile/password', [
            'current_password' => 'Demo@1234',
            'password' => 'NewSecure@123',
            'password_confirmation' => 'NewSecure@123',
        ]);

        $response->assertOk()->assertJsonPath('user.mustChange', false);
        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('NewSecure@123', $user->password));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'profile.password_changed',
            'target_id' => $user->id,
        ]);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $this->seed();
        $user = User::where('username', 'college')->firstOrFail();

        $this->actingAs($user)->putJson('/platform/api/profile/password', [
            'current_password' => 'WrongPassword',
            'password' => 'NewSecure@123',
            'password_confirmation' => 'NewSecure@123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('Demo@1234', $user->fresh()->password));
    }

    public function test_user_selects_an_owned_active_assignment(): void
    {
        $this->seed();
        $user = User::where('username', 'college')->firstOrFail();
        $collegeAssignment = $user->activeAssignments()->firstOrFail();
        $centralAssignment = UserAssignment::create([
            'user_id' => $user->id,
            'role_id' => Role::where('key', 'central')->value('id'),
            'active' => true,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_assignment_id' => $collegeAssignment->id])
            ->putJson('/platform/api/profile/assignment', [
                'assignmentId' => $centralAssignment->id,
            ]);

        $response->assertOk()
            ->assertSessionHas('active_assignment_id', $centralAssignment->id)
            ->assertJsonPath('user.role', 'central')
            ->assertJsonPath('user.activeAssignmentId', $centralAssignment->id)
            ->assertJsonCount(2, 'user.assignments');
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'profile.assignment_selected',
        ]);
    }

    public function test_user_cannot_select_another_users_assignment(): void
    {
        $this->seed();
        $user = User::where('username', 'college')->firstOrFail();
        $otherAssignment = User::where('username', 'central')
            ->firstOrFail()
            ->activeAssignments()
            ->firstOrFail();

        $this->actingAs($user)->putJson('/platform/api/profile/assignment', [
            'assignmentId' => $otherAssignment->id,
        ])->assertNotFound();
    }

    public function test_selected_assignment_controls_role_permissions(): void
    {
        $this->seed();
        $user = User::where('username', 'college')->firstOrFail();
        $collegeAssignment = $user->activeAssignments()->firstOrFail();
        $centralAssignment = UserAssignment::create([
            'user_id' => $user->id,
            'role_id' => Role::where('key', 'central')->value('id'),
            'active' => true,
            'assigned_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['active_assignment_id' => $collegeAssignment->id])
            ->postJson('/platform/api/quality/versions', [])
            ->assertForbidden();

        $this->putJson('/platform/api/profile/assignment', [
            'assignmentId' => $centralAssignment->id,
        ])->assertOk();

        $this->postJson('/platform/api/quality/versions', [])
            ->assertUnprocessable();
    }
}
