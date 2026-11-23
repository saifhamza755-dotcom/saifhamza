<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EvaluationCycle;
use App\Models\StandardsVersion;
use App\Models\University;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EvaluationCycleController extends Controller
{
    public function index(): View
    {
        return view('cycles.index', [
            'cycles' => EvaluationCycle::with(['standardsVersion', 'universities', 'colleges'])->latest()->get(),
            'versions' => StandardsVersion::where('status', 'active')->latest()->get(),
            'universities' => University::with(['colleges' => fn ($query) => $query->where('active', true)])
                ->where('active', true)->orderBy('name_ar')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:evaluation_cycles,code'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'standards_version_id' => ['required', 'integer', Rule::exists('standards_versions', 'id')->where('status', 'active')],
            'university_ids' => ['required', 'array', 'min:1'],
            'university_ids.*' => ['integer', Rule::exists('universities', 'id')->where('active', true)],
        ]);

        $universities = University::with(['colleges' => fn ($query) => $query->where('active', true)])
            ->whereIn('id', $data['university_ids'])->get();
        if ($universities->contains(fn ($university) => $university->colleges->isEmpty())) {
            throw ValidationException::withMessages(['university_ids' => 'كل جامعة مشاركة يجب أن تحتوي على كلية نشطة واحدة على الأقل.']);
        }

        $cycle = DB::transaction(function () use ($data, $universities, $request) {
            $cycle = EvaluationCycle::create([
                'name' => $data['name'], 'code' => $data['code'], 'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'], 'status' => 'draft',
                'standards_version_id' => $data['standards_version_id'], 'created_by' => $request->user()->id,
            ]);
            $cycle->universities()->attach($universities->pluck('id'));
            foreach ($universities as $university) {
                foreach ($university->colleges as $college) {
                    $cycle->colleges()->attach($college->id, ['university_id' => $university->id]);
                }
            }
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'cycles.created',
                'target_type' => EvaluationCycle::class, 'target_id' => $cycle->id,
                'metadata' => ['universities' => $universities->pluck('id')->all()], 'ip_address' => $request->ip(),
            ]);

            return $cycle;
        });

        return back()->with('success', "تم إنشاء دورة {$cycle->name} كمسودة مع تثبيت الكليات المشاركة.");
    }

    public function updateStatus(Request $request, EvaluationCycle $cycle): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'closed'])]]);
        $allowed = ($cycle->status === 'draft' && $data['status'] === 'open')
            || ($cycle->status === 'open' && $data['status'] === 'closed');
        if (! $allowed) {
            throw ValidationException::withMessages(['status' => 'انتقال حالة الدورة غير مسموح.']);
        }
        if ($data['status'] === 'open' && (! $cycle->universities()->exists() || ! $cycle->colleges()->exists())) {
            throw ValidationException::withMessages(['status' => 'لا يمكن فتح دورة بلا جامعات وكليات مشاركة.']);
        }

        $cycle->update(['status' => $data['status']]);
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => "cycles.{$data['status']}",
            'target_type' => EvaluationCycle::class, 'target_id' => $cycle->id, 'ip_address' => $request->ip(),
        ]);

        return back()->with('success', $data['status'] === 'open' ? 'تم فتح دورة التقييم.' : 'تم إغلاق دورة التقييم وأصبحت للقراءة فقط.');
    }
}
