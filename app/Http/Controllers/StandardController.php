<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Standard;
use App\Models\StandardsVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StandardController extends Controller
{
    public function index(): View
    {
        return view('standards.index', [
            'versions' => StandardsVersion::with(['standards.indicators', 'creator'])->latest()->get(),
        ]);
    }

    public function storeVersion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:50', 'unique:standards_versions,version'],
            'effective_from' => ['nullable', 'date'],
        ]);
        $version = StandardsVersion::create($data + ['status' => 'draft', 'created_by' => $request->user()->id]);
        $this->audit($request, 'standards.version.created', $version);

        return back()->with('success', 'تم إنشاء إصدار المعايير كمسودة.');
    }

    public function storeStandard(Request $request, StandardsVersion $version): RedirectResponse
    {
        $this->ensureEditable($version);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('standards')->where('standards_version_id', $version->id)],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'weight' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'sort_order' => ['required', 'integer', 'min:1'],
        ]);
        $standard = $version->standards()->create($data + ['active' => true]);
        $this->audit($request, 'standards.standard.created', $standard);

        return back()->with('success', 'تمت إضافة المعيار.');
    }

    public function storeIndicator(Request $request, Standard $standard): RedirectResponse
    {
        $standard->load('version');
        $this->ensureEditable($standard->version);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('indicators')->where('standard_id', $standard->id)],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'sort_order' => ['required', 'integer', 'min:1'],
            'evidence_required' => ['nullable', 'boolean'],
        ]);
        $indicator = $standard->indicators()->create($data + [
            'evidence_required' => $request->boolean('evidence_required'), 'active' => true,
        ]);
        $this->audit($request, 'standards.indicator.created', $indicator);

        return back()->with('success', 'تمت إضافة المؤشر.');
    }

    public function activate(Request $request, StandardsVersion $version): RedirectResponse
    {
        $this->ensureEditable($version);
        $version->load('standards.indicators');
        if ($version->standards->isEmpty()) {
            throw ValidationException::withMessages(['version' => 'لا يمكن اعتماد إصدار بلا معايير.']);
        }
        if (round((float) $version->standards->sum('weight'), 2) !== 100.0) {
            throw ValidationException::withMessages(['version' => 'يجب أن يكون مجموع أوزان المعايير 100%.']);
        }
        foreach ($version->standards as $standard) {
            if ($standard->indicators->isEmpty() || round((float) $standard->indicators->sum('weight'), 2) !== 100.0) {
                throw ValidationException::withMessages([
                    'version' => "يجب أن يحتوي المعيار {$standard->code} على مؤشرات مجموع أوزانها 100%.",
                ]);
            }
        }

        StandardsVersion::where('status', 'active')->whereKeyNot($version->id)->update(['status' => 'archived']);
        $version->update(['status' => 'active', 'effective_from' => $version->effective_from ?? now()->toDateString()]);
        $this->audit($request, 'standards.version.activated', $version);

        return back()->with('success', 'تم اعتماد إصدار المعايير وتجميده للاستخدام في الدورات.');
    }

    private function ensureEditable(StandardsVersion $version): void
    {
        if (! $version->isEditable()) {
            abort(409, 'لا يمكن تعديل إصدار معتمد أو مرتبط بدورة تقييم.');
        }
    }

    private function audit(Request $request, string $action, $target): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action,
            'target_type' => $target::class, 'target_id' => $target->getKey(), 'ip_address' => $request->ip(),
        ]);
    }
}
