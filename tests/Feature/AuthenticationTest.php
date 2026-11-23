<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_login_page_is_available_in_arabic(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('تسجيل الدخول')
            ->assertSee('منصة اليمن للجودة والاعتماد');
    }

    public function test_active_user_with_assignment_can_log_in(): void
    {
        $this->post(route('login.store'), [
            'login' => 'admin',
            'password' => 'Demo@1234',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::where('username', 'admin')->update(['active' => false]);

        $this->from(route('login'))->post(route('login.store'), [
            'login' => 'admin',
            'password' => 'Demo@1234',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_college_user_sees_only_assigned_scope_on_dashboard(): void
    {
        $user = User::where('username', 'college')->firstOrFail();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('جامعة صنعاء')
            ->assertSee('كلية الهندسة')
            ->assertSee('مسؤول جودة الكلية');
    }

    public function test_guest_cannot_open_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_original_interface_uses_real_laravel_session(): void
    {
        $this->postJson(route('platform.session.store'), [
            'username' => 'college',
            'password' => 'Demo@1234',
        ])->assertOk()
            ->assertJsonPath('user.username', 'college')
            ->assertJsonPath('user.role', 'college');

        $this->assertAuthenticated();

        $this->get(route('platform'))
            ->assertOk()
            ->assertSee('"username":"college"', false);
    }

    public function test_original_interface_can_log_out_of_laravel(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($user)
            ->deleteJson(route('platform.session.destroy'))
            ->assertOk()
            ->assertJsonPath('message', 'تم تسجيل الخروج.');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout']);
    }
}
