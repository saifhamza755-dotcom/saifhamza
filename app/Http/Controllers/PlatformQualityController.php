<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EvaluationCycle;
use App\Models\Indicator;
use App\Models\Standard;
use App\Models\StandardsVersion;
use App\Models\University;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformQualityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $versions = StandardsVersion::with('standards.indicators')->orderByDesc('id')->get();
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        $cyclesQuery = EvaluationCycle::with(['standardsVersion.standards.indicators', 'universities', 'colleges'])->orderByDesc('id');
        if (in_array($assignment->role->key, ['university', 'college', 'dean'], true)) {
            $cyclesQuery->whereHas('universities', fn ($query) => $query->whereKey($assignment->university_id));
        } elseif ($assignment->role->key === 'evaluator') {
            $cyclesQuery->whereHas('universityReports.externalEvaluation', fn ($query) => $query->where('evaluator_id', $request->user()->id));
        }
        $cycles = $cyclesQuery->get();

        return response()->json([
            'versions' => $versions->map(fn (StandardsVersion $version) => $this->versionData($version)),
            'cycles' => $cycles->map(fn (EvaluationCycle $cycle) => $this->cycleData($cycle)),
        ]);
    }

    public function storeVersion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:50', 'unique:standards_versions,version'],
            'source' => ['nullable', 'string'],
        ]);
        $sourceId = $this->numericId($data['source'] ?? null, 'v');

        $version = DB::transaction(function () use ($data, $sourceId, $request) {
            $version = StandardsVersion::create([
                'name' => $data['name'], 'version' => $data['version'],
                'status' => 'draft', 'created_by' => $request->user()->id,
            ]);
            if ($sourceId) {
                $source = StandardsVersion::with('standards.indicators')->findOrFail($sourceId);
                foreach ($source->standards as $standard) {
                    $copy = $version->standards()->create($standard->only(['code', 'name_ar', 'name_en', 'weight', 'sort_order', 'active']));
                    foreach ($standard->indicators as $indicator) {
                        $copy->indicators()->create($indicator->only(['code', 'name_ar', 'name_en', 'description', 'weight', 'evidence_required', 'sort_order', 'active']));
                    }
                }
            }
            $this->audit($request, 'standards.version.created', $version);

            return $version;
        });

        return response()->json(['version' => $this->versionData($version->load('standards.indicators'))], 201);
    }

    public function storeStandard(Request $request, StandardsVersion $version): JsonResponse
    {
        $this->ensureEditable($version);
        $data = $request->validate($this->standardRules($version));
        $standard = $version->standards()->create($data + ['active' => true]);
        $this->audit($request, 'standards.standard.created', $standard);

        return response()->json(['standard' => $standard], 201);
    }

    public function updateStandard(Request $request, Standard $standard): JsonResponse
    {
        $standard->load('version');
        $this->ensureEditable($standard->version);
        $data = $request->validate($this->standardRules($standard->version, $standard));
        $standard->update($data);
        $this->audit($request, 'standards.standard.updated', $standard);

        return response()->json(['standard' => $standard]);
    }

    public function storeIndicator(Request $request, Standard $standard): JsonResponse
    {
        $standard->load('version');
        $this->ensureEditable($standard->version);
        $data = $request->validate($this->indicatorRules($standard));
        $indicator = $standard->indicators()->create($data + ['active' => true]);
        $this->audit($request, 'standards.indicator.created', $indicator);

        return response()->json(['indicator' => $indicator], 201);
    }

    public function updateIndicator(Request $request, Indicator $indicator): JsonResponse
    {
        $indicator->load('standard.version');
        $this->ensureEditable($indicator->standard->version);
        $targetStandard = Standard::with('version')->findOrFail($this->numericId($request->input('standardId'), 's'));
        abort_unless($targetStandard->standards_version_id === $indicator->standard->standards_version_id, 422, 'المعيار لا يتبع الإصدار نفسه.');
        $data = $request->validate($this->indicatorRules($targetStandard, $indicator));
        $indicator->update($data + ['standard_id' => $targetStandard->id]);
        $this->audit($request, 'standards.indicator.updated', $indicator);

        return response()->json(['indicator' => $indicator]);
    }

    public function activateVersion(Request $request, StandardsVersion $version): JsonResponse
    {
        $this->ensureEditable($version);
        $version->load('standards.indicators');
        if ($version->standards->isEmpty() || round((float) $version->standards->sum('weight'), 2) !== 100.0) {
            throw ValidationException::withMessages(['version' => 'يجب وجود معايير مجموع أوزانها 100%.']);
        }
        foreach ($version->standards as $standard) {
            if ($standard->indicators->isEmpty() || round((float) $standard->indicators->sum('weight'), 2) !== 100.0) {
                throw ValidationException::withMessages(['version' => "مجموع أوزان مؤشرات المعيار {$standard->code} يجب أن يكون 100%."]);
            }
        }
        StandardsVersion::where('status', 'active')->whereKeyNot($version->id)->update(['status' => 'archived']);
        $version->update(['status' => 'active', 'effective_from' => $version->effective_from ?? now()->toDateString()]);
        $this->audit($request, 'standards.version.activated', $version);

        return response()->json(['message' => 'تم اعتماد إصدار المعايير.']);
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:evaluation_cycles,code'],
            'start' => ['required', 'date'], 'end' => ['required', 'date', 'after:start'],
            'versionId' => ['required', 'string'],
            'universityIds' => ['required', 'array', 'min:1'], 'universityIds.*' => ['string'],
        ]);
        $versionId = $this->numericId($data['versionId'], 'v');
        $version = StandardsVersion::whereKey($versionId)->where('status', 'active')->firstOrFail();
        $universityIds = collect($data['universityIds'])->map(fn ($id) => $this->numericId($id, 'u'))->unique()->values();
        $universities = University::with(['colleges' => fn ($query) => $query->where('active', true)])
            ->where('active', true)->whereIn('id', $universityIds)->get();
        if ($universities->count() !== $universityIds->count() || $universities->contains(fn ($university) => $university->colleges->isEmpty())) {
            throw ValidationException::withMessages(['universityIds' => 'كل جامعة مشاركة يجب أن تكون نشطة وتحتوي على كلية نشطة.']);
        }

        $cycle = DB::transaction(function () use ($data, $version, $universities, $request) {
            $cycle = EvaluationCycle::create([
                'name' => $data['name'], 'code' => $data['code'], 'starts_on' => $data['start'],
                'ends_on' => $data['end'], 'status' => 'draft',
                'standards_version_id' => $version->id, 'created_by' => $request->user()->id,
            ]);
            $cycle->universities()->attach($universities->pluck('id'));
            foreach ($universities as $university) {
                foreach ($university->colleges as $college) {
                    $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);
                }
            }
            $this->audit($request, 'cycles.created', $cycle);

            return $cycle;
        });

        return response()->json(['cycle' => $this->cycleData($cycle->load(['standardsVersion.standards.indicators', 'universities', 'colleges']))], 201);
    }

    public function updateCycleStatus(Request $request, EvaluationCycle $cycle): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'closed'])]]);
        $allowed = ($cycle->status === 'draft' && $data['status'] === 'open') || ($cycle->status === 'open' && $data['status'] === 'closed');
        if (! $allowed) {
            throw ValidationException::withMessages(['status' => 'انتقال حالة الدورة غير مسموح.']);
        }
        $cycle->update(['status' => $data['status']]);
        $this->audit($request, 'cycles.'.$data['status'], $cycle);

        return response()->json(['message' => $data['status'] === 'open' ? 'تم فتح الدورة.' : 'تم إغلاق الدورة.']);
    }

    private function versionData(StandardsVersion $version): array
    {
        return [
            'id' => 'v'.$version->id, 'name' => $version->name, 'version' => $version->version,
            'status' => $version->status, 'editable' => $version->isEditable(),
            'standards' => $version->standards->map(fn (Standard $standard) => [
                'id' => 's'.$standard->id, 'code' => $standard->code, 'name' => $standard->name_ar,
                'weight' => (float) $standard->weight, 'sortOrder' => $standard->sort_order,
            ])->values(),
            'indicators' => $version->standards->flatMap(fn (Standard $standard) => $standard->indicators->map(fn (Indicator $indicator) => [
                'id' => 'i'.$indicator->id, 'standardId' => 's'.$standard->id, 'code' => $indicator->code,
                'text' => $indicator->name_ar, 'requirement' => $indicator->description ?? '',
                'weight' => (float) $indicator->weight, 'sortOrder' => $indicator->sort_order,
                'evidenceRequired' => $indicator->evidence_required,
            ]))->values(),
        ];
    }

    private function cycleData(EvaluationCycle $cycle): array
    {
        $version = $this->versionData($cycle->standardsVersion);
        $statuses = ['draft' => 'مسودة', 'open' => 'نشطة', 'closed' => 'مغلقة'];

        return [
            'id' => 'cy'.$cycle->id, 'name' => $cycle->name, 'code' => $cycle->code,
            'start' => $cycle->starts_on->toDateString(), 'end' => $cycle->ends_on->toDateString(),
            'status' => $statuses[$cycle->status], 'versionId' => $version['id'], 'standards' => $version,
            'universityIds' => $cycle->universities->map(fn ($university) => 'u'.$university->id)->values(),
            'collegeIds' => $cycle->colleges->map(fn ($college) => 'c'.$college->id)->values(),
        ];
    }

    private function standardRules(StandardsVersion $version, ?Standard $standard = null): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('standards')->where('standards_version_id', $version->id)->ignore($standard)],
            'name_ar' => ['required', 'string', 'max:255'], 'weight' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'sort_order' => ['required', 'integer', 'min:1'],
        ];
    }

    private function indicatorRules(Standard $standard, ?Indicator $indicator = null): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('indicators')->where('standard_id', $standard->id)->ignore($indicator)],
            'name_ar' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['required', 'numeric', 'gt:0', 'lte:100'], 'sort_order' => ['required', 'integer', 'min:1'],
            'evidence_required' => ['nullable', 'boolean'],
        ];
    }

    private function ensureEditable(StandardsVersion $version): void
    {
        abort_unless($version->isEditable(), 409, 'لا يمكن تعديل إصدار معتمد أو مرتبط بدورة.');
    }

    private function numericId(?string $value, string $prefix): ?int
    {
        if (! $value) {
            return null;
        }
        abort_unless(str_starts_with($value, $prefix) && ctype_digit(substr($value, strlen($prefix))), 422, 'المعرف غير صحيح.');

        return (int) substr($value, strlen($prefix));
    }

    private function audit(Request $request, string $action, $target): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action,
            'target_type' => $target::class, 'target_id' => $target->getKey(), 'ip_address' => $request->ip(),
        ]);
    }
}
