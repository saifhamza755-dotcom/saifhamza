<?php

namespace App\Http\Controllers;

use App\Models\AssessmentResponse;
use App\Models\AuditLog;
use App\Models\EvidenceFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EvidenceController extends Controller
{
    public function store(Request $request, AssessmentResponse $response): RedirectResponse
    {
        $response->load('assessment.cycle');
        $this->authorizeCollege($request, $response->assessment->college_id);
        abort_unless($response->assessment->isEditable(), 409, 'لا يمكن رفع دليل في الحالة الحالية.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ]);
        $file = $request->file('file');
        $path = $file->store("evidence/{$response->assessment->id}/{$response->id}", 'evidence');
        abort_unless($path, 500, 'تعذر حفظ الملف.');
        $evidence = EvidenceFile::create([
            'assessment_response_id' => $response->id, 'title' => $data['title'],
            'original_name' => $file->getClientOriginalName(), 'stored_path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()), 'status' => 'pending',
            'uploaded_by' => $request->user()->id,
        ]);
        $this->audit($request, 'evidence.uploaded', $evidence);

        return back()->with('success', 'تم رفع الدليل وحفظه بشكل خاص.');
    }

    public function download(Request $request, EvidenceFile $evidence): BinaryFileResponse
    {
        $evidence->load('response.assessment');
        $this->authorizeCollege($request, $evidence->response->assessment->college_id);
        abort_unless(Storage::disk('evidence')->exists($evidence->stored_path), 404);

        return response()->download(Storage::disk('evidence')->path($evidence->stored_path), $evidence->original_name);
    }

    public function destroy(Request $request, EvidenceFile $evidence): RedirectResponse
    {
        $evidence->load('response.assessment.cycle');
        $this->authorizeCollege($request, $evidence->response->assessment->college_id);
        abort_unless($evidence->response->assessment->isEditable(), 409, 'لا يمكن حذف الدليل في الحالة الحالية.');
        Storage::disk('evidence')->delete($evidence->stored_path);
        $this->audit($request, 'evidence.deleted', $evidence);
        $evidence->delete();

        return back()->with('success', 'تم حذف الدليل.');
    }

    public function review(Request $request, EvidenceFile $evidence): RedirectResponse
    {
        $evidence->load('response.assessment');
        $this->authorizeCollege($request, $evidence->response->assessment->college_id);
        abort_unless($evidence->response->assessment->status === 'submitted', 409);
        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'returned'])],
            'review_notes' => ['nullable', 'string', 'max:2000', Rule::requiredIf($request->input('status') === 'returned')],
        ]);
        $evidence->update([
            'status' => $data['status'], 'review_notes' => $data['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ]);
        $this->audit($request, 'evidence.'.$data['status'], $evidence);

        return back()->with('success', $data['status'] === 'accepted' ? 'تم قبول الدليل.' : 'تمت إعادة الدليل مع الملاحظة.');
    }

    private function authorizeCollege(Request $request, int $collegeId): void
    {
        abort_unless($request->user()->activeAssignments()->where('college_id', $collegeId)->exists(), 403);
    }

    private function audit(Request $request, string $action, EvidenceFile $evidence): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'target_type' => EvidenceFile::class, 'target_id' => $evidence->id, 'ip_address' => $request->ip()]);
    }
}
