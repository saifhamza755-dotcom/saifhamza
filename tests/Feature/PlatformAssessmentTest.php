<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\EvaluationCycle;
use App\Models\EvidenceFile;
use App\Models\SelfAssessment;
use App\Models\StandardsVersion;
use App\Models\University;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_college_starts_and_saves_zero_to_four_assessment_from_original_interface(): void
    {
        $college = User::where('username', 'college')->firstOrFail();
        $cycle = $this->openCycle();

        $started = $this->actingAs($college)
            ->postJson(route('platform.api.assessments.start', $cycle))
            ->assertCreated()
            ->assertJsonPath('assessment.status', 'مسودة');

        $assessmentId = $started->json('assessment.assessmentId');
        $indicatorIds = array_keys($started->json('assessment.responses'));
        $scores = array_fill_keys($indicatorIds, 4);
        $comments = array_fill_keys($indicatorIds, 'ملاحظة موثقة');

        $this->putJson(route('platform.api.assessments.save', $assessmentId), [
            'scores' => $scores, 'comments' => $comments, 'report_notes' => 'تقرير الكلية',
        ])->assertOk()->assertJsonPath('assessment.totalScore', 100);

        $invalid = $scores;
        $invalid[$indicatorIds[0]] = 5;
        $this->putJson(route('platform.api.assessments.save', $assessmentId), [
            'scores' => $invalid, 'comments' => $comments,
        ])->assertUnprocessable();
    }

    public function test_evidence_is_privately_uploaded_and_returned_to_interface(): void
    {
        Storage::fake('local');
        $college = User::where('username', 'college')->firstOrFail();
        $assessment = $this->startAssessment($college);
        $response = $assessment->responses()->firstOrFail();

        $uploaded = $this->actingAs($college)->post(route('platform.api.assessments.evidence.store', $response), [
            'title' => 'دليل المؤشر', 'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('evidence.status', 'قيد المراجعة')
            ->assertJsonPath('evidence.indicatorId', 'i'.$response->indicator_id);

        $evidence = EvidenceFile::findOrFail((int) substr($uploaded->json('evidence.id'), 2));
        Storage::disk('local')->assertExists($evidence->stored_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'evidence.uploaded']);
    }

    public function test_college_submits_and_dean_reviews_evidence_then_approves_report(): void
    {
        $college = User::where('username', 'college')->firstOrFail();
        $dean = User::where('username', 'dean')->firstOrFail();
        $assessment = $this->startAssessment($college);

        foreach ($assessment->responses as $response) {
            $response->update(['score' => 4, 'notes' => 'مكتمل']);
            EvidenceFile::create([
                'assessment_response_id' => $response->id, 'title' => 'دليل', 'original_name' => 'evidence.pdf',
                'stored_path' => 'private/evidence.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 100,
                'sha256' => str_repeat('a', 64), 'status' => 'pending', 'uploaded_by' => $college->id,
            ]);
        }

        $this->actingAs($college)
            ->postJson(route('platform.api.assessments.submit', $assessment))
            ->assertOk()->assertJsonPath('assessment.status', 'بانتظار مراجعة العميد');

        foreach (EvidenceFile::all() as $evidence) {
            $this->actingAs($dean)->patchJson(route('platform.api.assessments.evidence.review', $evidence), [
                'status' => 'accepted', 'review_notes' => '',
            ])->assertOk();
        }

        $this->postJson(route('platform.api.assessments.decision', $assessment), [
            'decision' => 'approved', 'review_notes' => '',
        ])->assertOk()->assertJsonPath('assessment.status', 'معتمد');

        $this->assertDatabaseHas('self_assessments', ['id' => $assessment->id, 'status' => 'approved']);
    }

    public function test_users_cannot_cross_college_or_role_boundaries(): void
    {
        $college = User::where('username', 'college')->firstOrFail();
        $assessment = $this->startAssessment($college);

        $this->actingAs($college)
            ->postJson(route('platform.api.assessments.decision', $assessment), ['decision' => 'approved'])
            ->assertForbidden();

        $this->actingAs(User::where('username', 'dean')->firstOrFail())
            ->putJson(route('platform.api.assessments.save', $assessment), ['scores' => []])
            ->assertForbidden();
    }

    private function openCycle(): EvaluationCycle
    {
        $university = University::where('code', 'SU')->firstOrFail();
        $college = College::where('code', 'ENG')->firstOrFail();
        $cycle = EvaluationCycle::create([
            'name' => 'دورة التقويم من الواجهة الأصلية', 'code' => 'SPA-SELF-2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'status' => 'open',
            'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'),
            'created_by' => User::where('username', 'central')->value('id'),
        ]);
        $cycle->universities()->attach($university->id);
        $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);

        return $cycle;
    }

    private function startAssessment(User $college): SelfAssessment
    {
        $this->actingAs($college)->postJson(route('platform.api.assessments.start', $this->openCycle()));

        return SelfAssessment::with('responses')->firstOrFail();
    }
}
