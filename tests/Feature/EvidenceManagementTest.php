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

class EvidenceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('evidence');
    }

    public function test_college_manager_can_upload_and_delete_private_evidence(): void
    {
        $assessment = $this->assessment();
        $response = $assessment->responses->first();
        $collegeUser = User::where('username', 'college')->firstOrFail();

        $this->actingAs($collegeUser)->post(route('evidence.store', $response), [
            'title' => 'خطة الكلية', 'file' => UploadedFile::fake()->create('plan.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $evidence = EvidenceFile::firstOrFail();
        Storage::disk('evidence')->assertExists($evidence->stored_path);
        $this->assertSame('pending', $evidence->status);

        $this->delete(route('evidence.destroy', $evidence))->assertRedirect();
        Storage::disk('evidence')->assertMissing($evidence->stored_path);
        $this->assertDatabaseMissing('evidence_files', ['id' => $evidence->id]);
    }

    public function test_invalid_file_type_is_rejected(): void
    {
        $response = $this->assessment()->responses->first();

        $this->actingAs(User::where('username', 'college')->firstOrFail())->post(route('evidence.store', $response), [
            'title' => 'ملف تنفيذي', 'file' => UploadedFile::fake()->create('unsafe.exe', 10, 'application/octet-stream'),
        ])->assertSessionHasErrors('file');
    }

    public function test_dean_can_review_evidence_but_other_college_cannot_download_it(): void
    {
        $assessment = $this->assessment();
        $response = $assessment->responses->first();
        $collegeUser = User::where('username', 'college')->firstOrFail();
        $this->actingAs($collegeUser)->post(route('evidence.store', $response), [
            'title' => 'دليل المؤشر', 'file' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ]);
        $evidence = EvidenceFile::firstOrFail();
        $assessment->update(['status' => 'submitted']);

        $dean = User::where('username', 'dean')->firstOrFail();
        $this->actingAs($dean)->patch(route('evidence.review', $evidence), ['status' => 'accepted'])->assertRedirect();
        $this->assertSame('accepted', $evidence->fresh()->status);

        $otherCollege = College::create(['university_id' => University::first()->id, 'name_ar' => 'كلية أخرى', 'code' => 'OTH', 'active' => true]);
        $dean->activeAssignments()->update(['college_id' => $otherCollege->id]);
        $this->actingAs($dean)->get(route('evidence.download', $evidence))->assertForbidden();
    }

    private function assessment(): SelfAssessment
    {
        $university = University::where('code', 'SU')->firstOrFail();
        $college = College::where('code', 'ENG')->firstOrFail();
        $cycle = EvaluationCycle::create([
            'name' => 'دورة الأدلة', 'code' => 'EVIDENCE-1', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
            'status' => 'open', 'standards_version_id' => StandardsVersion::where('status', 'active')->value('id'),
            'created_by' => User::where('username', 'central')->value('id'),
        ]);
        $cycle->universities()->attach($university->id);
        $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);
        $this->actingAs(User::where('username', 'college')->firstOrFail())->post(route('assessments.start', $cycle));

        return SelfAssessment::with('responses')->firstOrFail();
    }
}
