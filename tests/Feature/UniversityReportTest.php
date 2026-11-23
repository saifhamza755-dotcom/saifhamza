<?php

namespace Tests\Feature;

use App\Models\AssessmentResponse;
use App\Models\College;
use App\Models\EvaluationCycle;
use App\Models\SelfAssessment;
use App\Models\StandardsVersion;
use App\Models\University;
use App\Models\UniversityReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversityReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_cannot_be_generated_until_all_colleges_are_approved(): void
    {
        $cycle = $this->cycle();
        $this->approvedAssessment($cycle, College::where('code', 'ENG')->firstOrFail(), 80);

        $this->actingAs(User::where('username', 'university')->firstOrFail())
            ->post(route('university-reports.generate', $cycle))->assertSessionHasErrors('coverage');

        $this->assertDatabaseCount('university_reports', 0);
    }

    public function test_university_generates_weighted_report_from_all_approved_colleges(): void
    {
        $cycle = $this->completeCycle();

        $this->actingAs(User::where('username', 'university')->firstOrFail())
            ->post(route('university-reports.generate', $cycle))->assertRedirect();

        $report = UniversityReport::firstOrFail();
        $this->assertSame('80.00', $report->overall_score);
        $this->assertSame(3, $report->college_count);
        $this->assertCount(9, $report->standards_snapshot);
    }

    public function test_report_follows_submit_return_resubmit_approve_workflow(): void
    {
        $cycle = $this->completeCycle();
        $universityUser = User::where('username', 'university')->firstOrFail();
        $central = User::where('username', 'central')->firstOrFail();
        $this->actingAs($universityUser)->post(route('university-reports.generate', $cycle));
        $report = UniversityReport::firstOrFail();

        $this->put(route('university-reports.save', $report), [
            'executive_summary' => 'ملخص نتائج الجامعة.', 'recommendations' => 'تطوير خطط التحسين.',
        ])->assertRedirect();
        $this->post(route('university-reports.submit', $report))->assertRedirect();
        $this->assertSame('submitted', $report->fresh()->status);

        $this->actingAs($central)->post(route('university-reports.decide', $report), [
            'decision' => 'returned', 'review_notes' => 'توسيع التوصيات.',
        ])->assertRedirect();
        $this->assertSame('returned', $report->fresh()->status);

        $this->actingAs($universityUser)->post(route('university-reports.submit', $report))->assertRedirect();
        $this->actingAs($central)->post(route('university-reports.decide', $report), ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $report->fresh()->status);
    }

    public function test_university_cannot_open_another_university_report(): void
    {
        $cycle = $this->completeCycle();
        $other = University::create(['name_ar' => 'جامعة أخرى', 'code' => 'OTHER-U', 'type' => 'private', 'active' => true]);
        $report = UniversityReport::create([
            'evaluation_cycle_id' => $cycle->id, 'university_id' => $other->id, 'status' => 'draft',
            'overall_score' => 70, 'college_count' => 1, 'standards_snapshot' => [],
            'prepared_by' => User::where('username', 'central')->value('id'),
        ]);

        $this->actingAs(User::where('username', 'university')->firstOrFail())
            ->get(route('university-reports.show', $report))->assertForbidden();
    }

    private function cycle(): EvaluationCycle
    {
        $university = University::where('code', 'SU')->firstOrFail();
        $cycle = EvaluationCycle::create([
            'name' => 'دورة التقرير الجامعي', 'code' => 'UNIV-REPORT', 'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31', 'status' => 'open',
            'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'),
            'created_by' => User::where('username', 'central')->value('id'),
        ]);
        $cycle->universities()->attach($university->id);
        foreach ($university->colleges as $college) {
            $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);
        }

        return $cycle;
    }

    private function completeCycle(): EvaluationCycle
    {
        $cycle = $this->cycle();
        $scores = [70, 80, 90];
        foreach (University::where('code', 'SU')->firstOrFail()->colleges as $index => $college) {
            $this->approvedAssessment($cycle, $college, $scores[$index]);
        }

        return $cycle;
    }

    private function approvedAssessment(EvaluationCycle $cycle, College $college, int $score): SelfAssessment
    {
        $assessment = SelfAssessment::create([
            'evaluation_cycle_id' => $cycle->id, 'university_id' => $college->university_id,
            'college_id' => $college->id, 'status' => 'approved', 'total_score' => $score,
            'created_by' => User::where('username', 'college')->value('id'),
        ]);
        $indicators = $cycle->standardsVersion->standards()->with('indicators')->get()->flatMap->indicators;
        foreach ($indicators as $indicator) {
            AssessmentResponse::create(['self_assessment_id' => $assessment->id, 'indicator_id' => $indicator->id, 'score' => $score / 25]);
        }

        return $assessment;
    }
}
