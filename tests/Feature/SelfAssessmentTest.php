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
use Tests\TestCase;

class SelfAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_college_manager_can_start_assessment_only_for_assigned_open_cycle(): void
    {
        $cycle = $this->openCycle();
        $collegeUser = User::where('username', 'college')->firstOrFail();

        $this->actingAs($collegeUser)->post(route('assessments.start', $cycle))->assertRedirect();

        $assessment = SelfAssessment::firstOrFail();
        $this->assertSame($collegeUser->activeAssignments()->value('college_id'), $assessment->college_id);
        $this->assertCount(18, $assessment->responses);
    }

    public function test_scores_are_calculated_using_indicator_and_standard_weights(): void
    {
        $assessment = $this->startAssessment();
        $payload = [];
        foreach ($assessment->responses as $response) {
            $payload[$response->indicator_id] = ['score' => 3, 'notes' => 'ملاحظة موثقة'];
        }

        $this->actingAs(User::where('username', 'college')->firstOrFail())
            ->put(route('assessments.save', $assessment), ['responses' => $payload])->assertRedirect();

        $this->assertSame('75.00', $assessment->fresh()->total_score);
    }

    public function test_assessment_cannot_be_submitted_with_missing_scores(): void
    {
        $assessment = $this->startAssessment();

        $this->actingAs(User::where('username', 'college')->firstOrFail())
            ->post(route('assessments.submit', $assessment))->assertSessionHasErrors('responses');

        $this->assertSame('draft', $assessment->fresh()->status);
    }

    public function test_dean_can_return_then_approve_resubmitted_assessment(): void
    {
        $assessment = $this->startAssessment();
        foreach ($assessment->responses as $response) {
            $response->update(['score' => 4]);
            EvidenceFile::create([
                'assessment_response_id' => $response->id, 'title' => 'دليل', 'original_name' => 'evidence.pdf',
                'stored_path' => 'test/evidence.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 100,
                'sha256' => str_repeat('a', 64), 'status' => 'pending',
                'uploaded_by' => User::where('username', 'college')->value('id'),
            ]);
        }
        $collegeUser = User::where('username', 'college')->firstOrFail();
        $dean = User::where('username', 'dean')->firstOrFail();

        $this->actingAs($collegeUser)->post(route('assessments.submit', $assessment))->assertRedirect();
        $this->actingAs($dean)->post(route('assessment-reviews.decide', $assessment), [
            'decision' => 'returned', 'review_notes' => 'يرجى مراجعة التبريرات.',
        ])->assertRedirect();
        $this->assertSame('returned', $assessment->fresh()->status);

        $this->actingAs($collegeUser)->post(route('assessments.submit', $assessment))->assertRedirect();
        EvidenceFile::query()->update(['status' => 'accepted', 'reviewed_by' => $dean->id, 'reviewed_at' => now()]);
        $this->actingAs($dean)->post(route('assessment-reviews.decide', $assessment), [
            'decision' => 'approved',
        ])->assertRedirect();
        $this->assertSame('approved', $assessment->fresh()->status);
    }

    public function test_user_from_another_college_cannot_open_assessment(): void
    {
        $assessment = $this->startAssessment();
        $otherCollege = College::create([
            'university_id' => University::firstOrFail()->id, 'name_ar' => 'كلية أخرى', 'code' => 'OTHER', 'active' => true,
        ]);
        $otherUser = User::where('username', 'college')->firstOrFail();
        $otherUser->activeAssignments()->update(['college_id' => $otherCollege->id]);

        $this->actingAs($otherUser)->get(route('assessments.edit', $assessment))->assertForbidden();
    }

    private function openCycle(): EvaluationCycle
    {
        $university = University::where('code', 'SU')->firstOrFail();
        $college = College::where('code', 'ENG')->firstOrFail();
        $cycle = EvaluationCycle::create([
            'name' => 'دورة التقويم الذاتي', 'code' => 'SELF-2027', 'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31', 'status' => 'open',
            'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'),
            'created_by' => User::where('username', 'central')->value('id'),
        ]);
        $cycle->universities()->attach($university->id);
        $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);

        return $cycle;
    }

    private function startAssessment(): SelfAssessment
    {
        $this->actingAs(User::where('username', 'college')->firstOrFail())
            ->post(route('assessments.start', $this->openCycle()));

        return SelfAssessment::with('responses')->firstOrFail();
    }
}
