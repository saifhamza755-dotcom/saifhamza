@extends('layouts.app')
@section('title', 'مراجعة تقويم الكلية')
@section('page-title', 'مراجعة تقويم الكلية')

@section('content')
<section class="panel" style="margin-top:0"><h2>التقويمات المرسلة</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>الدورة</th><th>الكلية</th><th>أرسل بواسطة</th><th>النتيجة</th><th>الحالة</th><th></th></tr></thead><tbody>
@forelse($assessments as $assessment)
<tr><td><strong>{{ $assessment->cycle->name }}</strong><br><span class="muted">{{ $assessment->cycle->code }}</span></td><td>{{ $assessment->college->name_ar }}</td><td>{{ $assessment->submitter?->name }}<br><span class="muted">{{ $assessment->submitted_at?->format('Y-m-d H:i') }}</span></td><td><strong>{{ $assessment->total_score }}%</strong></td><td><span class="status {{ $assessment->status === 'approved' ? 'active' : 'draft' }}">{{ $assessment->status === 'approved' ? 'معتمد' : 'بانتظار المراجعة' }}</span></td><td><a class="small-button" href="{{ route('assessment-reviews.show', $assessment) }}">فتح التقرير</a></td></tr>
@empty<tr><td colspan="6"><div class="empty-state"><strong>لا توجد تقويمات بانتظار المراجعة</strong></div></td></tr>@endforelse
</tbody></table></div></section>
@endsection
