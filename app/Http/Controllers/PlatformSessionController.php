<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlatformSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'active' => true,
        ])) {
            return response()->json([
                'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة، أو الحساب معطل.',
            ], 422);
        }

        // Keep the page token valid for the SPA after rotating the session ID.
        $request->session()->regenerate(false);
        /** @var User $user */
        $user = $request->user();
        $assignment = $user->activeAssignments()->with('role')->first();

        if (! $assignment) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'لا يوجد تكليف فعال لهذا الحساب.',
            ], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'auth.login',
            'metadata' => ['interface' => 'original-spa'],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'csrfToken' => csrf_token(),
            'user' => [
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $assignment->role->key,
                'universityId' => $assignment->university_id ? 'u'.$assignment->university_id : '',
                'collegeId' => $assignment->college_id ? 'c'.$assignment->college_id : '',
                'mustChange' => $user->must_change_password,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        if ($request->user()) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'auth.logout',
                'metadata' => ['interface' => 'original-spa'],
                'ip_address' => $request->ip(),
            ]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    public function destroyPage(Request $request): RedirectResponse
    {
        if ($request->user()) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'auth.logout',
                'metadata' => ['interface' => 'original-spa-page'],
                'ip_address' => $request->ip(),
            ]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform');
    }
}
