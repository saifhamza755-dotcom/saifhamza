<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $user->update($data);
        $this->audit($request, 'profile.updated', $user);

        return response()->json([
            'user' => $this->userData($user->fresh()),
            'message' => 'تم تحديث الملف الشخصي.',
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->update([
            'password' => $data['password'],
            'must_change_password' => false,
        ]);
        $this->audit($request, 'profile.password_changed', $user);

        return response()->json([
            'user' => $this->userData($user->fresh()),
            'message' => 'تم تغيير كلمة المرور.',
        ]);
    }

    public function selectAssignment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'assignmentId' => ['required', 'integer'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $assignment = $user->activeAssignments()->whereKey($data['assignmentId'])->firstOrFail();

        $request->session()->put('active_assignment_id', $assignment->id);
        $this->audit($request, 'profile.assignment_selected', $user);

        return response()->json([
            'user' => $this->userData($user->fresh()),
            'message' => 'تم تغيير التكليف الفعّال.',
        ]);
    }

    private function userData(User $user): array
    {
        $assignments = $user->activeAssignments()->with(['role', 'university', 'college'])->get();
        $assignment = $assignments->firstOrFail();

        return [
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $assignment->role->key,
            'universityId' => $assignment->university_id ? 'u'.$assignment->university_id : '',
            'collegeId' => $assignment->college_id ? 'c'.$assignment->college_id : '',
            'mustChange' => $user->must_change_password,
            'activeAssignmentId' => $assignment->id,
            'assignments' => $assignments->map(fn ($item) => [
                'id' => $item->id,
                'role' => $item->role->key,
                'roleName' => $item->role->name_ar,
                'universityId' => $item->university_id ? 'u'.$item->university_id : '',
                'universityName' => $item->university?->name_ar,
                'collegeId' => $item->college_id ? 'c'.$item->college_id : '',
                'collegeName' => $item->college?->name_ar,
            ])->values(),
        ];
    }

    private function audit(Request $request, string $action, User $user): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'target_type' => User::class,
            'target_id' => $user->id,
            'ip_address' => $request->ip(),
        ]);
    }
}
