@extends('layouts.app')
@section('title', 'التقييم الخارجي')
@section('page-title', $isCentral ? 'إدارة التقييم الخارجي' : 'مهام التقييم الخارجي')

@section('content')
@if($isCentral)
<section class="panel" style="margin-top:0"><h2>التقارير الجامعية المعتمدة</h2><p class="muted">عيّن مقيمًا خارجيًا لكل تقرير معتمد، ثم تابع حالة إنجاز المهمة.</p><div class="table-wrap"><table class="data-table"><thead><tr><th>الجامعة</th><th>الدورة</th><th>نتيجة التقويم الذاتي</th><th>المقيم</th><th>الحالة</th><th>الإجراء</th></tr></thead><tbody>
@forelse($reports as $report) @php($evaluation=$report->externalEvaluation)
<tr><td><strong>{{ $report->university->name_ar }}</strong></td><td>{{ $report->cycle->name }}</td><td><strong>{{ $report->overall_score }}%</strong></td><td>{{ $evaluation?->evaluator?->name ?? 'لم يعيّن' }}</td><td>@if($evaluation)<span class="status {{ $evaluation->status==='approved'?'active':($evaluation->status==='returned'?'inactive':'draft') }}">{{ ['assigned'=>'قيد التنفيذ','submitted'=>'بانتظار الاعتماد','returned'=>'معاد للمقيم','approved'=>'معتمد'][$evaluation->status] }}</span>@else<span class="muted">غير مكلّف</span>@endif</td><td>
@if($evaluation)<a class="small-button" href="{{ route('external-evaluations.show',$evaluation) }}">عرض ومراجعة</a>@else<form class="assign-form" method="post" action="{{ route('external-evaluations.assign',$report) }}">@csrf<select name="evaluator_id" required><option value="">اختر المقيم</option>@foreach($evaluators as $evaluator)<option value="{{ $evaluator->id }}">{{ $evaluator->name }}</option>@endforeach</select><button class="small-button" type="submit">تعيين</button></form>@endif
</td></tr>
@empty<tr><td colspan="6"><div class="empty-state">لا توجد تقارير جامعية معتمدة جاهزة للتقييم.</div></td></tr>@endforelse
</tbody></table></div></section>
@else
<section class="panel" style="margin-top:0"><h2>المهام المسندة إليك</h2><div class="assessment-grid">
@forelse($evaluations as $evaluation)<article class="assessment-card"><div class="section-head"><div><span class="cycle-code">{{ $evaluation->report->cycle->code }}</span><h3>{{ $evaluation->report->university->name_ar }}</h3><small>{{ $evaluation->report->cycle->name }}</small></div><span class="status {{ $evaluation->status==='approved'?'active':($evaluation->status==='returned'?'inactive':'draft') }}">{{ ['assigned'=>'مهمة جديدة','submitted'=>'مرسل للمراجعة','returned'=>'معاد للتعديل','approved'=>'معتمد'][$evaluation->status] }}</span></div><div class="assessment-meta"><span>الذاتي <b>{{ $evaluation->report->overall_score }}%</b></span><span>الخارجي <b>{{ $evaluation->total_score }}%</b></span></div><a class="secondary-button" href="{{ route('external-evaluations.show',$evaluation) }}">{{ in_array($evaluation->status,['assigned','returned'])?'تنفيذ المهمة':'عرض المهمة' }}</a></article>
@empty<div class="empty-state">لا توجد مهام تقييم مسندة إليك.</div>@endforelse
</div></section>
@endif
@endsection
