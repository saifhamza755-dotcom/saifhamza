<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $assignment = $user?->activeAssignments()->with('role')->first();

        $platformUser = $user && $assignment ? [
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $assignment->role->key,
            'universityId' => $assignment->university_id ? 'u'.$assignment->university_id : '',
            'collegeId' => $assignment->college_id ? 'c'.$assignment->college_id : '',
            'mustChange' => $user->must_change_password,
        ] : null;

        return view('platform.index', [
            'platformConfig' => [
                'user' => $platformUser,
                'loginUrl' => route('platform.session.store'),
                'logoutUrl' => route('platform.session.destroy'),
                'logoutPageUrl' => route('platform.session.destroy-page'),
                'directoryUrl' => route('platform.api.directory'),
                'usersUrl' => url('/platform/api/users'),
                'institutionsUrl' => url('/platform/api/institutions'),
                'qualityUrl' => url('/platform/api/quality'),
                'assessmentsUrl' => url('/platform/api/assessments'),
                'universityReportsUrl' => url('/platform/api/university-reports'),
                'evaluationTeamsUrl' => url('/platform/api/evaluation-teams'),
                'externalEvaluationsUrl' => url('/platform/api/external-evaluations'),
                'improvementUrl' => url('/platform/api/improvement-actions'),
                'accreditationUrl' => url('/platform/api/accreditation-requests'),
                'reportsUrl' => url('/platform/api/reports'),
                'activityUrl' => url('/platform/api/activity'),
                'accountRequestsUrl' => url('/platform/api/account-requests'),
                'profileUrl' => url('/platform/api/profile'),
                'csrfToken' => csrf_token(),
            ],
        ]);
    }
}
