<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\College;
use App\Models\Role;
use App\Models\University;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::with(['activeAssignments.role', 'activeAssignments.university', 'activeAssignments.college'])
                ->orderBy('name')->paginate(15),
            'roles' => Role::orderBy('id')->get(),
            'universities' => University::with('colleges')->where('active', true)->orderBy('name_ar')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_key' => ['required', Rule::exists('roles', 'key')],
            'university_id' => ['nullable', 'integer', Rule::exists('universities', 'id')],
            'college_id' => ['nullable', 'integer', Rule::exists('colleges', 'id')],
        ]);

        $role = Role::where('key', $data['role_key'])->firstOrFail();
        $universityId = $data['university_id'] ?? null;
        $collegeId = $data['college_id'] ?? null;

        if ($role->scope === 'university' && ! $universityId) {
            throw ValidationException::withMessages(['university_id' => 'يجب تحديد جامعة لهذا الدور.']);
        }
        if ($role->scope === 'college' && (! $universityId || ! $collegeId)) {
            throw ValidationException::withMessages(['college_id' => 'يجب تحديد الجامعة والكلية لهذا الدور.']);
        }
        if ($collegeId && ! College::whereKey($collegeId)->where('university_id', $universityId)->exists()) {
            throw ValidationException::withMessages(['college_id' => 'الكلية لا تتبع الجامعة المحددة.']);
        }
        if (in_array($role->scope, ['platform', 'assignment'], true)) {
            $universityId = null;
            $collegeId = null;
        } elseif ($role->scope === 'university') {
            $collegeId = null;
        }

        $user = DB::transaction(function () use ($data, $role, $universityId, $collegeId, $request) {
            $user = User::create([
                'name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'],
                'phone' => $data['phone'] ?? null, 'password' => $data['password'],
                'active' => true, 'must_change_password' => true,
            ]);
            $user->assignments()->create([
                'role_id' => $role->id, 'university_id' => $universityId, 'college_id' => $collegeId,
                'active' => true, 'assigned_at' => now(),
            ]);
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'users.created',
                'target_type' => User::class, 'target_id' => $user->id,
                'metadata' => ['role' => $role->key], 'ip_address' => $request->ip(),
            ]);

            return $user;
        });

        return back()->with('success', "تم إنشاء حساب {$user->name} بنجاح.");
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        if ($request->user()->is($user) && ! $data['active']) {
            return back()->withErrors(['active' => 'لا يمكنك إيقاف حسابك الحالي.']);
        }

        $user->update(['active' => $data['active']]);
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $data['active'] ? 'users.activated' : 'users.deactivated',
            'target_type' => User::class, 'target_id' => $user->id, 'ip_address' => $request->ip(),
        ]);

        return back()->with('success', 'تم تحديث حالة الحساب.');
    }
}
