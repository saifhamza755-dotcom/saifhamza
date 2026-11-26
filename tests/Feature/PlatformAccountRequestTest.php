<?php

namespace Tests\Feature;

use App\Models\AccountRequest;
use App\Models\College;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAccountRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_approve_request_and_create_scoped_user(): void
    {
        $this->seed();
        $admin = User::where('username', 'admin')->firstOrFail();
        $university = University::firstOrFail();
        $college = College::where('university_id', $university->id)->firstOrFail();
        $accountRequest = AccountRequest::create([
            'name' => 'مستخدم جديد',
            'email' => 'new.user@example.com',
            'role' => 'college',
            'note' => 'كلية الحاسوب',
        ]);

        $response = $this->actingAs($admin)->postJson(
            "/platform/api/account-requests/{$accountRequest->id}/approve",
            [
                'username' => 'new-user',
                'phone' => '777000111',
                'password' => 'Temporary@123',
                'universityId' => 'u'.$university->id,
                'collegeId' => 'c'.$college->id,
                'reviewNote' => 'تم التحقق من البيانات.',
            ]
        );

        $response->assertOk()->assertJsonPath('request.status', 'مقبول');
        $user = User::where('username', 'new-user')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertDatabaseHas('user_assignments', [
            'user_id' => $user->id,
            'university_id' => $university->id,
            'college_id' => $college->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('account_requests', [
            'id' => $accountRequest->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account_requests.approved',
            'target_id' => $accountRequest->id,
        ]);
    }

    public function test_admin_can_reject_request_with_reason(): void
    {
        $this->seed();
        $admin = User::where('username', 'admin')->firstOrFail();
        $accountRequest = AccountRequest::create([
            'name' => 'طلب مرفوض',
            'email' => 'rejected@example.com',
            'role' => 'evaluator',
            'note' => 'طلب تقييم خارجي',
        ]);

        $response = $this->actingAs($admin)->postJson(
            "/platform/api/account-requests/{$accountRequest->id}/reject",
            ['reviewNote' => 'بيانات التحقق غير مكتملة.']
        );

        $response->assertOk()->assertJsonPath('request.status', 'مرفوض');
        $this->assertDatabaseHas('account_requests', [
            'id' => $accountRequest->id,
            'status' => 'rejected',
            'review_note' => 'بيانات التحقق غير مكتملة.',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'rejected@example.com']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account_requests.rejected',
            'target_id' => $accountRequest->id,
        ]);
    }
}
