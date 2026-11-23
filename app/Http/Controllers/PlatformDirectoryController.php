<?php

namespace App\Http\Controllers;

use App\Models\AcademicProgram;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Department;
use App\Models\Role;
use App\Models\University;
use App\Models\User;
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
            $users = User::with(['activeAssignments.role'])->orderBy('id')->get()->map(fn (User $user) => $this->userData($user));
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
            $user->assignments()->update(['active' => false]);
            $user->assignments()->create([
                'role_id' => $role->id, 'university_id' => $universityId, 'college_id' => $collegeId,
                'active' => true, 'assigned_at' => now(),
            ]);
            $this->audit($request, 'users.updated', $user, ['role' => $role->key]);
        });

        return response()->json(['user' => $this->userData($user->load('activeAssignments.role'))]);
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
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $entity = $this->resolveInstitution($type, $id);
        $this->assertInstitutionScope($request, $entity);
        $entity->update(['name_ar' => $data['name']]);
        $this->audit($request, 'institutions.updated', $entity);

        return response()->json(['message' => 'تم تحديث اسم المؤسسة.']);
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

    private function userData(User $user): array
    {
        $assignment = $user->activeAssignments->first();

        return [
            'id' => $user->id, 'name' => $user->name, 'username' => $user->username,
            'email' => $user->email, 'phone' => $user->phone, 'active' => $user->active,
            'mustChange' => $user->must_change_password, 'role' => $assignment?->role?->key,
            'universityId' => $assignment?->university_id ? 'u'.$assignment->university_id : '',
            'collegeId' => $assignment?->college_id ? 'c'.$assignment->college_id : '',
        ];
    }

    private function institutionData(University $university): array
    {
        $rows = [['id' => 'u'.$university->id, 'type' => 'جامعة', 'name' => $university->name_ar, 'parent' => null, 'active' => $university->active]];
        foreach ($university->colleges as $college) {
            $rows[] = ['id' => 'c'.$college->id, 'type' => 'كلية', 'name' => $college->name_ar, 'parent' => 'u'.$university->id, 'active' => $college->active];
            foreach ($college->departments as $department) {
                $rows[] = ['id' => 'd'.$department->id, 'type' => 'قسم', 'name' => $department->name_ar, 'parent' => 'c'.$college->id, 'active' => $department->active];
                foreach ($department->programs as $program) {
                    $rows[] = ['id' => 'p'.$program->id, 'type' => 'برنامج', 'name' => $program->name_ar, 'parent' => 'd'.$department->id, 'active' => $program->active];
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
