@extends('layouts.app')
@section('title', 'نموذج التقويم الذاتي')
@section('page-title', 'نموذج التقويم الذاتي')

@section('page-actions')<a class="secondary-button" href="{{ route('assessments.index') }}">العودة للدورات</a>@endsection

@section('content')
@php($editable = $assessment->isEditable())
<section class="assessment-summary">
    <div><small>الكلية</small><strong>{{ $assessment->college->name_ar }}</strong></div>
    <div><small>الدورة</small><strong>{{ $assessment->cycle->name }}</strong></div>
    <div><small>الحالة</small><strong>{{ ['draft'=>'مسودة','submitted'=>'قيد المراجعة','returned'=>'معاد للتعديل','approved'=>'معتمد'][$assessment->status] }}</strong></div>
    <div class="score-box"><small>النتيجة الكلية</small><strong>{{ $assessment->total_score }}%</strong></div>
</section>

@if($assessment->status === 'returned')<div class="flash error"><strong>ملاحظات المراجع:</strong> {{ $assessment->review_notes }}</div>@endif

<form method="post" action="{{ route('assessments.save', $assessment) }}">
    @csrf @method('PUT')
    @foreach($assessment->cycle->standardsVersion->standards as $standard)
        <section class="panel standard-assessment">
            <div class="section-head"><div><span class="standard-number">{{ $standard->code }}</span><h2>{{ $standard->name_ar }}</h2><small>وزن المعيار: {{ $standard->weight }}%</small></div><div class="standard-score"><small>نتيجة المعيار</small><strong>{{ number_format($standardScores[$standard->id], 2) }}%</strong></div></div>
            <div class="response-list">
            @foreach($standard->indicators as $indicator)
                @php($response = $responses->get($indicator->id))
                <article class="response-row">
                    <div class="indicator-info"><b>{{ $indicator->code }}</b><strong>{{ $indicator->name_ar }}</strong><small>وزن المؤشر {{ $indicator->weight }}% @if($indicator->evidence_required) · يتطلب دليلاً @endif</small></div>
                    <div class="field score-field"><label>الدرجة من 100</label><input type="number" min="0" max="100" step="0.01" name="responses[{{ $indicator->id }}][score]" value="{{ old("responses.{$indicator->id}.score", $response?->score) }}" @disabled(!$editable)></div>
                    <div class="field notes-field"><label>الملاحظات والتبرير</label><textarea rows="2" name="responses[{{ $indicator->id }}][notes]" @disabled(!$editable)>{{ old("responses.{$indicator->id}.notes", $response?->notes) }}</textarea></div>
                    <div class="evidence-box">
                        <div class="evidence-head"><strong>الأدلة المرفقة</strong><small>PDF، Word، Excel أو صورة · بحد أقصى 10MB</small></div>
                        @forelse($response?->evidenceFiles ?? [] as $evidence)
                            <div class="evidence-item"><div><span class="file-icon">▤</span><a href="{{ route('evidence.download',$evidence) }}">{{ $evidence->title }}</a><small>{{ $evidence->original_name }} · {{ number_format($evidence->size_bytes/1024,1) }} KB</small></div><span class="status {{ $evidence->status === 'accepted' ? 'active' : ($evidence->status === 'returned' ? 'inactive' : 'draft') }}">{{ ['pending'=>'بانتظار المراجعة','accepted'=>'مقبول','returned'=>'معاد'][$evidence->status] }}</span>@if($editable)<button class="small-button danger-button" form="delete-evidence-{{ $evidence->id }}" type="submit">حذف</button>@endif</div>
                            @if($evidence->status === 'returned')<p class="evidence-note">ملاحظة المراجع: {{ $evidence->review_notes }}</p>@endif
                        @empty<span class="muted">لم يُرفع دليل لهذا المؤشر.</span>@endforelse
                        @if($editable)<div class="evidence-upload"><input form="upload-evidence-{{ $response->id }}" name="title" placeholder="عنوان الدليل" required><input form="upload-evidence-{{ $response->id }}" type="file" name="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg" required><button form="upload-evidence-{{ $response->id }}" class="small-button" type="submit">رفع الدليل</button></div>@endif
                    </div>
                </article>
            @endforeach
            </div>
        </section>
    @endforeach
    @if($editable)<div class="sticky-actions"><button class="secondary-button" type="submit">حفظ المسودة واحتساب النتيجة</button></div>@endif
</form>
@if($editable)
    @foreach($assessment->responses as $response)
        <form id="upload-evidence-{{ $response->id }}" method="post" action="{{ route('evidence.store',$response) }}" enctype="multipart/form-data">@csrf</form>
        @foreach($response->evidenceFiles as $evidence)<form id="delete-evidence-{{ $evidence->id }}" method="post" action="{{ route('evidence.destroy',$evidence) }}">@csrf @method('DELETE')</form>@endforeach
    @endforeach
@endif
@if($editable)
<section class="panel submit-panel"><div><h2>إرسال التقويم للمراجعة</h2><p class="muted">تأكد من إدخال جميع الدرجات. بعد الإرسال لن تتمكن من التعديل إلا إذا أعاده العميد.</p></div><form method="post" action="{{ route('assessments.submit', $assessment) }}">@csrf<button class="primary-button compact" type="submit">إرسال إلى العميد</button></form></section>
@endif
@endsection
