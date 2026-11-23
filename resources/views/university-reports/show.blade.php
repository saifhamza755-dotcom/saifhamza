@extends('layouts.app')
@section('title', 'التقرير الجامعي')
@section('page-title', 'التقرير الجامعي الموحد')
@section('page-actions')<a class="secondary-button" href="{{ route('university-reports.index') }}">العودة للتقارير</a>@endsection

@section('content')
<section class="report-hero"><div><span class="cycle-code">{{ $report->cycle->code }}</span><h2>{{ $report->university->name_ar }}</h2><p>{{ $report->cycle->name }}</p></div><div class="report-score"><small>النتيجة الجامعية</small><strong>{{ $report->overall_score }}%</strong><span>{{ $report->college_count }} كليات معتمدة</span></div></section>
@if($report->status==='returned')<div class="flash error"><strong>ملاحظات الإدارة المركزية:</strong> {{ $report->review_notes }}</div>@endif
<section class="panel"><h2>نتائج المعايير المجمعة</h2><div class="standards-results">
@foreach($report->standards_snapshot as $standard)<div class="result-row"><div><b>{{ $standard['code'] }}</b><span>{{ $standard['name'] }}</span></div><div class="result-bar"><i style="width:{{ $standard['score'] }}%"></i></div><strong>{{ number_format($standard['score'],2) }}%</strong></div>@endforeach
</div></section>
<section class="panel"><h2>الملخص التنفيذي والتوصيات</h2>
@if($editable)<form method="post" action="{{ route('university-reports.save',$report) }}" class="report-form">@csrf @method('PUT')<div class="field"><label>الملخص التنفيذي</label><textarea name="executive_summary" rows="8" required>{{ old('executive_summary',$report->executive_summary) }}</textarea></div><div class="field"><label>التوصيات العامة</label><textarea name="recommendations" rows="8" required>{{ old('recommendations',$report->recommendations) }}</textarea></div><button class="secondary-button" type="submit">حفظ وتحديث النتائج</button></form>
@else<div class="report-text"><article><h3>الملخص التنفيذي</h3><p>{{ $report->executive_summary ?: 'لم يُكتب بعد.' }}</p></article><article><h3>التوصيات</h3><p>{{ $report->recommendations ?: 'لم تُكتب بعد.' }}</p></article></div>@endif
</section>
@if($editable)<section class="panel submit-panel"><div><h2>إرسال التقرير للاعتماد</h2><p class="muted">بعد الإرسال يصبح التقرير للقراءة فقط حتى قرار الإدارة المركزية.</p></div><form method="post" action="{{ route('university-reports.submit',$report) }}">@csrf<button class="primary-button compact" type="submit">إرسال للإدارة المركزية</button></form></section>@endif
@if($assignment->role->key==='central' && $report->status==='submitted')<section class="panel decision-panel"><h2>قرار الإدارة المركزية</h2><form method="post" action="{{ route('university-reports.decide',$report) }}" class="form-grid">@csrf<div class="field full"><label>ملاحظات المراجعة (إلزامية عند الإعادة)</label><textarea name="review_notes" rows="4"></textarea></div><div class="decision-actions"><button class="primary-button compact" name="decision" value="approved">اعتماد التقرير</button><button class="secondary-button danger-button" name="decision" value="returned">إعادة للجامعة</button></div></form></section>@endif
@if($report->status==='approved')<div class="flash success">تقرير جامعي معتمد بواسطة {{ $report->reviewer?->name }} بتاريخ {{ $report->reviewed_at?->format('Y-m-d H:i') }}.</div>@endif
@endsection
