<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EvaluationCycle;
use App\Models\EvaluationTeam;
use App\Models\EvaluationVisit;
use App\Models\EvaluatorConflict;
use App\Models\EvaluatorProfile;
use App\Models\University;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformEvaluationTeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $central = $request->user()->hasRole('central');
        $profiles = EvaluatorProfile::with(['user', 'employer'])->get();
        $conflicts = EvaluatorConflict::with(['evaluator', 'university'])->get();
        $teams = EvaluationTeam::with(['members', 'visits', 'cycle', 'university']);
        if (! $central) {
            abort_unless($request->user()->hasRole('evaluator'), 403);
            $profiles = $profiles->where('user_id', $request->user()->id);
            $conflicts = $conflicts->where('evaluator_id', $request->user()->id);
            $teams->whereHas('members', fn ($query) => $query->whereKey($request->user()->id));
        }

        $teamRows = $teams->get();

        return response()->json([
            'evaluators' => $profiles->map(fn ($profile) => [
                'userId' => $profile->user_id, 'employerId' => $profile->employer_university_id ? 'u'.$profile->employer_university_id : '',
                'specialty' => $profile->specialty ?? '',
            ])->values(),
            'conflicts' => $conflicts->map(fn ($conflict) => [
                'id' => $conflict->id, 'userId' => $conflict->evaluator_id, 'universityId' => 'u'.$conflict->university_id,
                'active' => $conflict->active, 'note' => $conflict->note,
            ])->values(),
            'teams' => $teamRows->map(fn (EvaluationTeam $team) => $this->teamData($team))->values(),
            'visits' => $teamRows->flatMap(fn (EvaluationTeam $team) => $team->visits->map(fn (EvaluationVisit $visit) => $this->visitData($visit, $team)))->values(),
        ]);
    }

    public function saveProfile(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('central'), 403);
        $data = $request->validate([
            'userId' => ['required', 'integer', Rule::exists('users', 'id')],
            'employerId' => ['required', 'string'], 'specialty' => ['nullable', 'string', 'max:255'],
        ]);
        $user = User::findOrFail($data['userId']);
        abort_unless($user->active && $user->hasRole('evaluator'), 422, 'الحساب ليس مقيمًا نشطًا.');
        $employerId = $this->numericId($data['employerId'], 'u');
        University::findOrFail($employerId);
        if (EvaluationTeam::where('university_id', $employerId)->whereHas('members', fn ($query) => $query->whereKey($user->id))->exists()) {
            throw ValidationException::withMessages(['employerId' => 'جهة العمل تتعارض مع تكليف قائم.']);
        }
        $profile = EvaluatorProfile::updateOrCreate(['user_id' => $user->id], [
            'employer_university_id' => $employerId, 'specialty' => $data['specialty'] ?? null,
        ]);
        $this->audit($request, 'evaluators.profile.saved', $profile);

        return response()->json(['message' => 'تم حفظ ملف المقيم.']);
    }

    public function saveConflict(Request $request, User $evaluator): JsonResponse
    {
        abort_unless($request->user()->hasRole('central'), 403);
        abort_unless($evaluator->hasRole('evaluator'), 422, 'الحساب ليس مقيمًا.');
        $data = $request->validate([
            'universityId' => ['required', 'string'], 'active' => ['required', 'boolean'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $universityId = $this->numericId($data['universityId'], 'u');
        University::findOrFail($universityId);
        if ($data['active'] && EvaluationTeam::where('university_id', $universityId)->whereHas('members', fn ($query) => $query->whereKey($evaluator->id))->exists()) {
            throw ValidationException::withMessages(['active' => 'استبعد المقيم من الفريق قبل تسجيل التعارض.']);
        }
        $conflict = EvaluatorConflict::updateOrCreate(
            ['evaluator_id' => $evaluator->id, 'university_id' => $universityId],
            ['active' => $data['active'], 'note' => $data['note'], 'recorded_by' => $request->user()->id]
        );
        $this->audit($request, 'evaluators.conflict.saved', $conflict);

        return response()->json(['message' => 'تم حفظ حالة تعارض المصالح.']);
    }

    public function saveTeam(Request $request, ?EvaluationTeam $team = null): JsonResponse
    {
        abort_unless($request->user()->hasRole('central'), 403);
        $data = $request->validate([
            'cycleId' => ['required', 'string'], 'universityId' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'], 'members' => ['required', 'array', 'min:1'],
            'members.*' => ['integer', Rule::exists('users', 'id')],
        ]);
        $cycleId = $this->numericId($data['cycleId'], 'cy');
        $universityId = $this->numericId($data['universityId'], 'u');
        $cycle = EvaluationCycle::findOrFail($cycleId);
        abort_unless($cycle->universities()->whereKey($universityId)->exists(), 422, 'الجامعة لا تشارك في الدورة.');
        if ($team) {
            abort_unless($team->evaluation_cycle_id === $cycleId && $team->university_id === $universityId, 422);
        } elseif (EvaluationTeam::where('evaluation_cycle_id', $cycleId)->where('university_id', $universityId)->exists()) {
            throw ValidationException::withMessages(['team' => 'يوجد فريق لهذه الجامعة والدورة.']);
        }
        $memberIds = collect($data['members'])->unique()->values();
        $valid = User::whereIn('id', $memberIds)->where('active', true)
            ->whereHas('activeAssignments.role', fn ($query) => $query->where('key', 'evaluator'))
            ->whereHas('activeAssignments')->pluck('id');
        if ($valid->count() !== $memberIds->count()) {
            throw ValidationException::withMessages(['members' => 'جميع الأعضاء يجب أن يكونوا مقيمين نشطين.']);
        }
        foreach ($memberIds as $memberId) {
            if ($this->hasConflict($memberId, $universityId)) {
                throw ValidationException::withMessages(['members' => 'أحد المقيمين لديه تعارض مصالح مع الجامعة.']);
            }
        }
        $team = DB::transaction(function () use ($team, $cycleId, $universityId, $data, $memberIds, $request) {
            $team ??= EvaluationTeam::create([
                'evaluation_cycle_id' => $cycleId, 'university_id' => $universityId,
                'name' => $data['name'], 'created_by' => $request->user()->id,
            ]);
            $team->update(['name' => $data['name']]);
            $team->members()->sync($memberIds);
            $this->audit($request, 'evaluation_team.saved', $team);

            return $team;
        });

        return response()->json(['team' => $this->teamData($team->load(['members', 'cycle', 'university']))], $team->wasRecentlyCreated ? 201 : 200);
    }

    public function removeMember(Request $request, EvaluationTeam $team, User $user): JsonResponse
    {
        abort_unless($request->user()->hasRole('central'), 403);
        abort_unless($team->members()->whereKey($user->id)->exists(), 404);
        $team->members()->detach($user->id);
        $this->audit($request, 'evaluation_team.member_removed', $team);

        return response()->json(['message' => 'تم استبعاد عضو الفريق.']);
    }

    public function saveVisit(Request $request, EvaluationTeam $team, ?EvaluationVisit $visit = null): JsonResponse
    {
        $isCentral = $request->user()->hasRole('central');
        if (! $isCentral) {
            abort_unless($request->user()->hasRole('evaluator') && $team->members()->whereKey($request->user()->id)->exists(), 403);
            abort_unless($visit && $visit->evaluation_team_id === $team->id, 403, 'جدولة الزيارة من اختصاص الإدارة المركزية.');
        }
        if ($visit) {
            abort_unless($visit->evaluation_team_id === $team->id, 404);
        }
        $data = $request->validate([
            'date' => ['required', 'date'], 'time' => ['required', 'date_format:H:i'],
            'agenda' => ['required', 'string', 'max:5000'], 'results' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(['scheduled', 'completed', 'cancelled'])],
        ]);
        $team->load('cycle');
        if ($data['date'] < $team->cycle->starts_on->toDateString() || $data['date'] > $team->cycle->ends_on->toDateString()) {
            throw ValidationException::withMessages(['date' => 'يجب أن يقع موعد الزيارة ضمن فترة الدورة.']);
        }
        if ($data['status'] === 'completed' && blank($data['results'] ?? null)) {
            throw ValidationException::withMessages(['results' => 'يجب توثيق نتائج الزيارة قبل إكمالها.']);
        }
        $visit = $visit
            ? tap($visit)->update(['visit_date' => $data['date'], 'visit_time' => $data['time'], 'agenda' => $data['agenda'], 'results' => $data['results'] ?? null, 'status' => $data['status'], 'updated_by' => $request->user()->id])
            : $team->visits()->create(['visit_date' => $data['date'], 'visit_time' => $data['time'], 'agenda' => $data['agenda'], 'results' => $data['results'] ?? null, 'status' => $data['status'], 'updated_by' => $request->user()->id]);
        $this->audit($request, 'evaluation_visit.saved', $visit);

        return response()->json(['visit' => $this->visitData($visit, $team)], $visit->wasRecentlyCreated ? 201 : 200);
    }

    private function hasConflict(int $evaluatorId, int $universityId): bool
    {
        return EvaluatorProfile::where('user_id', $evaluatorId)->where('employer_university_id', $universityId)->exists()
            || EvaluatorConflict::where('evaluator_id', $evaluatorId)->where('university_id', $universityId)->where('active', true)->exists();
    }

    private function teamData(EvaluationTeam $team): array
    {
        return ['id' => 't'.$team->id, 'cycleId' => 'cy'.$team->evaluation_cycle_id, 'universityId' => 'u'.$team->university_id, 'name' => $team->name, 'members' => $team->members->pluck('id')->values()];
    }

    private function visitData(EvaluationVisit $visit, EvaluationTeam $team): array
    {
        $statuses = ['scheduled' => 'مجدولة', 'completed' => 'مكتملة', 'cancelled' => 'ملغاة'];

        return ['id' => 'vs'.$visit->id, 'teamId' => 't'.$team->id, 'cycleId' => 'cy'.$team->evaluation_cycle_id, 'universityId' => 'u'.$team->university_id, 'date' => $visit->visit_date->toDateString(), 'time' => substr((string) $visit->visit_time, 0, 5), 'agenda' => $visit->agenda, 'results' => $visit->results ?? '', 'status' => $statuses[$visit->status]];
    }

    private function numericId(string $value, string $prefix): int
    {
        abort_unless(str_starts_with($value, $prefix) && ctype_digit(substr($value, strlen($prefix))), 422, 'المعرف غير صحيح.');

        return (int) substr($value, strlen($prefix));
    }

    private function audit(Request $request, string $action, $target): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'target_type' => $target::class, 'target_id' => $target->getKey(), 'ip_address' => $request->ip()]);
    }
}
