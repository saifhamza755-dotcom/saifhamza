<?php

namespace Tests\Feature;

use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformPasswordResetRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_submit_password_reset_request(): void
    {
        $this->seed();

        $this->postJson('/platform/api/password-reset-requests', [
            'account' => 'college',
        ])->assertCreated();

        $this->assertDatabaseHas('password_reset_requests', [
            'account' => 'college',
            'status' => 'pending',
        ]);
    }

    public function test_admin_processes_request_with_temporary_password(): void
    {
        $this->seed();
        $admin = User::where('username', 'admin')->firstOrFail();
        $user = User::where('username', 'college')->firstOrFail();
        $item = PasswordResetRequest::create([
            'account' => $user->email,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->postJson(
            "/platform/api/password-reset-requests/{$item->id}/process",
            [
                'password' => 'Temporary@456',
                'reviewNote' => 'تم التحقق من هوية صاحب الحساب.',
            ]
        );

        $response->assertOk()->assertJsonPath('request.status', 'تمت المعالجة');
        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('Temporary@456', $user->password));
        $this->assertDatabaseHas('password_reset_requests', [
            'id' => $item->id,
            'status' => 'processed',
            'user_id' => $user->id,
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_reset_requests.processed',
            'target_id' => $item->id,
        ]);
    }

    public function test_admin_rejects_request_with_reason(): void
    {
        $this->seed();
        $admin = User::where('username', 'admin')->firstOrFail();
        $item = PasswordResetRequest::create([
            'account' => 'unknown@example.com',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->postJson(
            "/platform/api/password-reset-requests/{$item->id}/reject",
            ['reviewNote' => 'تعذر التحقق من صاحب الطلب.']
        )->assertOk()->assertJsonPath('request.status', 'مرفوض');

        $this->assertDatabaseHas('password_reset_requests', [
            'id' => $item->id,
            'status' => 'rejected',
            'review_note' => 'تعذر التحقق من صاحب الطلب.',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_reset_requests.rejected',
            'target_id' => $item->id,
        ]);
    }
}
