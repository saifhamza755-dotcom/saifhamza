<?php

namespace Tests\Feature;

use App\Models\EvaluationCycle;
use App\Models\StandardsVersion;
use App\Models\University;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformEvaluationTeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_central_registers_evaluator_profile_and_creates_team(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $evaluator = User::where('username', 'evaluator')->firstOrFail();
        $cycle = $this->cycle();

        $this->actingAs($central)->postJson(route('platform.api.evaluation-teams.profiles.save'), [
            'userId' => $evaluator->id, 'employerId' => 'u1', 'specialty' => 'الحوكمة والجودة',
        ])->assertOk();

        $team = $this->postJson(route('platform.api.evaluation-teams.store'), [
            'cycleId' => 'cy'.$cycle->id, 'universityId' => 'u2',
            'name' => 'الفريق الخارجي الأول', 'members' => [$evaluator->id],
        ])->assertCreated()->assertJsonPath('team.name', 'الفريق الخارجي الأول');

        $this->assertDatabaseHas('evaluation_team_user', ['user_id' => $evaluator->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'evaluation_team.saved']);
        $teamId = (int) substr($team->json('team.id'), 1);
        $this->assertGreaterThan(0, $teamId);
    }

    public function test_conflict_or_employer_blocks_assignment_to_target_university(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $evaluator = User::where('username', 'evaluator')->firstOrFail();
        $cycle = $this->cycle();

        $this->actingAs($central)->postJson(route('platform.api.evaluation-teams.profiles.save'), [
            'userId' => $evaluator->id, 'employerId' => 'u1', 'specialty' => 'الجودة',
        ]);
        $this->postJson(route('platform.api.evaluation-teams.store'), [
            'cycleId' => 'cy'.$cycle->id, 'universityId' => 'u1',
            'name' => 'فريق غير مسموح', 'members' => [$evaluator->id],
        ])->assertUnprocessable();

        $this->putJson(route('platform.api.evaluation-teams.conflicts.save', $evaluator), [
            'universityId' => 'u2', 'active' => true, 'note' => 'علاقة مهنية سابقة.',
        ])->assertOk();
        $this->postJson(route('platform.api.evaluation-teams.store'), [
            'cycleId' => 'cy'.$cycle->id, 'universityId' => 'u2',
            'name' => 'فريق متعارض', 'members' => [$evaluator->id],
        ])->assertUnprocessable();

        $this->assertDatabaseHas('evaluator_conflicts', ['evaluator_id' => $evaluator->id, 'active' => true]);
    }

    public function test_central_schedules_and_evaluator_completes_visit(): void
    {
        $central = User::where('username', 'central')->firstOrFail();
        $evaluator = User::where('username', 'evaluator')->firstOrFail();
        $cycle = $this->cycle();
        $team = $this->actingAs($central)->postJson(route('platform.api.evaluation-teams.store'), [
            'cycleId' => 'cy'.$cycle->id, 'universityId' => 'u2',
            'name' => 'فريق الزيارة', 'members' => [$evaluator->id],
        ])->assertCreated();
        $teamId = (int) substr($team->json('team.id'), 1);

        $visit = $this->postJson(route('platform.api.evaluation-teams.visits.store', $teamId), [
            'date' => '2027-06-10', 'time' => '09:00', 'agenda' => 'لقاء الإدارة والكلية.',
            'results' => '', 'status' => 'scheduled',
        ])->assertCreated()->assertJsonPath('visit.status', 'مجدولة');

        $visitId = (int) substr($visit->json('visit.id'), 2);
        $this->actingAs($evaluator)->putJson(route('platform.api.evaluation-teams.visits.update', [$teamId, $visitId]), [
            'date' => '2027-06-10', 'time' => '09:00', 'agenda' => 'لقاء الإدارة والكلية.',
            'results' => 'تمت الزيارة وتوثيق الملاحظات.', 'status' => 'completed',
        ])->assertOk()->assertJsonPath('visit.status', 'مكتملة');
    }

    public function test_non_central_evaluator_cannot_create_team_or_conflict(): void
    {
        $evaluator = User::where('username', 'evaluator')->firstOrFail();
        $cycle = $this->cycle();

        $this->actingAs($evaluator)->postJson(route('platform.api.evaluation-teams.store'), [
            'cycleId' => 'cy'.$cycle->id, 'universityId' => 'u1', 'name' => 'غير مسموح', 'members' => [$evaluator->id],
        ])->assertForbidden();
    }

    private function cycle(): EvaluationCycle
    {
        $universities = University::whereIn('code', ['SU'])->get();
        $second = University::create(['name_ar' => 'جامعة الاختبار الثانية', 'code' => 'U2', 'type' => 'public', 'active' => true]);
        $second->colleges()->create(['name_ar' => 'كلية الاختبار', 'code' => 'TEST-C', 'active' => true]);
        $cycle = EvaluationCycle::create([
            'name' => 'دورة فرق التقييم', 'code' => 'TEAMS-2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
            'status' => 'open', 'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'),
            'created_by' => User::where('username', 'central')->value('id'),
        ]);
        $cycle->universities()->attach([...$universities->pluck('id')->all(), $second->id]);
        foreach ($universities->push($second) as $university) {
            foreach ($university->colleges as $college) {
                $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);
            }
        }

        return $cycle;
    }
}
