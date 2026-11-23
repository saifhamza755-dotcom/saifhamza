@extends('layouts.app')
@section('title', 'التقويم الذاتي')
@section('page-title', 'التقويم الذاتي للكلية')

@section('content')
<section class="panel" style="margin-top:0">
    <h2>دورات التقييم المتاحة</h2>
    <p class="muted">{{ $assignment->college->name_ar }} — ابدأ التقويم أو تابع المسودة المحفوظة.</p>
    <div class="assessment-grid">
        @forelse ($cycles as $cycle)
            @php($assessment = $cycle->selfAssessments->first())
            <article class="assessment-card">
                <div class="section-head">
                    <div><span class="cycle-code">{{ $cycle->code }}</span><h3>{{ $cycle->name }}</h3><small>{{ $cycle->starts_on->format('Y-m-d') }} — {{ $cycle->ends_on->format('Y-m-d') }}</small></div>
                    <span class="status {{ $cycle->status === 'open' ? 'active' : 'inactive' }}">{{ $cycle->status === 'open' ? 'الدورة مفتوحة' : 'الدورة مغلقة' }}</span>
                </div>
                <div class="assessment-meta"><span>إصدار المعايير <b>{{ $cycle->standardsVersion->version }}</b></span>@if($assessment)<span>النتيجة <b>{{ $assessment->total_score }}%</b></span>@endif</div>
                @if ($assessment)
                    <div class="assessment-footer"><span class="status {{ in_array($assessment->status, ['approved']) ? 'active' : ($assessment->status === 'returned' ? 'inactive' : 'draft') }}">{{ ['draft'=>'مسودة','submitted'=>'قيد المراجعة','returned'=>'معاد للتعديل','approved'=>'معتمد'][$assessment->status] }}</span><a class="secondary-button" href="{{ route('assessments.edit', $assessment) }}">{{ in_array($assessment->status, ['draft','returned']) ? 'متابعة التقويم' : 'عرض التقويم' }}</a></div>
                @elseif ($cycle->status === 'open')
                    <form method="post" action="{{ route('assessments.start', $cycle) }}">@csrf<button class="primary-button compact" type="submit">بدء التقويم الذاتي</button></form>
                @else
                    <span class="muted">لم يبدأ التقويم قبل إغلاق الدورة.</span>
                @endif
            </article>
        @empty
            <div class="empty-state"><strong>لا توجد دورة مخصصة لهذه الكلية</strong><span>ستظهر الدورة هنا بعد إضافتها من الإدارة المركزية.</span></div>
        @endforelse
    </div>
</section>
@endsection
