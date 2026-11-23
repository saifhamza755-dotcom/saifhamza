@extends('layouts.app')
@section('title', 'التقارير الجامعية')
@section('page-title', $assignment->role->key === 'central' ? 'اعتماد تقارير الجامعات' : 'التقرير الجامعي الموحد')

@section('content')
@if($assignment->role->key === 'university')
<section class="panel" style="margin-top:0"><h2>دورات الجامعة</h2><p class="muted">لا يمكن إنشاء التقرير إلا بعد اعتماد تقويمات جميع الكليات المشاركة.</p><div class="assessment-grid">
@forelse($cycles as $cycle) @php($report=$cycle->universityReports->first())
<article class="assessment-card"><div class="section-head"><div><span class="cycle-code">{{ $cycle->code }}</span><h3>{{ $cycle->name }}</h3><small>إصدار المعايير {{ $cycle->standardsVersion->version }}</small></div>@if($report)<span class="status {{ $report->status==='approved'?'active':($report->status==='returned'?'inactive':'draft') }}">{{ ['draft'=>'مسودة','submitted'=>'قيد المراجعة','returned'=>'معاد للتعديل','approved'=>'معتمد'][$report->status] }}</span>@endif</div>
<div class="coverage"><div><span>الكليات المعتمدة</span><strong>{{ $cycle->approved_count }} / {{ $cycle->participating_count }}</strong></div><div class="coverage-bar"><i style="width:{{ $cycle->participating_count ? ($cycle->approved_count/$cycle->participating_count)*100 : 0 }}%"></i></div></div>
@if($report)<div class="assessment-footer"><span>النتيجة المجمعة: <b>{{ $report->overall_score }}%</b></span><a class="secondary-button" href="{{ route('university-reports.show',$report) }}">فتح التقرير</a></div>
@elseif($cycle->participating_count>0 && $cycle->approved_count===$cycle->participating_count)<form method="post" action="{{ route('university-reports.generate',$cycle) }}">@csrf<button class="primary-button compact" type="submit">تجميع وإنشاء التقرير</button></form>
@else<span class="muted">بانتظار اكتمال واعتماد تقويمات الكليات.</span>@endif</article>
@empty<div class="empty-state">لا توجد دورات مخصصة لهذه الجامعة.</div>@endforelse
</div></section>
@else
<section class="panel" style="margin-top:0"><h2>تقارير الجامعات</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>الجامعة</th><th>الدورة</th><th>الكليات</th><th>النتيجة</th><th>الحالة</th><th></th></tr></thead><tbody>
@forelse($reports as $report)<tr><td><strong>{{ $report->university->name_ar }}</strong></td><td>{{ $report->cycle->name }}<br><span class="muted">{{ $report->cycle->code }}</span></td><td>{{ $report->college_count }}</td><td><strong>{{ $report->overall_score }}%</strong></td><td><span class="status {{ $report->status==='approved'?'active':($report->status==='returned'?'inactive':'draft') }}">{{ ['draft'=>'مسودة','submitted'=>'بانتظار المراجعة','returned'=>'معاد للجامعة','approved'=>'معتمد'][$report->status] }}</span></td><td><a class="small-button" href="{{ route('university-reports.show',$report) }}">عرض التقرير</a></td></tr>
@empty<tr><td colspan="6"><div class="empty-state">لم تُرسل الجامعات تقارير حتى الآن.</div></td></tr>@endforelse
</tbody></table></div></section>
@endif
@endsection
