<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExternalEvaluation;
use App\Models\ImprovementAction;
use App\Models\ImprovementPlan;
use App\Models\SelfAssessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ImprovementPlanController extends Controller
{
    public function index(Request $request): View
    {
        $assignment = $request->user()->activeAssignments()->with(['role', 'university', 'college'])->firstOrFail();
        $plansQuery = ImprovementPlan::with(['cycle', 'university', 'college', 'actions']);
        $sources = collect();

        if ($assignment->role->key === 'central') {
            $plans = $plansQuery->latest()->get();
        } elseif ($assignment->role->key === 'university') {
            $plans = $plansQuery->where('university_id', $assignment->university_id)->whereNull('college_id')->latest()->get();
            $usedIds = ImprovementPlan::where('source_type', 'external_evaluation')->pluck('source_id');
            $sources = ExternalEvaluation::with(['report.cycle', 'report.university'])
                ->where('status', 'approved')->whereHas('report', fn ($query) => $query->where('university_id', $assignment->university_id))
                ->whereNotIn('id', $usedIds)->get();
        } elseif ($assignment->role->key === 'college') {
            $plans = $plansQuery->where('college_id', $assignment->college_id)->latest()->get();
            $usedIds = ImprovementPlan::where('source_type', 'self_assessment')->pluck('source_id');
            $sources = SelfAssessment::with(['cycle', 'college'])->where('college_id', $assignment->college_id)
                ->where('status', 'approved')->whereNotIn('id', $usedIds)->get();
        } else {
            abort(403);
        }

        return view('improvement-plans.index', compact('assignment', 'plans', 'sources'));
    }

    public function store(Request $request): RedirectResponse
    {
        $assignment = $this->ownerAssignment($request);
        $data = $request->validate([
            'source_type' => ['required', Rule::in(['self_assessment', 'external_evaluation'])],
            'source_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
        ]);
        if ($assignment->role->key === 'college') {
            abort_unless($data['source_type'] === 'self_assessment', 403);
            $source = SelfAssessment::whereKey($data['source_id'])->where('college_id', $assignment->college_id)->where('status', 'approved')->firstOrFail();
            $attributes = ['evaluation_cycle_id' => $source->evaluation_cycle_id, 'university_id' => $source->university_id, 'college_id' => $source->college_id];
        } else {
            abort_unless($data['source_type'] === 'external_evaluation', 403);
            $source = ExternalEvaluation::whereKey($data['source_id'])->where('status', 'approved')
                ->whereHas('report', fn ($query) => $query->where('university_id', $assignment->university_id))->with('report')->firstOrFail();
            $attributes = ['evaluation_cycle_id' => $source->report->evaluation_cycle_id, 'university_id' => $source->report->university_id, 'college_id' => null];
        }
        $plan = ImprovementPlan::create($attributes + [
            'source_type' => $data['source_type'], 'source_id' => $data['source_id'],
            'title' => $data['title'], 'status' => 'draft', 'created_by' => $request->user()->id,
        ]);
        $this->audit($request, 'improvement_plan.created', $plan);

        return redirect()->route('improvement-plans.show', $plan)->with('success', 'تم إنشاء مسودة خطة التحسين.');
    }

    public function show(Request $request, ImprovementPlan $plan): View
    {
        $this->authorizeScope($request, $plan);
        $plan->load(['cycle.standardsVersion.standards.indicators', 'university', 'college', 'actions.standard', 'actions.indicator']);
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        $editable = in_array($assignment->role->key, ['university', 'college'], true) && $plan->status !== 'completed';
        $averageProgress = round((float) $plan->actions->avg('progress'), 1);

        return view('improvement-plans.show', compact('plan', 'assignment', 'editable', 'averageProgress'));
    }

    public function addAction(Request $request, ImprovementPlan $plan): RedirectResponse
    {
        $this->authorizeOwner($request, $plan);
        abort_unless($plan->status !== 'completed', 409);
        $data = $request->validate([
            'standard_id' => ['nullable', 'integer'], 'indicator_id' => ['nullable', 'integer'],
            'gap_description' => ['required', 'string', 'max:3000'],
            'action_description' => ['required', 'string', 'max:3000'],
            'responsible_party' => ['required', 'string', 'max:255'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'starts_on' => ['required', 'date'], 'due_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);
        $standardIds = $plan->cycle->standardsVersion->standards()->pluck('id');
        if (($data['standard_id'] ?? null) && ! $standardIds->contains((int) $data['standard_id'])) {
            throw ValidationException::withMessages(['standard_id' => 'المعيار لا ينتمي إلى دورة الخطة.']);
        }
        if ($data['indicator_id'] ?? null) {
            $valid = $plan->cycle->standardsVersion->standards()->whereHas('indicators', fn ($query) => $query->whereKey($data['indicator_id']))->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['indicator_id' => 'المؤشر لا ينتمي إلى دورة الخطة.']);
            }
        }
        $action = $plan->actions()->create($data + ['progress' => 0, 'status' => 'planned', 'updated_by' => $request->user()->id]);
        $this->audit($request, 'improvement_action.created', $plan, ['action_id' => $action->id]);

        return back()->with('success', 'تمت إضافة إجراء التحسين.');
    }

    public function activate(Request $request, ImprovementPlan $plan): RedirectResponse
    {
        $this->authorizeOwner($request, $plan);
        abort_unless($plan->status === 'draft', 409);
        if (! $plan->actions()->exists()) {
            throw ValidationException::withMessages(['plan' => 'أضف إجراء تحسين واحدًا على الأقل قبل التفعيل.']);
        }
        $plan->update(['status' => 'active', 'activated_at' => now()]);
        $this->audit($request, 'improvement_plan.activated', $plan);

        return back()->with('success', 'تم تفعيل خطة التحسين وبدء المتابعة.');
    }

    public function updateAction(Request $request, ImprovementAction $action): RedirectResponse
    {
        $action->load('plan');
        $this->authorizeOwner($request, $action->plan);
        abort_unless($action->plan->status !== 'completed', 409);
        $data = $request->validate([
            'progress' => ['required', 'integer', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $status = $data['progress'] === 100 ? 'completed' : ($data['progress'] > 0 ? 'in_progress' : 'planned');
        $action->update($data + ['status' => $status, 'updated_by' => $request->user()->id]);
        $this->audit($request, 'improvement_action.updated', $action->plan, ['action_id' => $action->id, 'progress' => $data['progress']]);

        return back()->with('success', 'تم تحديث تقدم الإجراء.');
    }

    public function complete(Request $request, ImprovementPlan $plan): RedirectResponse
    {
        $this->authorizeOwner($request, $plan);
        abort_unless($plan->status === 'active', 409);
        if ($plan->actions()->where('status', '!=', 'completed')->exists()) {
            throw ValidationException::withMessages(['plan' => 'يجب إكمال جميع الإجراءات قبل إغلاق الخطة.']);
        }
        $plan->update(['status' => 'completed', 'completed_at' => now()]);
        $this->audit($request, 'improvement_plan.completed', $plan);

        return back()->with('success', 'تم إكمال خطة التحسين وإغلاقها.');
    }

    private function ownerAssignment(Request $request)
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        abort_unless(in_array($assignment->role->key, ['university', 'college'], true), 403);

        return $assignment;
    }

    private function authorizeScope(Request $request, ImprovementPlan $plan): void
    {
        if ($request->user()->hasRole('central')) {
            return;
        }
        $assignment = $this->ownerAssignment($request);
        $allowed = $assignment->role->key === 'university'
            ? $assignment->university_id === $plan->university_id && is_null($plan->college_id)
            : $assignment->college_id === $plan->college_id;
        abort_unless($allowed, 403);
    }

    private function authorizeOwner(Request $request, ImprovementPlan $plan): void
    {
        $this->authorizeScope($request, $plan);
        abort_if($request->user()->hasRole('central'), 403);
    }

    private function audit(Request $request, string $action, ImprovementPlan $plan, array $metadata = []): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'target_type' => ImprovementPlan::class, 'target_id' => $plan->id, 'metadata' => $metadata ?: null, 'ip_address' => $request->ip()]);
    }
}
