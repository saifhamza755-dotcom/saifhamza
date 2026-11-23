@extends('layouts.app')
@section('title', 'قرارات الاعتماد')
@section('page-title', 'قرارات الاعتماد')

@section('content')
<section class="panel" style="margin-top:0"><h2>تقييمات جاهزة للقرار</h2><p class="muted">لا يظهر هنا إلا التقييم الخارجي المعتمد الذي لم يصدر له قرار.</p><div class="decision-candidates">
@forelse($eligibleEvaluations as $evaluation)<details class="candidate-card"><summary><div><strong>{{ $evaluation->report->university->name_ar }}</strong><small>{{ $evaluation->report->cycle->name }} · النتيجة النهائية {{ $evaluation->total_score }}%</small></div><span class="secondary-button">إصدار القرار</span></summary><form method="post" action="{{ route('accreditation.store',$evaluation) }}" class="form-grid">@csrf<div class="field"><label>نوع القرار</label><select name="decision_type" required><option value="accredited">اعتماد كامل</option><option value="conditional">اعتماد مشروط</option><option value="not_accredited">عدم اعتماد</option><option value="deferred">تأجيل القرار</option></select></div><div class="field"><label>بداية السريان</label><input type="date" name="valid_from"></div><div class="field"><label>نهاية السريان</label><input type="date" name="valid_until"></div><div class="field wide"><label>مسوغات القرار</label><textarea name="rationale" rows="4" required></textarea></div><div class="field wide"><label>الشروط والالتزامات (إلزامية للاعتماد المشروط)</label><textarea name="conditions" rows="4"></textarea></div><div class="full"><button class="primary-button compact">إصدار وتثبيت القرار</button></div></form></details>
@empty<div class="empty-state">لا توجد تقييمات جاهزة لإصدار قرار جديد.</div>@endforelse
</div></section>
<section class="panel"><h2>سجل قرارات الاعتماد</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>رقم القرار</th><th>الجامعة</th><th>الدورة</th><th>القرار</th><th>النتيجة</th><th>الحالة</th><th></th></tr></thead><tbody>
@forelse($decisions as $decision) @php($display=$decision->displayStatus())
<tr><td><strong>{{ $decision->decision_number }}</strong><br><span class="muted">{{ $decision->issued_at->format('Y-m-d') }}</span></td><td>{{ $decision->university->name_ar }}</td><td>{{ $decision->cycle->name }}</td><td>{{ ['accredited'=>'اعتماد كامل','conditional'=>'اعتماد مشروط','not_accredited'=>'عدم اعتماد','deferred'=>'مؤجل'][$decision->decision_type] }}</td><td><strong>{{ $decision->final_score }}%</strong></td><td><span class="status {{ $display==='active'?'active':'inactive' }}">{{ ['active'=>'ساري','expired'=>'منتهي','revoked'=>'ملغى'][$display] }}</span></td><td><a class="small-button" href="{{ route('accreditation.show',$decision) }}">التقرير النهائي</a></td></tr>
@empty<tr><td colspan="7"><div class="empty-state">لم تصدر قرارات اعتماد حتى الآن.</div></td></tr>@endforelse
</tbody></table></div></section>
@endsection
