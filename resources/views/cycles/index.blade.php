@extends('layouts.app')

@section('title', 'دورات التقييم')
@section('page-title', 'إدارة دورات التقييم')

@section('content')
<section class="panel">
    <h2>إنشاء دورة تقييم</h2>
    @if ($versions->isEmpty())
        <div class="flash error">يجب اعتماد إصدار معايير أولاً قبل إنشاء دورة.</div>
    @else
        <form method="post" action="{{ route('cycles.store') }}" class="form-grid">
            @csrf
            <div class="field wide"><label>اسم الدورة</label><input name="name" value="{{ old('name') }}" required></div>
            <div class="field"><label>رمز الدورة</label><input name="code" value="{{ old('code') }}" required></div>
            <div class="field"><label>تاريخ البداية</label><input type="date" name="starts_on" value="{{ old('starts_on') }}" required></div>
            <div class="field"><label>تاريخ النهاية</label><input type="date" name="ends_on" value="{{ old('ends_on') }}" required></div>
            <div class="field"><label>إصدار المعايير</label><select name="standards_version_id" required>@foreach ($versions as $version)<option value="{{ $version->id }}">{{ $version->name }} — {{ $version->version }}</option>@endforeach</select></div>
            <fieldset class="full choice-box"><legend>الجامعات المشاركة</legend>
                @foreach ($universities as $university)
                    <label class="choice"><input type="checkbox" name="university_ids[]" value="{{ $university->id }}" @checked(in_array($university->id, old('university_ids', [])))> <span>{{ $university->name_ar }} <small>({{ $university->colleges->count() }} كليات نشطة)</small></span></label>
                @endforeach
            </fieldset>
            <div class="full"><button class="primary-button compact" type="submit">إنشاء الدورة كمسودة</button></div>
        </form>
    @endif
</section>

<section class="panel">
    <h2>الدورات المسجلة</h2>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>الدورة</th><th>الفترة</th><th>إصدار المعايير</th><th>النطاق</th><th>الحالة</th><th>الإجراء</th></tr></thead><tbody>
    @forelse ($cycles as $cycle)
        <tr>
            <td><strong>{{ $cycle->name }}</strong><br><small class="muted">{{ $cycle->code }}</small></td>
            <td>{{ $cycle->starts_on->format('Y-m-d') }} — {{ $cycle->ends_on->format('Y-m-d') }}</td>
            <td>{{ $cycle->standardsVersion->version }}</td>
            <td>{{ $cycle->universities->count() }} جامعة / {{ $cycle->colleges->count() }} كلية</td>
            <td><span class="status {{ $cycle->status === 'open' ? 'active' : ($cycle->status === 'draft' ? 'draft' : 'inactive') }}">{{ ['draft' => 'مسودة', 'open' => 'مفتوحة', 'closed' => 'مغلقة'][$cycle->status] }}</span></td>
            <td>
                @if ($cycle->status === 'draft')
                    <form method="post" action="{{ route('cycles.status', $cycle) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="open"><button class="small-button" type="submit">فتح الدورة</button></form>
                @elseif ($cycle->status === 'open')
                    <form method="post" action="{{ route('cycles.status', $cycle) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="closed"><button class="small-button danger-button" type="submit">إغلاق الدورة</button></form>
                @else
                    <span class="muted">للقراءة فقط</span>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">لا توجد دورات تقييم حتى الآن.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endsection
