@extends('layouts.app')
@section('title', 'نموذج التقييم الخارجي')
@section('page-title', 'نموذج التقييم الخارجي')
@section('page-actions')<a class="secondary-button" href="{{ route('external-evaluations.index') }}">العودة للمهام</a>@endsection

@section('content')
@php($gap=(float)$evaluation->total_score-(float)$evaluation->report->overall_score)
<section class="comparison-summary"><div><small>الجامعة</small><strong>{{ $evaluation->report->university->name_ar }}</strong></div><div><small>المقيم</small><strong>{{ $evaluation->evaluator->name }}</strong></div><div><small>التقويم الذاتي</small><strong>{{ $evaluation->report->overall_score }}%</strong></div><div><small>التقييم الخارجي</small><strong>{{ $evaluation->total_score }}%</strong></div><div class="gap-box"><small>الفارق</small><strong>{{ $gap>0?'+':'' }}{{ number_format($gap,2) }}%</strong></div></section>
@if($evaluation->status==='returned')<div class="flash error"><strong>ملاحظات الإدارة المركزية:</strong> {{ $evaluation->review_notes }}</div>@endif
<form method="post" action="{{ route('external-evaluations.save',$evaluation) }}">@csrf @method('PUT')
@foreach($evaluation->report->cycle->standardsVersion->standards as $standard) @php($self=$selfScores->get($standard->id)['score']??0)
<section class="panel standard-assessment"><div class="section-head"><div><span class="standard-number">{{ $standard->code }}</span><h2>{{ $standard->name_ar }}</h2><small>وزن المعيار {{ $standard->weight }}%</small></div><div class="dual-score"><span>الذاتي <b>{{ number_format($self,2) }}%</b></span><span>الخارجي <b>{{ number_format($standardScores[$standard->id],2) }}%</b></span></div></div><div class="response-list">
@foreach($standard->indicators as $indicator) @php($response=$responses->get($indicator->id))
<article class="response-row"><div class="indicator-info"><b>{{ $indicator->code }}</b><strong>{{ $indicator->name_ar }}</strong><small>وزن المؤشر {{ $indicator->weight }}%</small></div><div class="field score-field"><label>الدرجة من 100</label><input type="number" min="0" max="100" step="0.01" name="responses[{{ $indicator->id }}][score]" value="{{ old("responses.{$indicator->id}.score",$response?->score) }}" @disabled(!$editable)></div><div class="field notes-field"><label>ملاحظات المقيم</label><textarea rows="2" name="responses[{{ $indicator->id }}][notes]" @disabled(!$editable)>{{ old("responses.{$indicator->id}.notes",$response?->notes) }}</textarea></div></article>
@endforeach</div></section>
@endforeach
@if($editable)<div class="sticky-actions"><button class="secondary-button" type="submit">حفظ واحتساب النتائج</button></div>@endif
</form>
@if($editable)<section class="panel submit-panel"><div><h2>إرسال التقييم للمراجعة</h2><p class="muted">يجب استكمال درجات جميع المؤشرات قبل الإرسال.</p></div><form method="post" action="{{ route('external-evaluations.submit',$evaluation) }}">@csrf<button class="primary-button compact">إرسال للإدارة المركزية</button></form></section>@endif
@if(auth()->user()->hasRole('central') && $evaluation->status==='submitted')<section class="panel decision-panel"><h2>قرار الإدارة المركزية</h2><form method="post" action="{{ route('external-evaluations.decide',$evaluation) }}" class="form-grid">@csrf<div class="field full"><label>ملاحظات المراجعة (إلزامية عند الإعادة)</label><textarea name="review_notes" rows="4"></textarea></div><div class="decision-actions"><button class="primary-button compact" name="decision" value="approved">اعتماد التقييم</button><button class="secondary-button danger-button" name="decision" value="returned">إعادة للمقيم</button></div></form></section>@endif
@if($evaluation->status==='approved')<div class="flash success">تم اعتماد التقييم الخارجي بتاريخ {{ $evaluation->reviewed_at?->format('Y-m-d H:i') }}.</div>@endif
@endsection
