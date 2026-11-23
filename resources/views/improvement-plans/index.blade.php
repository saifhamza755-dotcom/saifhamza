@extends('layouts.app')
@section('title', 'خطط التحسين')
@section('page-title', 'خطط التحسين والمتابعة')

@section('content')
@if(in_array($assignment->role->key,['university','college']))
<section class="panel" style="margin-top:0"><h2>إنشاء خطة تحسين</h2><p class="muted">تُنشأ الخطة من {{ $assignment->role->key==='college'?'تقويم ذاتي معتمد':'تقييم خارجي معتمد' }}.</p>
@if($sources->isNotEmpty())<form method="post" action="{{ route('improvement-plans.store') }}" class="form-grid">@csrf<input type="hidden" name="source_type" value="{{ $assignment->role->key==='college'?'self_assessment':'external_evaluation' }}"><div class="field wide"><label>عنوان الخطة</label><input name="title" required placeholder="خطة التحسين للدورة..."></div><div class="field"><label>المصدر المعتمد</label><select name="source_id" required><option value="">اختر المصدر</option>@foreach($sources as $source)<option value="{{ $source->id }}">{{ $assignment->role->key==='college'?$source->cycle->name:$source->report->cycle->name }}</option>@endforeach</select></div><div class="full"><button class="primary-button compact">إنشاء مسودة الخطة</button></div></form>
@else<div class="empty-inline">لا يوجد مصدر معتمد جديد لإنشاء خطة منه.</div>@endif</section>
@endif
<section class="panel"><h2>{{ $assignment->role->key==='central'?'جميع خطط المؤسسات':'الخطط المسجلة' }}</h2><div class="plan-grid">
@forelse($plans as $plan) @php($progress=round((float)$plan->actions->avg('progress'),1))
<article class="plan-card"><div class="section-head"><div><span class="cycle-code">{{ $plan->cycle->code }}</span><h3>{{ $plan->title }}</h3><small>{{ $plan->college?->name_ar ?? $plan->university->name_ar }}</small></div><span class="status {{ $plan->status==='completed'?'active':($plan->status==='active'?'draft':'inactive') }}">{{ ['draft'=>'مسودة','active'=>'قيد التنفيذ','completed'=>'مكتملة'][$plan->status] }}</span></div><div class="plan-progress"><div><span>نسبة الإنجاز</span><strong>{{ $progress }}%</strong></div><div class="coverage-bar"><i style="width:{{ $progress }}%"></i></div></div><div class="assessment-footer"><span>{{ $plan->actions->count() }} إجراءات · {{ $plan->actions->where('status','completed')->count() }} مكتملة</span><a class="secondary-button" href="{{ route('improvement-plans.show',$plan) }}">فتح الخطة</a></div></article>
@empty<div class="empty-state">لا توجد خطط تحسين حتى الآن.</div>@endforelse
</div></section>
@endsection
