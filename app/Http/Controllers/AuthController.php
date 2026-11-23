<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $field = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $remember = $request->boolean('remember');

        if (! Auth::attempt([
            $field => $credentials['login'],
            'password' => $credentials['password'],
            'active' => true,
        ], $remember)) {
            return back()
                ->withErrors(['login' => 'اسم المستخدم أو كلمة المرور غير صحيحة، أو الحساب موقوف.'])
                ->onlyInput('login');
        }

        $request->session()->regenerate();
        /** @var User $user */
        $user = $request->user();

        if (! $user->activeAssignments()->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withErrors(['login' => 'لا يوجد تكليف فعال لهذا الحساب. راجع مسؤول النظام.'])
                ->onlyInput('login');
        }

        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'auth.login',
            'metadata' => ['login_field' => $field],
            'ip_address' => $request->ip(),
        ]);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($request->user()) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'auth.logout',
                'ip_address' => $request->ip(),
            ]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
