<?php

namespace Tests\Feature;

use App\Models\User;
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
}
