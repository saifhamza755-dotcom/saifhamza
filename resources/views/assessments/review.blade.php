@extends('layouts.app')
@section('title', 'مراجعة التقويم')
@section('page-title', 'مراجعة التقويم الذاتي')
@section('page-actions')<a class="secondary-button" href="{{ route('assessment-reviews.index') }}">العودة للقائمة</a>@endsection

@section('content')
<section class="assessment-summary"><div><small>الكلية</small><strong>{{ $assessment->college->name_ar }}</strong></div><div><small>الدورة</small><strong>{{ $assessment->cycle->name }}</strong></div><div><small>مقدم التقرير</small><strong>{{ $assessment->submitter?->name }}</strong></div><div class="score-box"><small>النتيجة الكلية</small><strong>{{ $assessment->total_score }}%</strong></div></section>
@foreach($assessment->cycle->standardsVersion->standards as $standard)
<section class="panel standard-assessment"><div class="section-head"><div><span class="standard-number">{{ $standard->code }}</span><h2>{{ $standard->name_ar }}</h2></div><div class="standard-score"><small>نتيجة المعيار</small><strong>{{ number_format($standardScores[$standard->id],2) }}%</strong></div></div><div class="response-list">
@foreach($standard->indicators as $indicator) @php($response=$responses->get($indicator->id))
<article class="response-row review-row"><div class="indicator-info"><b>{{ $indicator->code }}</b><strong>{{ $indicator->name_ar }}</strong></div><div><small>الدرجة</small><strong class="review-score">{{ $response?->score }}%</strong></div><div><small>الملاحظات</small><p>{{ $response?->notes ?: 'لا توجد ملاحظات.' }}</p></div><div class="evidence-box review-evidence"><div class="evidence-head"><strong>أدلة المؤشر</strong></div>
@forelse($response?->evidenceFiles ?? [] as $evidence)
<div class="review-evidence-item"><div><a href="{{ route('evidence.download',$evidence) }}">{{ $evidence->title }}</a><small>{{ $evidence->original_name }}</small></div><span class="status {{ $evidence->status === 'accepted' ? 'active' : ($evidence->status === 'returned' ? 'inactive' : 'draft') }}">{{ ['pending'=>'بانتظار القرار','accepted'=>'مقبول','returned'=>'معاد'][$evidence->status] }}</span></div>
@if($assessment->status === 'submitted')<form method="post" action="{{ route('evidence.review',$evidence) }}" class="evidence-review-form">@csrf @method('PATCH')<input name="review_notes" placeholder="ملاحظة الإعادة"><button class="small-button" name="status" value="accepted" type="submit">قبول</button><button class="small-button danger-button" name="status" value="returned" type="submit">إعادة</button></form>@elseif($evidence->review_notes)<p class="evidence-note">{{ $evidence->review_notes }}</p>@endif
@empty<span class="muted">لا يوجد دليل.</span>@endforelse
</div></article>
@endforeach</div></section>
@endforeach
@if($assessment->status === 'submitted')
<section class="panel decision-panel"><h2>قرار المراجعة</h2><form method="post" action="{{ route('assessment-reviews.decide',$assessment) }}" class="form-grid">@csrf<div class="field full"><label>ملاحظات المراجع (إلزامية عند الإعادة)</label><textarea name="review_notes" rows="4"></textarea></div><div class="decision-actions"><button class="primary-button compact" name="decision" value="approved" type="submit">اعتماد التقويم</button><button class="secondary-button danger-button" name="decision" value="returned" type="submit">إعادة للتعديل</button></div></form></section>
@else<div class="flash success">تم اعتماد هذا التقويم بتاريخ {{ $assessment->reviewed_at?->format('Y-m-d H:i') }}.</div>@endif
@endsection
