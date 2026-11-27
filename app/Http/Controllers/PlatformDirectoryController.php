<?php

namespace App\Http\Controllers;

use App\Models\AcademicProgram;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Department;
use App\Models\Role;
use App\Models\University;
use App\Models\User;
use App\Models\UserAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        $universities = University::with(['colleges.departments.programs'])->orderBy('name_ar');

        if ($assignment->role->key === 'university') {
            $universities->whereKey($assignment->university_id);
        }

        $users = null;
        if ($request->user()->hasPermission('users.manage')) {
            $users = User::with(['assignments.role', 'assignments.university', 'assignments.college'])
                ->orderBy('id')
                ->get()
                ->map(fn (User $user) => $this->userData(
                    $user,
                    $request->user()->is($user) ? $request->session()->get('active_assignment_id') : null
                ));
        }

        return response()->json([
            'users' => $users,
            'institutions' => $universities->get()->flatMap(fn (University $university) => $this->institutionData($university))->values(),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $data = $this->validateUser($request);
        $role = Role::where('key', $data['role'])->firstOrFail();
        [$universityId, $collegeId] = $this->validatedScope($role, $data);

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
            $this->audit($request, 'users.created', $user, ['role' => $role->key]);

            return $user;
        });

        return response()->json(['user' => $this->userData($user->load('activeAssignments.role'))], 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $data = $this->validateUser($request, $user);
        $role = Role::where('key', $data['role'])->firstOrFail();
        [$universityId, $collegeId] = $this->validatedScope($role, $data);

        if ($user->username === 'admin' && $role->key !== 'admin') {
            throw ValidationException::withMessages(['role' => 'لا يمكن تغيير دور المسؤول الرئيسي.']);
        }

        DB::transaction(function () use ($data, $role, $universityId, $collegeId, $request, $user) {
            $user->update([
                'name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                ...(! empty($data['password']) ? ['password' => $data['password'], 'must_change_password' => true] : []),
            ]);
            $assignment = $user->activeAssignments()->firstOrFail();
            $assignment->update([
                'role_id' => $role->id, 'university_id' => $universityId, 'college_id' => $collegeId,
            ]);
            $this->audit($request, 'users.updated', $user, ['role' => $role->key]);
        });

        return response()->json(['user' => $this->userData($user->load('activeAssignments.role'))]);
    }

    public function storeAssignment(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::exists('roles', 'key')],
            'universityId' => ['nullable', 'string'],
            'collegeId' => ['nullable', 'string'],
        ]);
        $role = Role::where('key', $data['role'])->firstOrFail();
        [$universityId, $collegeId] = $this->validatedScope($role, $data);

        if ($user->username === 'admin' && $role->key !== 'admin') {
            throw ValidationException::withMessages(['role' => 'لا يمكن إضافة دور آخر للمسؤول الرئيسي.']);
        }
        if ($user->activeAssignments()
            ->where('role_id', $role->id)
            ->where('university_id', $universityId)
            ->where('college_id', $collegeId)
            ->exists()) {
            throw ValidationException::withMessages(['role' => 'هذا التكليف موجود ونشط بالفعل.']);
        }

        $assignment = $user->assignments()->create([
            'role_id' => $role->id,
            'university_id' => $universityId,
            'college_id' => $collegeId,
            'active' => true,
            'assigned_at' => now(),
        ]);
        $this->audit($request, 'users.assignment_created', $user, ['assignment_id' => $assignment->id]);

        return response()->json(['user' => $this->userData($user->load(['assignments.role', 'assignments.university', 'assignments.college']))], 201);
    }

    public function updateAssignmentStatus(Request $request, User $user, UserAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->user_id === $user->id, 404);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $assignment->loadMissing('role');

        if (! $data['active'] && $user->username === 'admin' && $assignment->role->key === 'admin') {
            throw ValidationException::withMessages(['active' => 'لا يمكن تعطيل تكليف المسؤول الرئيسي.']);
        }
        if (! $data['active'] && $user->activeAssignments()->whereKeyNot($assignment->id)->doesntExist()) {
            throw ValidationException::withMessages(['active' => 'يجب أن يبقى للمستخدم تكليف نشط واحد على الأقل.']);
        }

        $assignment->update(['active' => $data['active']]);
        $this->audit(
            $request,
            $data['active'] ? 'users.assignment_activated' : 'users.assignment_deactivated',
            $user,
            ['assignment_id' => $assignment->id]
        );

        return response()->json(['user' => $this->userData($user->load(['assignments.role', 'assignments.university', 'assignments.college']))]);
    }

    public function updateUserStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        if ($request->user()->is($user) && ! $data['active']) {
            throw ValidationException::withMessages(['active' => 'لا يمكنك إيقاف حسابك الحالي.']);
        }
        $user->update(['active' => $data['active']]);
        $this->audit($request, $data['active'] ? 'users.activated' : 'users.deactivated', $user);

        return response()->json(['user' => $this->userData($user->load('activeAssignments.role'))]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);
        $user->update(['password' => $data['password'], 'must_change_password' => true]);
        $this->audit($request, 'users.password_reset', $user);

        return response()->json(['message' => 'تم تعيين كلمة المرور المؤقتة.']);
    }

    public function storeInstitution(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => [
                'required',
                Rule::in(['جامعة', 'كلية', 'قسم', 'برنامج']),
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30'],
            'parent' => ['nullable', 'string'],
            'university_type' => ['nullable', Rule::in(['public', 'private'])],
            'city' => ['nullable', 'string', 'max:100'],
            'degree' => ['nullable', Rule::in(['diploma', 'bachelor', 'master', 'doctorate'])],
        ]);
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();

        $entity = match ($data['type']) {
            'جامعة' => $this->createUniversity($data, $assignment->role->key),
            'كلية' => $this->createCollege($data, $assignment->university_id),
            'قسم' => $this->createDepartment($data, $assignment->university_id),
            'برنامج' => $this->createProgram($data, $assignment->university_id),
        };
        $this->audit($request, 'institutions.created', $entity, ['type' => $data['type']]);

        return response()->json(['message' => 'تمت إضافة العنصر إلى الهيكل المؤسسي.'], 201);
    }

    public function updateInstitution(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30'],
        ]);
        $entity = $this->resolveInstitution($type, $id);
        $this->assertInstitutionScope($request, $entity);
        $this->assertUniqueInstitutionCode($entity, trim($data['code']));
        $entity->update(['name_ar' => trim($data['name']), 'code' => trim($data['code'])]);
        $this->audit($request, 'institutions.updated', $entity);

        return response()->json(['message' => 'تم تحديث اسم ورمز المؤسسة.']);
    }

    private function validateUser(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users')->ignore($user)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::exists('roles', 'key')],
            'universityId' => ['nullable', 'string'], 'collegeId' => ['nullable', 'string'],
        ]);
    }

    private function validatedScope(Role $role, array $data): array
    {
        $universityId = $this->numericId($data['universityId'] ?? null, 'u');
        $collegeId = $this->numericId($data['collegeId'] ?? null, 'c');
        if ($role->scope === 'university' && ! $universityId) {
            throw ValidationException::withMessages(['universityId' => 'يجب تحديد جامعة لهذا الدور.']);
        }
        if ($role->scope === 'college' && (! $universityId || ! $collegeId)) {
            throw ValidationException::withMessages(['collegeId' => 'يجب تحديد الجامعة والكلية لهذا الدور.']);
        }
        if ($collegeId && ! College::whereKey($collegeId)->where('university_id', $universityId)->exists()) {
            throw ValidationException::withMessages(['collegeId' => 'الكلية لا تتبع الجامعة المحددة.']);
        }
        if (in_array($role->scope, ['platform', 'assignment'], true)) {
            return [null, null];
        }

        return [$universityId, $role->scope === 'university' ? null : $collegeId];
    }

    private function userData(User $user, mixed $selectedAssignmentId = null): array
    {
        $assignments = $user->relationLoaded('assignments')
            ? $user->assignments
            : $user->assignments()->with(['role', 'university', 'college'])->get();
        $activeAssignments = $assignments->filter(fn (UserAssignment $item) => $item->active && (! $item->expires_at || $item->expires_at->isFuture()));
        $assignment = $activeAssignments->firstWhere('id', (int) $selectedAssignmentId) ?? $activeAssignments->first();

        return [
            'id' => $user->id, 'name' => $user->name, 'username' => $user->username,
            'email' => $user->email, 'phone' => $user->phone, 'active' => $user->active,
            'mustChange' => $user->must_change_password, 'role' => $assignment?->role?->key,
            'universityId' => $assignment?->university_id ? 'u'.$assignment->university_id : '',
            'collegeId' => $assignment?->college_id ? 'c'.$assignment->college_id : '',
            'activeAssignmentId' => $assignment?->id,
            'assignments' => $assignments->map(fn (UserAssignment $item) => [
                'id' => $item->id,
                'role' => $item->role->key,
                'roleName' => $item->role->name_ar,
                'universityId' => $item->university_id ? 'u'.$item->university_id : '',
                'universityName' => $item->university?->name_ar,
                'collegeId' => $item->college_id ? 'c'.$item->college_id : '',
                'collegeName' => $item->college?->name_ar,
                'active' => $item->active,
                'expiresAt' => $item->expires_at?->toISOString(),
            ])->values(),
        ];
    }

    private function institutionData(University $university): array
    {
        $rows = [['id' => 'u'.$university->id, 'type' => 'جامعة', 'name' => $university->name_ar, 'code' => $university->code, 'parent' => null, 'active' => $university->active]];
        foreach ($university->colleges as $college) {
            $rows[] = ['id' => 'c'.$college->id, 'type' => 'كلية', 'name' => $college->name_ar, 'code' => $college->code, 'parent' => 'u'.$university->id, 'active' => $college->active];
            foreach ($college->departments as $department) {
                $rows[] = ['id' => 'd'.$department->id, 'type' => 'قسم', 'name' => $department->name_ar, 'code' => $department->code, 'parent' => 'c'.$college->id, 'active' => $department->active];
                foreach ($department->programs as $program) {
                    $rows[] = ['id' => 'p'.$program->id, 'type' => 'برنامج', 'name' => $program->name_ar, 'code' => $program->code, 'parent' => 'd'.$department->id, 'active' => $program->active];
                }
            }
        }

        return $rows;
    }

    private function createUniversity(array $data, string $role): University
    {
        abort_unless($role === 'central', 403, 'تسجيل الجامعات من اختصاص الإدارة المركزية.');

        return University::create(['name_ar' => $data['name'], 'code' => $data['code'], 'type' => $data['university_type'] ?? 'public', 'city' => $data['city'] ?? null, 'active' => true]);
    }

    private function createCollege(array $data, ?int $scope): College
    {
        $universityId = $this->numericId($data['parent'] ?? null, 'u');
        $this->assertUniversityScope($scope, $universityId);

        return College::create(['university_id' => $universityId, 'name_ar' => $data['name'], 'code' => $data['code'], 'active' => true]);
    }

    private function createDepartment(array $data, ?int $scope): Department
    {
        $college = College::findOrFail($this->numericId($data['parent'] ?? null, 'c'));
        $this->assertUniversityScope($scope, $college->university_id);

        return Department::create(['college_id' => $college->id, 'name_ar' => $data['name'], 'code' => $data['code'], 'active' => true]);
    }

    private function createProgram(array $data, ?int $scope): AcademicProgram
    {
        $department = Department::with('college')->findOrFail($this->numericId($data['parent'] ?? null, 'd'));
        $this->assertUniversityScope($scope, $department->college->university_id);

        return AcademicProgram::create(['department_id' => $department->id, 'name_ar' => $data['name'], 'code' => $data['code'], 'degree' => $data['degree'] ?? 'bachelor', 'active' => true]);
    }

    private function assertUniversityScope(?int $scope, ?int $universityId): void
    {
        if ($scope && $scope !== $universityId) {
            abort(403, 'لا يمكنك إدارة هيكل جامعة أخرى.');
        }
    }

    private function assertInstitutionScope(Request $request, Model $entity): void
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        if ($assignment->role->key !== 'university') {
            return;
        }
        $universityId = match (true) {
            $entity instanceof University => $entity->id,
            $entity instanceof College => $entity->university_id,
            $entity instanceof Department => $entity->college()->value('university_id'),
            default => $entity->department()->with('college')->firstOrFail()->college->university_id,
        };
        abort_unless($entity instanceof University === false && $assignment->university_id === $universityId, 403);
    }

    private function resolveInstitution(string $type, int $id): Model
    {
        return match ($type) {
            'u' => University::findOrFail($id), 'c' => College::findOrFail($id),
            'd' => Department::findOrFail($id), 'p' => AcademicProgram::findOrFail($id),
            default => abort(404),
        };
    }

    private function assertUniqueInstitutionCode(Model $entity, string $code): void
    {
        $exists = match (true) {
            $entity instanceof University => University::where('code', $code)->whereKeyNot($entity->id)->exists(),
            $entity instanceof College => College::where('university_id', $entity->university_id)->where('code', $code)->whereKeyNot($entity->id)->exists(),
            $entity instanceof Department => Department::where('college_id', $entity->college_id)->where('code', $code)->whereKeyNot($entity->id)->exists(),
            default => AcademicProgram::where('department_id', $entity->department_id)->where('code', $code)->whereKeyNot($entity->id)->exists(),
        };

        if ($exists) {
            throw ValidationException::withMessages(['code' => 'الرمز مستخدم مسبقًا داخل هذا النطاق.']);
        }
    }

    private function numericId(?string $value, string $prefix): ?int
    {
        if (! $value) {
            return null;
        }
        abort_unless(str_starts_with($value, $prefix) && ctype_digit(substr($value, 1)), 422, 'معرف المؤسسة غير صحيح.');

        return (int) substr($value, 1);
    }

    private function audit(Request $request, string $action, Model $target, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action,
            'target_type' => $target::class, 'target_id' => $target->getKey(),
            'metadata' => $metadata ?: null, 'ip_address' => $request->ip(),
        ]);
    }
}
