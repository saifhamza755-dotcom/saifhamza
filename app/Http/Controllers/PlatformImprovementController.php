<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExternalEvaluation;
use App\Models\ImprovementAction;
use App\Models\ImprovementPlan;
use App\Models\SelfAssessment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformImprovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        $plans = ImprovementPlan::with(['actions', 'college'])->latest();
        if ($assignment->role->key === 'university') {
            $plans->where('university_id', $assignment->university_id)->whereNull('college_id');
        } elseif ($assignment->role->key === 'college') {
            $plans->where('college_id', $assignment->college_id);
        } elseif ($assignment->role->key !== 'central') {
            abort(403);
        }

        return response()->json(['actions' => $plans->get()->flatMap(fn (ImprovementPlan $plan) => $plan->actions->map(fn (ImprovementAction $action) => $this->actionData($action, $plan)))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $assignment = $this->owner($request);
        $data = $request->validate([
            'cycleId' => ['required', 'string'], 'universityId' => ['required', 'string'], 'collegeId' => ['nullable', 'string'],
            'action' => ['required', 'string', 'max:3000'], 'gapDescription' => ['nullable', 'string', 'max:3000'],
            'ownerId' => ['required', 'integer', Rule::exists('users', 'id')], 'due' => ['required', 'date'],
        ]);
        $cycleId = $this->id($data['cycleId'], 'cy');
        $universityId = $this->id($data['universityId'], 'u');
        abort_unless($assignment->university_id === $universityId, 403);
        $collegeId = filled($data['collegeId'] ?? null) ? $this->id($data['collegeId'], 'c') : null;
        if ($assignment->role->key === 'college') {
            abort_unless($collegeId === $assignment->college_id, 403);
            $source = SelfAssessment::where(['evaluation_cycle_id' => $cycleId, 'college_id' => $collegeId, 'status' => 'approved'])->firstOrFail();
            $sourceType = 'self_assessment';
        } else {
            abort_unless($collegeId === null, 422, 'الخطة الجامعية تنشأ من التقييم الخارجي الجامعي.');
            $source = ExternalEvaluation::where('status', 'approved')->whereHas('report', fn ($report) => $report->where(['evaluation_cycle_id' => $cycleId, 'university_id' => $universityId]))->firstOrFail();
            $sourceType = 'external_evaluation';
        }
        $owner = User::findOrFail($data['ownerId']);
        abort_unless($owner->activeAssignments()->where('university_id', $universityId)->exists(), 422, 'مسؤول التنفيذ خارج نطاق الجامعة.');
        $plan = ImprovementPlan::firstOrCreate(['source_type' => $sourceType, 'source_id' => $source->id], [
            'evaluation_cycle_id' => $cycleId, 'university_id' => $universityId, 'college_id' => $collegeId,
            'title' => 'خطة تحسين مرتبطة بنتائج التقييم', 'status' => 'active', 'created_by' => $request->user()->id, 'activated_at' => now(),
        ]);
        $action = $plan->actions()->create([
            'gap_description' => $data['gapDescription'] ?: 'معالجة فجوة موثقة في نتائج التقييم.',
            'action_description' => $data['action'], 'responsible_party' => $owner->name,
            'priority' => 'high', 'starts_on' => now()->toDateString(), 'due_on' => $data['due'],
            'progress' => 0, 'status' => 'planned', 'updated_by' => $request->user()->id,
        ]);
        $this->audit($request, 'platform_improvement.created', $plan, $action->id);

        return response()->json(['action' => $this->actionData($action, $plan)], 201);
    }

    public function update(Request $request, ImprovementAction $action): JsonResponse
    {
        $action->load('plan');
        $assignment = $this->owner($request);
        abort_unless($action->plan->university_id === $assignment->university_id && ($assignment->role->key === 'university' ? $action->plan->college_id === null : $action->plan->college_id === $assignment->college_id), 403);
        $data = $request->validate(['progress' => ['required', 'integer', 'between:0,100'], 'notes' => ['nullable', 'string', 'max:3000']]);
        $action->update($data + ['status' => $data['progress'] === 100 ? 'completed' : ($data['progress'] ? 'in_progress' : 'planned'), 'updated_by' => $request->user()->id]);
        $this->audit($request, 'platform_improvement.updated', $action->plan, $action->id);

        return response()->json(['action' => $this->actionData($action->fresh(), $action->plan)]);
    }

    private function actionData(ImprovementAction $action, ImprovementPlan $plan): array
    {
        return ['id' => 'ia'.$action->id, 'cycleId' => 'cy'.$plan->evaluation_cycle_id, 'universityId' => 'u'.$plan->university_id, 'collegeId' => $plan->college_id ? 'c'.$plan->college_id : '', 'action' => $action->action_description, 'gapDescription' => $action->gap_description, 'ownerId' => $action->updated_by, 'ownerName' => $action->responsible_party, 'due' => $action->due_on->toDateString(), 'progress' => $action->progress, 'notes' => $action->notes ?? '', 'files' => []];
    }

    private function owner(Request $request)
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        abort_unless(in_array($assignment->role->key, ['university', 'college'], true), 403);

        return $assignment;
    }

    private function id(string $value, string $prefix): int
    {
        abort_unless(str_starts_with($value, $prefix) && ctype_digit(substr($value, strlen($prefix))), 422, 'المعرف غير صحيح.');

        return (int) substr($value, strlen($prefix));
    }

    private function audit(Request $request, string $action, ImprovementPlan $plan, int $actionId): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'target_type' => ImprovementPlan::class, 'target_id' => $plan->id, 'metadata' => ['action_id' => $actionId], 'ip_address' => $request->ip()]);
    }
}
