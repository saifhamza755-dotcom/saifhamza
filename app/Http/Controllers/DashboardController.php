<?php

namespace App\Http\Controllers;

use App\Models\College;
use App\Models\ExternalEvaluation;
use App\Models\ImprovementPlan;
use App\Models\SelfAssessment;
use App\Models\University;
use App\Models\UniversityReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user()->load([
            'activeAssignments.role.permissions',
            'activeAssignments.university',
            'activeAssignments.college.university',
        ]);
        $assignment = $user->activeAssignments->firstOrFail();

        $universityCount = match ($assignment->role->scope) {
            'platform' => University::where('active', true)->count(),
            'university', 'college' => $assignment->university_id ? 1 : 0,
            default => 0,
        };

        $collegeCount = match ($assignment->role->scope) {
            'platform' => College::where('active', true)->count(),
            'university' => College::where('university_id', $assignment->university_id)->where('active', true)->count(),
            'college' => $assignment->college_id ? 1 : 0,
            default => 0,
        };

        $pendingCount = match ($assignment->role->key) {
            'central' => UniversityReport::where('status', 'submitted')->count() + ExternalEvaluation::where('status', 'submitted')->count(),
            'university' => UniversityReport::where('university_id', $assignment->university_id)->where('status', 'returned')->count(),
            'college' => SelfAssessment::where('college_id', $assignment->college_id)->where('status', 'returned')->count(),
            'dean' => SelfAssessment::where('college_id', $assignment->college_id)->where('status', 'submitted')->count(),
            'evaluator' => ExternalEvaluation::where('evaluator_id', $request->user()->id)->whereIn('status', ['assigned', 'returned'])->count(),
            default => 0,
        };
        $activePlans = ImprovementPlan::when($assignment->role->key === 'university', fn ($query) => $query->where('university_id', $assignment->university_id)->whereNull('college_id'))
            ->when(in_array($assignment->role->key, ['college', 'dean'], true), fn ($query) => $query->where('college_id', $assignment->college_id))
            ->when(! in_array($assignment->role->key, ['central', 'university', 'college', 'dean'], true), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('status', 'active')->count();

        return view('dashboard', compact('user', 'assignment', 'universityCount', 'collegeCount', 'pendingCount', 'activePlans'));
    }
}
