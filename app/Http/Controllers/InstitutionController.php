<?php

namespace App\Http\Controllers;

use App\Models\AcademicProgram;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Department;
use App\Models\University;
use App\Models\UserAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InstitutionController extends Controller
{
    public function index(Request $request): View
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        $query = University::with(['colleges.departments.programs'])->orderBy('name_ar');
        if ($assignment->role->key === 'university') {
            $query->whereKey($assignment->university_id);
        }

        return view('institutions.index', ['universities' => $query->get(), 'assignment' => $assignment]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $request->validate([
            'entity_type' => ['required', Rule::in(['university', 'college', 'department', 'program'])],
        ])['entity_type'];
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();

        $entity = match ($type) {
            'university' => $this->createUniversity($request, $assignment->role->key),
            'college' => $this->createCollege($request, $assignment),
            'department' => $this->createDepartment($request, $assignment),
            'program' => $this->createProgram($request, $assignment),
        };

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => "institutions.{$type}.created",
            'target_type' => $entity::class, 'target_id' => $entity->getKey(), 'ip_address' => $request->ip(),
        ]);

        return back()->with('success', 'تمت إضافة العنصر إلى الهيكل المؤسسي.');
    }

    public function updateStatus(Request $request, string $type, int $id): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        $entity = $this->resolveEntity($type, $id);
        $universityId = $this->universityIdFor($entity);

        if ($assignment->role->key === 'university' && ($type === 'university' || $assignment->university_id !== $universityId)) {
            abort(403, 'لا يمكنك تعديل عنصر خارج جامعتك.');
        }

        $entity->update(['active' => $data['active']]);
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $data['active'] ? 'institutions.activated' : 'institutions.deactivated',
            'target_type' => $entity::class, 'target_id' => $entity->getKey(), 'ip_address' => $request->ip(),
        ]);

        return back()->with('success', 'تم تحديث حالة العنصر.');
    }

    private function createUniversity(Request $request, string $roleKey): University
    {
        if ($roleKey !== 'central') {
            abort(403, 'تسجيل الجامعات الجديدة من اختصاص الإدارة المركزية.');
        }
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'], 'name_en' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'unique:universities,code'],
            'type' => ['required', Rule::in(['public', 'private'])], 'city' => ['nullable', 'string', 'max:100'],
        ]);

        return University::create($data + ['active' => true]);
    }

    private function createCollege(Request $request, UserAssignment $assignment): College
    {
        $data = $request->validate([
            'university_id' => ['required', 'integer', Rule::exists('universities', 'id')],
            'name_ar' => ['required', 'string', 'max:255'], 'name_en' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30'],
        ]);
        $this->assertUniversityScope($assignment, (int) $data['university_id']);
        if (College::where('university_id', $data['university_id'])->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'رمز الكلية مستخدم داخل هذه الجامعة.']);
        }

        return College::create($data + ['active' => true]);
    }

    private function createDepartment(Request $request, UserAssignment $assignment): Department
    {
        $data = $request->validate([
            'college_id' => ['required', 'integer', Rule::exists('colleges', 'id')],
            'name_ar' => ['required', 'string', 'max:255'], 'name_en' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30'],
        ]);
        $college = College::findOrFail($data['college_id']);
        $this->assertUniversityScope($assignment, $college->university_id);
        if (Department::where('college_id', $college->id)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'رمز القسم مستخدم داخل هذه الكلية.']);
        }

        return Department::create($data + ['active' => true]);
    }

    private function createProgram(Request $request, UserAssignment $assignment): AcademicProgram
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'name_ar' => ['required', 'string', 'max:255'], 'name_en' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30'],
            'degree' => ['required', Rule::in(['diploma', 'bachelor', 'master', 'doctorate'])],
        ]);
        $department = Department::with('college')->findOrFail($data['department_id']);
        $this->assertUniversityScope($assignment, $department->college->university_id);
        if (AcademicProgram::where('department_id', $department->id)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'رمز البرنامج مستخدم داخل هذا القسم.']);
        }

        return AcademicProgram::create($data + ['active' => true]);
    }

    private function assertUniversityScope(UserAssignment $assignment, int $universityId): void
    {
        if ($assignment->role->key === 'university' && $assignment->university_id !== $universityId) {
            abort(403, 'لا يمكنك إدارة هيكل جامعة أخرى.');
        }
    }

    private function resolveEntity(string $type, int $id): Model
    {
        return match ($type) {
            'university' => University::findOrFail($id), 'college' => College::findOrFail($id),
            'department' => Department::findOrFail($id), 'program' => AcademicProgram::findOrFail($id),
            default => abort(404),
        };
    }

    private function universityIdFor(Model $entity): int
    {
        return match (true) {
            $entity instanceof University => $entity->id,
            $entity instanceof College => $entity->university_id,
            $entity instanceof Department => (int) $entity->college()->value('university_id'),
            $entity instanceof AcademicProgram => $entity->department()->with('college')->firstOrFail()->college->university_id,
        };
    }
}
