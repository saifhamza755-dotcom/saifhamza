<?php

namespace App\Http\Controllers;

use App\Models\AccreditationDecision;
use App\Models\EvaluationCycle;
use App\Models\ExternalEvaluation;
use App\Models\ImprovementAction;
use App\Models\ImprovementPlan;
use App\Models\SelfAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $assignment = $request->user()->activeAssignments()->with('role')->firstOrFail();
        abort_unless(in_array($assignment->role->key, ['central', 'university'], true), 403);
        $cycles = EvaluationCycle::with('universities')->get();
        $rows = $cycles->flatMap(function (EvaluationCycle $cycle) use ($assignment) {
            $universities = $assignment->role->key === 'central' ? $cycle->universities : $cycle->universities->where('id', $assignment->university_id);

            return $universities->map(function ($university) use ($cycle) {
                $collegeIds = $cycle->colleges()->wherePivot('university_id', $university->id)->pluck('colleges.id');
                $assessments = SelfAssessment::where('evaluation_cycle_id', $cycle->id)->whereIn('college_id', $collegeIds);
                $external = ExternalEvaluation::whereHas('report', fn ($report) => $report->where(['evaluation_cycle_id' => $cycle->id, 'university_id' => $university->id]));
                $plans = ImprovementPlan::where(['evaluation_cycle_id' => $cycle->id, 'university_id' => $university->id]);
                $actions = ImprovementAction::whereIn('improvement_plan_id', (clone $plans)->pluck('id'));
                $decision = AccreditationDecision::where(['evaluation_cycle_id' => $cycle->id, 'university_id' => $university->id])->latest('issued_at')->first();

                return ['cycleId' => 'cy'.$cycle->id, 'universityId' => 'u'.$university->id, 'collegeCount' => $collegeIds->count(), 'approvedColleges' => (clone $assessments)->where('status', 'approved')->count(), 'externalStatus' => (clone $external)->latest()->value('status') ?? '', 'externalScore' => (float) ((clone $external)->latest()->value('total_score') ?? 0), 'plans' => (clone $plans)->count(), 'completedActions' => (clone $actions)->where('status', 'completed')->count(), 'actions' => $actions->count(), 'decision' => $decision?->decision_type ?? '', 'decisionNumber' => $decision?->decision_number ?? ''];
            });
        })->values();

        return response()->json(['rows' => $rows, 'summary' => ['universities' => $rows->pluck('universityId')->unique()->count(), 'approvedExternal' => $rows->where('externalStatus', 'approved')->count(), 'decisions' => $rows->where('decision', '!=', '')->count(), 'completedActions' => $rows->sum('completedActions'), 'actions' => $rows->sum('actions')]]);
    }
}
