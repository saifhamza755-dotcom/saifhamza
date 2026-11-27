<?php

use App\Http\Controllers\AccreditationDecisionController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvaluationCycleController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\ExternalEvaluationController;
use App\Http\Controllers\ImprovementPlanController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlatformAccountRequestController;
use App\Http\Controllers\PlatformAccreditationController;
use App\Http\Controllers\PlatformActivityController;
use App\Http\Controllers\PlatformAssessmentController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PlatformDirectoryController;
use App\Http\Controllers\PlatformEvaluationTeamController;
use App\Http\Controllers\PlatformExternalEvaluationController;
use App\Http\Controllers\PlatformImprovementController;
use App\Http\Controllers\PlatformPasswordResetRequestController;
use App\Http\Controllers\PlatformProfileController;
use App\Http\Controllers\PlatformQualityController;
use App\Http\Controllers\PlatformReportController;
use App\Http\Controllers\PlatformSessionController;
use App\Http\Controllers\PlatformUniversityReportController;
use App\Http\Controllers\SelfAssessmentController;
use App\Http\Controllers\StandardController;
use App\Http\Controllers\UniversityReportController;
use App\Http\Controllers\UserController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', PlatformController::class)->name('platform');
Route::post('/platform/session', [PlatformSessionController::class, 'store'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('platform.session.store');
Route::delete('/platform/session', [PlatformSessionController::class, 'destroy'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('platform.session.destroy');
Route::get('/platform/logout', [PlatformSessionController::class, 'destroyPage'])->name('platform.session.destroy-page');

Route::middleware('auth')->prefix('platform/api')->name('platform.api.')->group(function () {
    Route::put('/profile', [PlatformProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [PlatformProfileController::class, 'updatePassword'])->name('profile.password');
    Route::put('/profile/assignment', [PlatformProfileController::class, 'selectAssignment'])->name('profile.assignment');
    Route::post('/account-requests', [PlatformAccountRequestController::class, 'store'])->withoutMiddleware('auth')->name('account-requests.store');
    Route::post('/password-reset-requests', [PlatformPasswordResetRequestController::class, 'store'])->withoutMiddleware('auth')->name('password-reset-requests.store');
    Route::get('/account-requests', [PlatformAccountRequestController::class, 'index'])->name('account-requests.index');
    Route::get('/password-reset-requests', [PlatformPasswordResetRequestController::class, 'index'])->name('password-reset-requests.index');
    Route::get('/directory', [PlatformDirectoryController::class, 'index'])->name('directory');
    Route::middleware('permission:users.manage')->group(function () {
        Route::post('/account-requests/{accountRequest}/approve', [PlatformAccountRequestController::class, 'approve'])->name('account-requests.approve');
        Route::post('/account-requests/{accountRequest}/reject', [PlatformAccountRequestController::class, 'reject'])->name('account-requests.reject');
        Route::post('/password-reset-requests/{passwordResetRequest}/process', [PlatformPasswordResetRequestController::class, 'process'])->name('password-reset-requests.process');
        Route::post('/password-reset-requests/{passwordResetRequest}/reject', [PlatformPasswordResetRequestController::class, 'reject'])->name('password-reset-requests.reject');
        Route::post('/users', [PlatformDirectoryController::class, 'storeUser'])->name('users.store');
        Route::put('/users/{user}', [PlatformDirectoryController::class, 'updateUser'])->name('users.update');
        Route::patch('/users/{user}/status', [PlatformDirectoryController::class, 'updateUserStatus'])->name('users.status');
        Route::patch('/users/{user}/password', [PlatformDirectoryController::class, 'resetPassword'])->name('users.password');
        Route::post('/users/{user}/assignments', [PlatformDirectoryController::class, 'storeAssignment'])->name('users.assignments.store');
        Route::patch('/users/{user}/assignments/{assignment}/status', [PlatformDirectoryController::class, 'updateAssignmentStatus'])->name('users.assignments.status');
    });
    Route::middleware('permission:institutions.manage')->group(function () {
        Route::post('/institutions', [PlatformDirectoryController::class, 'storeInstitution'])->name('institutions.store');
        Route::put('/institutions/{type}/{id}', [PlatformDirectoryController::class, 'updateInstitution'])->name('institutions.update');
    });
    Route::get('/quality', [PlatformQualityController::class, 'index'])->name('quality.index');
    Route::middleware('permission:standards.manage')->group(function () {
        Route::post('/quality/versions', [PlatformQualityController::class, 'storeVersion'])->name('quality.versions.store');
        Route::post('/quality/versions/{version}/standards', [PlatformQualityController::class, 'storeStandard'])->name('quality.standards.store');
        Route::put('/quality/standards/{standard}', [PlatformQualityController::class, 'updateStandard'])->name('quality.standards.update');
        Route::post('/quality/standards/{standard}/indicators', [PlatformQualityController::class, 'storeIndicator'])->name('quality.indicators.store');
        Route::put('/quality/indicators/{indicator}', [PlatformQualityController::class, 'updateIndicator'])->name('quality.indicators.update');
        Route::patch('/quality/versions/{version}/activate', [PlatformQualityController::class, 'activateVersion'])->name('quality.versions.activate');
    });
    Route::middleware('permission:cycles.manage')->group(function () {
        Route::post('/quality/cycles', [PlatformQualityController::class, 'storeCycle'])->name('quality.cycles.store');
        Route::patch('/quality/cycles/{cycle}/status', [PlatformQualityController::class, 'updateCycleStatus'])->name('quality.cycles.status');
    });
    Route::get('/assessments', [PlatformAssessmentController::class, 'index'])->name('assessments.index');
    Route::middleware('permission:self_assessment.manage')->group(function () {
        Route::post('/assessments/cycles/{cycle}/start', [PlatformAssessmentController::class, 'start'])->name('assessments.start');
        Route::put('/assessments/{assessment}', [PlatformAssessmentController::class, 'save'])->name('assessments.save');
        Route::post('/assessments/{assessment}/submit', [PlatformAssessmentController::class, 'submit'])->name('assessments.submit');
        Route::delete('/assessments/evidence/{evidence}', [PlatformAssessmentController::class, 'deleteEvidence'])->name('assessments.evidence.destroy');
    });
    Route::middleware('permission:evidence.manage')->post('/assessments/responses/{response}/evidence', [PlatformAssessmentController::class, 'uploadEvidence'])->name('assessments.evidence.store');
    Route::middleware('permission:college_reports.approve')->group(function () {
        Route::patch('/assessments/evidence/{evidence}/review', [PlatformAssessmentController::class, 'reviewEvidence'])->name('assessments.evidence.review');
        Route::post('/assessments/{assessment}/decision', [PlatformAssessmentController::class, 'decide'])->name('assessments.decision');
    });
    Route::get('/university-reports', [PlatformUniversityReportController::class, 'index'])->name('university-reports.index');
    Route::middleware('permission:university_reports.manage')->group(function () {
        Route::post('/university-reports/cycles/{cycle}/generate', [PlatformUniversityReportController::class, 'generate'])->name('university-reports.generate');
        Route::put('/university-reports/{report}', [PlatformUniversityReportController::class, 'save'])->name('university-reports.save');
        Route::post('/university-reports/{report}/submit', [PlatformUniversityReportController::class, 'submit'])->name('university-reports.submit');
        Route::post('/university-reports/{report}/decision', [PlatformUniversityReportController::class, 'decide'])->name('university-reports.decision');
    });
    Route::get('/evaluation-teams', [PlatformEvaluationTeamController::class, 'index'])->name('evaluation-teams.index');
    Route::get('/external-evaluations', [PlatformExternalEvaluationController::class, 'index'])->name('external-evaluations.index');
    Route::get('/improvement-actions', [PlatformImprovementController::class, 'index'])->name('improvement.index');
    Route::get('/accreditation-requests', [PlatformAccreditationController::class, 'index'])->name('accreditation.index');
    Route::get('/reports', [PlatformReportController::class, 'index'])->name('reports.index');
    Route::get('/activity/notifications', [PlatformActivityController::class, 'notifications'])->name('activity.notifications');
    Route::patch('/activity/notifications/read-all', [PlatformActivityController::class, 'readAll'])->name('activity.notifications.read-all');
    Route::middleware('permission:audit.view')->get('/activity/audit', [PlatformActivityController::class, 'audit'])->name('activity.audit');
    Route::middleware('permission:evaluation.manage')->group(function () {
        Route::post('/external-evaluations/reports/{report}/assign', [PlatformExternalEvaluationController::class, 'assign'])->name('external-evaluations.assign');
        Route::put('/external-evaluations/{evaluation}/scores', [PlatformExternalEvaluationController::class, 'saveScores'])->name('external-evaluations.scores');
        Route::put('/external-evaluations/{evaluation}/report', [PlatformExternalEvaluationController::class, 'saveReport'])->name('external-evaluations.report');
        Route::post('/external-evaluations/{evaluation}/submit', [PlatformExternalEvaluationController::class, 'submit'])->name('external-evaluations.submit');
        Route::post('/external-evaluations/{evaluation}/decision', [PlatformExternalEvaluationController::class, 'decide'])->name('external-evaluations.decide');
        Route::post('/evaluation-teams/profiles', [PlatformEvaluationTeamController::class, 'saveProfile'])->name('evaluation-teams.profiles.save');
        Route::put('/evaluation-teams/evaluators/{evaluator}/conflict', [PlatformEvaluationTeamController::class, 'saveConflict'])->name('evaluation-teams.conflicts.save');
        Route::post('/evaluation-teams', [PlatformEvaluationTeamController::class, 'saveTeam'])->name('evaluation-teams.store');
        Route::put('/evaluation-teams/{team}', [PlatformEvaluationTeamController::class, 'saveTeam'])->name('evaluation-teams.update');
        Route::delete('/evaluation-teams/{team}/members/{user}', [PlatformEvaluationTeamController::class, 'removeMember'])->name('evaluation-teams.members.destroy');
        Route::post('/evaluation-teams/{team}/visits', [PlatformEvaluationTeamController::class, 'saveVisit'])->name('evaluation-teams.visits.store');
        Route::put('/evaluation-teams/{team}/visits/{visit}', [PlatformEvaluationTeamController::class, 'saveVisit'])->name('evaluation-teams.visits.update');
    });
    Route::middleware('permission:improvement.manage')->group(function () {
        Route::post('/improvement-actions', [PlatformImprovementController::class, 'store'])->name('improvement.store');
        Route::put('/improvement-actions/{action}', [PlatformImprovementController::class, 'update'])->name('improvement.update');
    });
    Route::middleware('permission:accreditation.decide')->post('/accreditation-requests/{item}/decision', [PlatformAccreditationController::class, 'decide'])->name('accreditation.decide');
    Route::middleware('permission:university_reports.manage')->post('/accreditation-requests/evaluations/{evaluation}', [PlatformAccreditationController::class, 'submit'])->name('accreditation.submit');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{notification}', [NotificationController::class, 'read'])->name('notifications.read');

    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');
    });

    Route::middleware('permission:institutions.manage')->group(function () {
        Route::get('/institutions', [InstitutionController::class, 'index'])->name('institutions.index');
        Route::post('/institutions', [InstitutionController::class, 'store'])->name('institutions.store');
        Route::patch('/institutions/{type}/{id}/status', [InstitutionController::class, 'updateStatus'])->name('institutions.status');
    });

    Route::middleware('permission:standards.manage')->group(function () {
        Route::get('/standards', [StandardController::class, 'index'])->name('standards.index');
        Route::post('/standards/versions', [StandardController::class, 'storeVersion'])->name('standards.versions.store');
        Route::post('/standards/versions/{version}/standards', [StandardController::class, 'storeStandard'])->name('standards.store');
        Route::post('/standards/{standard}/indicators', [StandardController::class, 'storeIndicator'])->name('indicators.store');
        Route::patch('/standards/versions/{version}/activate', [StandardController::class, 'activate'])->name('standards.versions.activate');
    });

    Route::middleware('permission:cycles.manage')->group(function () {
        Route::get('/cycles', [EvaluationCycleController::class, 'index'])->name('cycles.index');
        Route::post('/cycles', [EvaluationCycleController::class, 'store'])->name('cycles.store');
        Route::patch('/cycles/{cycle}/status', [EvaluationCycleController::class, 'updateStatus'])->name('cycles.status');
    });

    Route::middleware('permission:self_assessment.manage')->group(function () {
        Route::get('/self-assessments', [SelfAssessmentController::class, 'index'])->name('assessments.index');
        Route::post('/self-assessments/cycles/{cycle}/start', [SelfAssessmentController::class, 'start'])->name('assessments.start');
        Route::get('/self-assessments/{assessment}', [SelfAssessmentController::class, 'edit'])->name('assessments.edit');
        Route::put('/self-assessments/{assessment}', [SelfAssessmentController::class, 'save'])->name('assessments.save');
        Route::post('/self-assessments/{assessment}/submit', [SelfAssessmentController::class, 'submit'])->name('assessments.submit');
        Route::post('/assessment-responses/{response}/evidence', [EvidenceController::class, 'store'])->name('evidence.store');
        Route::delete('/evidence/{evidence}', [EvidenceController::class, 'destroy'])->name('evidence.destroy');
    });

    Route::middleware('permission:evidence.manage')->get('/evidence/{evidence}/download', [EvidenceController::class, 'download'])->name('evidence.download');

    Route::middleware('permission:college_reports.approve')->group(function () {
        Route::get('/assessment-reviews', [SelfAssessmentController::class, 'reviewIndex'])->name('assessment-reviews.index');
        Route::get('/assessment-reviews/{assessment}', [SelfAssessmentController::class, 'review'])->name('assessment-reviews.show');
        Route::post('/assessment-reviews/{assessment}/decision', [SelfAssessmentController::class, 'decide'])->name('assessment-reviews.decide');
        Route::patch('/evidence/{evidence}/review', [EvidenceController::class, 'review'])->name('evidence.review');
    });

    Route::middleware('permission:university_reports.manage')->group(function () {
        Route::get('/university-reports', [UniversityReportController::class, 'index'])->name('university-reports.index');
        Route::post('/university-reports/cycles/{cycle}/generate', [UniversityReportController::class, 'generate'])->name('university-reports.generate');
        Route::get('/university-reports/{report}', [UniversityReportController::class, 'show'])->name('university-reports.show');
        Route::put('/university-reports/{report}', [UniversityReportController::class, 'save'])->name('university-reports.save');
        Route::post('/university-reports/{report}/submit', [UniversityReportController::class, 'submit'])->name('university-reports.submit');
        Route::post('/university-reports/{report}/decision', [UniversityReportController::class, 'decide'])->name('university-reports.decide');
    });

    Route::middleware('permission:evaluation.manage')->group(function () {
        Route::get('/external-evaluations', [ExternalEvaluationController::class, 'index'])->name('external-evaluations.index');
        Route::post('/external-evaluations/reports/{report}/assign', [ExternalEvaluationController::class, 'assign'])->name('external-evaluations.assign');
        Route::get('/external-evaluations/{evaluation}', [ExternalEvaluationController::class, 'show'])->name('external-evaluations.show');
        Route::put('/external-evaluations/{evaluation}', [ExternalEvaluationController::class, 'save'])->name('external-evaluations.save');
        Route::post('/external-evaluations/{evaluation}/submit', [ExternalEvaluationController::class, 'submit'])->name('external-evaluations.submit');
        Route::post('/external-evaluations/{evaluation}/decision', [ExternalEvaluationController::class, 'decide'])->name('external-evaluations.decide');
    });

    Route::middleware('permission:improvement.manage')->group(function () {
        Route::get('/improvement-plans', [ImprovementPlanController::class, 'index'])->name('improvement-plans.index');
        Route::post('/improvement-plans', [ImprovementPlanController::class, 'store'])->name('improvement-plans.store');
        Route::get('/improvement-plans/{plan}', [ImprovementPlanController::class, 'show'])->name('improvement-plans.show');
        Route::post('/improvement-plans/{plan}/actions', [ImprovementPlanController::class, 'addAction'])->name('improvement-actions.store');
        Route::patch('/improvement-plans/{plan}/activate', [ImprovementPlanController::class, 'activate'])->name('improvement-plans.activate');
        Route::patch('/improvement-actions/{action}', [ImprovementPlanController::class, 'updateAction'])->name('improvement-actions.update');
        Route::patch('/improvement-plans/{plan}/complete', [ImprovementPlanController::class, 'complete'])->name('improvement-plans.complete');
    });

    Route::middleware('permission:accreditation.decide')->group(function () {
        Route::get('/accreditation', [AccreditationDecisionController::class, 'index'])->name('accreditation.index');
        Route::post('/accreditation/evaluations/{evaluation}', [AccreditationDecisionController::class, 'store'])->name('accreditation.store');
        Route::get('/accreditation/{decision}', [AccreditationDecisionController::class, 'show'])->name('accreditation.show');
        Route::patch('/accreditation/{decision}/revoke', [AccreditationDecisionController::class, 'revoke'])->name('accreditation.revoke');
    });

    Route::middleware('permission:audit.view')->get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
});
