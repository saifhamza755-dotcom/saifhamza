<?php

namespace Database\Seeders;

use App\Models\AcademicProgram;
use App\Models\College;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Standard;
use App\Models\StandardsVersion;
use App\Models\University;
use App\Models\User;
use App\Models\UserAssignment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $roles = collect([
            ['key' => 'admin', 'name_ar' => 'مسؤول النظام التقني', 'scope' => 'platform'],
            ['key' => 'central', 'name_ar' => 'الإدارة المركزية / هيئة الجودة', 'scope' => 'platform'],
            ['key' => 'university', 'name_ar' => 'مسؤول الجودة الجامعي', 'scope' => 'university'],
            ['key' => 'college', 'name_ar' => 'مسؤول جودة الكلية', 'scope' => 'college'],
            ['key' => 'dean', 'name_ar' => 'العميد / الجهة المخولة', 'scope' => 'college'],
            ['key' => 'evaluator', 'name_ar' => 'المقيم الخارجي', 'scope' => 'assignment'],
        ])->mapWithKeys(function (array $data) {
            $role = Role::updateOrCreate(['key' => $data['key']], $data + ['is_system' => true]);

            return [$role->key => $role];
        });

        $permissions = collect([
            ['key' => 'users.manage', 'name_ar' => 'إدارة المستخدمين', 'group' => 'identity'],
            ['key' => 'institutions.manage', 'name_ar' => 'إدارة الهيكل المؤسسي', 'group' => 'institutions'],
            ['key' => 'standards.manage', 'name_ar' => 'إدارة المعايير والمؤشرات', 'group' => 'quality'],
            ['key' => 'cycles.manage', 'name_ar' => 'إدارة دورات التقييم', 'group' => 'quality'],
            ['key' => 'self_assessment.manage', 'name_ar' => 'تنفيذ التقويم الذاتي', 'group' => 'assessment'],
            ['key' => 'evidence.manage', 'name_ar' => 'إدارة الأدلة', 'group' => 'assessment'],
            ['key' => 'college_reports.approve', 'name_ar' => 'اعتماد تقرير الكلية', 'group' => 'reports'],
            ['key' => 'university_reports.manage', 'name_ar' => 'إدارة التقرير الجامعي', 'group' => 'reports'],
            ['key' => 'evaluation.manage', 'name_ar' => 'تنفيذ التقييم الخارجي', 'group' => 'evaluation'],
            ['key' => 'improvement.manage', 'name_ar' => 'إدارة خطط التحسين', 'group' => 'improvement'],
            ['key' => 'accreditation.decide', 'name_ar' => 'إدارة قرار الاعتماد', 'group' => 'accreditation'],
            ['key' => 'audit.view', 'name_ar' => 'عرض سجل التدقيق', 'group' => 'audit'],
        ])->mapWithKeys(function (array $data) {
            $permission = Permission::updateOrCreate(['key' => $data['key']], $data);

            return [$permission->key => $permission];
        });

        $rolePermissions = [
            'admin' => ['users.manage', 'audit.view'],
            'central' => ['institutions.manage', 'standards.manage', 'cycles.manage', 'university_reports.manage', 'evaluation.manage', 'improvement.manage', 'accreditation.decide', 'audit.view'],
            'university' => ['institutions.manage', 'university_reports.manage', 'improvement.manage'],
            'college' => ['self_assessment.manage', 'evidence.manage', 'improvement.manage'],
            'dean' => ['evidence.manage', 'college_reports.approve'],
            'evaluator' => ['evaluation.manage'],
        ];
        foreach ($rolePermissions as $roleKey => $permissionKeys) {
            $roles[$roleKey]->permissions()->sync($permissions->only($permissionKeys)->pluck('id'));
        }

        $university = University::updateOrCreate(
            ['code' => 'SU'],
            ['name_ar' => 'جامعة صنعاء', 'name_en' => "Sana'a University", 'type' => 'public', 'city' => 'صنعاء', 'active' => true]
        );
        $engineering = College::updateOrCreate(
            ['university_id' => $university->id, 'code' => 'ENG'],
            ['name_ar' => 'كلية الهندسة', 'name_en' => 'Faculty of Engineering', 'active' => true]
        );
        $computing = College::updateOrCreate(
            ['university_id' => $university->id, 'code' => 'CIT'],
            ['name_ar' => 'كلية الحاسوب وتكنولوجيا المعلومات', 'name_en' => 'Faculty of Computer and Information Technology', 'active' => true]
        );
        College::updateOrCreate(
            ['university_id' => $university->id, 'code' => 'MED'],
            ['name_ar' => 'كلية الطب والعلوم الصحية', 'name_en' => 'Faculty of Medicine and Health Sciences', 'active' => true]
        );
        $department = Department::updateOrCreate(
            ['college_id' => $computing->id, 'code' => 'CS'],
            ['name_ar' => 'قسم علوم الحاسوب', 'name_en' => 'Computer Science', 'active' => true]
        );
        AcademicProgram::updateOrCreate(
            ['department_id' => $department->id, 'code' => 'BSC-CS'],
            ['name_ar' => 'بكالوريوس علوم الحاسوب', 'name_en' => 'BSc Computer Science', 'degree' => 'bachelor', 'active' => true]
        );

        $accounts = [
            ['admin', 'مسؤول النظام', 'admin@qa.local', 'admin', null, null],
            ['central', 'الإدارة المركزية', 'central@qa.local', 'central', null, null],
            ['university', 'مسؤول الجودة بجامعة صنعاء', 'university@qa.local', 'university', $university->id, null],
            ['college', 'مسؤول جودة كلية الهندسة', 'college@qa.local', 'college', $university->id, $engineering->id],
            ['dean', 'عميد كلية الهندسة', 'dean@qa.local', 'dean', $university->id, $engineering->id],
            ['evaluator', 'المقيم الخارجي التجريبي', 'evaluator@qa.local', 'evaluator', null, null],
        ];

        foreach ($accounts as [$username, $name, $email, $roleKey, $universityId, $collegeId]) {
            $user = User::updateOrCreate(
                ['username' => $username],
                ['name' => $name, 'email' => $email, 'password' => 'Demo@1234', 'active' => true, 'must_change_password' => false]
            );
            UserAssignment::updateOrCreate(
                ['user_id' => $user->id, 'role_id' => $roles[$roleKey]->id, 'university_id' => $universityId, 'college_id' => $collegeId],
                ['active' => true, 'assigned_at' => now(), 'expires_at' => null]
            );
        }

        $centralUser = User::where('username', 'central')->firstOrFail();
        $standardsVersion = StandardsVersion::updateOrCreate(
            ['version' => '1.0'],
            ['name' => 'الإطار الوطني لضمان الجودة', 'status' => 'active', 'effective_from' => '2026-01-01', 'created_by' => $centralUser->id]
        );
        $standards = [
            ['1', 'التخطيط الاستراتيجي', 'Strategic Planning', 12, ['الرؤية والرسالة والأهداف', 'تنفيذ الخطة ومتابعة مؤشرات الأداء']],
            ['2', 'القيادة والحوكمة', 'Leadership and Governance', 12, ['الهيكل التنظيمي والصلاحيات', 'النزاهة والشفافية والمساءلة']],
            ['3', 'البرامج الأكاديمية', 'Academic Programs', 12, ['تصميم البرامج ومخرجات التعلم', 'مراجعة البرامج وتطويرها']],
            ['4', 'الطلبة', 'Students', 10, ['القبول والإرشاد والدعم', 'الأنشطة وقياس رضا الطلبة']],
            ['5', 'الموارد البشرية', 'Human Resources', 10, ['كفاية الكادر واختياره', 'التطوير المهني وتقييم الأداء']],
            ['6', 'الموارد المالية والبنية التحتية', 'Finance and Infrastructure', 12, ['كفاية الموارد وإدارتها', 'المباني والتجهيزات والسلامة']],
            ['7', 'البحث العلمي', 'Scientific Research', 10, ['سياسات البحث وتمويله', 'الإنتاج العلمي وأثره']],
            ['8', 'خدمة المجتمع والشراكة', 'Community Service and Partnership', 10, ['برامج خدمة المجتمع', 'الشراكات وقياس الأثر']],
            ['9', 'ضمان الجودة والتحسين المستمر', 'Quality Assurance and Continuous Improvement', 12, ['نظام ضمان الجودة والتقويم', 'خطط التحسين ومتابعة تنفيذها']],
        ];
        foreach ($standards as [$code, $nameAr, $nameEn, $weight, $indicatorNames]) {
            $standard = Standard::updateOrCreate(
                ['standards_version_id' => $standardsVersion->id, 'code' => $code],
                ['name_ar' => $nameAr, 'name_en' => $nameEn, 'weight' => $weight, 'sort_order' => (int) $code, 'active' => true]
            );
            foreach ($indicatorNames as $index => $indicatorName) {
                $standard->indicators()->updateOrCreate(
                    ['code' => $code.'.'.($index + 1)],
                    ['name_ar' => $indicatorName, 'weight' => 50, 'evidence_required' => true, 'sort_order' => $index + 1, 'active' => true]
                );
            }
        }
    }
}
