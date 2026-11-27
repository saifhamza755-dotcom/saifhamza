<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_user_sees_only_own_notifications_and_can_mark_all_read(): void
    {
        $college = User::where('username', 'college')->firstOrFail();
        $dean = User::where('username', 'dean')->firstOrFail();
        AppNotification::create(['user_id' => $college->id, 'title' => 'إشعار الكلية', 'body' => 'تفاصيل']);
        AppNotification::create(['user_id' => $dean->id, 'title' => 'إشعار العميد', 'body' => 'تفاصيل']);

        $this->actingAs($college)->get(route('notifications.index'))
            ->assertOk()->assertSee('إشعار الكلية')->assertDontSee('إشعار العميد');
        $this->patch(route('notifications.read-all'))->assertRedirect();

        $this->assertNotNull(AppNotification::where('user_id', $college->id)->first()->read_at);
        $this->assertNull(AppNotification::where('user_id', $dean->id)->first()->read_at);

        AppNotification::create(['user_id' => $college->id, 'title' => 'إشعار جديد', 'body' => 'للتدقيق']);
        $this->patchJson(route('platform.api.activity.notifications.read-all'))->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $college->id,
            'action' => 'notifications.marked_read',
        ]);
    }

    public function test_user_cannot_open_another_users_notification(): void
    {
        $notification = AppNotification::create([
            'user_id' => User::where('username', 'dean')->value('id'), 'title' => 'خاص', 'body' => 'تفاصيل',
        ]);

        $this->actingAs(User::where('username', 'college')->firstOrFail())
            ->get(route('notifications.read', $notification))->assertForbidden();
    }

    public function test_authorized_user_can_filter_audit_log_while_college_cannot_access_it(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        AuditLog::create(['user_id' => $central->id, 'action' => 'workflow.completed', 'ip_address' => '127.0.0.1']);

        $this->actingAs($central)->get(route('audit.index', ['action' => 'workflow']))
            ->assertOk()->assertSee('workflow.completed');
        $this->actingAs(User::where('username', 'college')->firstOrFail())
            ->get(route('audit.index'))->assertForbidden();
    }
}
