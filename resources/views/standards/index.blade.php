@extends('layouts.app')

@section('title', 'المعايير والمؤشرات')
@section('page-title', 'إدارة المعايير والمؤشرات')

@section('content')
<section class="panel">
    <h2>إنشاء إصدار جديد</h2>
    <p class="muted">يُبنى الإصدار كمسودة، ثم يُعتمد ويُجمّد بعد اكتمال الأوزان.</p>
    <form method="post" action="{{ route('standards.versions.store') }}" class="form-grid">
        @csrf
        <div class="field"><label>اسم الإصدار</label><input name="name" value="{{ old('name') }}" required></div>
        <div class="field"><label>رقم الإصدار</label><input name="version" value="{{ old('version') }}" placeholder="1.0" required></div>
        <div class="field"><label>تاريخ السريان</label><input type="date" name="effective_from" value="{{ old('effective_from') }}"></div>
        <div class="full"><button class="secondary-button" type="submit">إنشاء مسودة</button></div>
    </form>
</section>

@forelse ($versions as $version)
    @php $standardsTotal = (float) $version->standards->sum('weight'); @endphp
    <section class="panel version-card">
        <div class="section-head">
            <div>
                <h2>{{ $version->name }} <small>({{ $version->version }})</small></h2>
                <span class="status {{ $version->status === 'active' ? 'active' : ($version->status === 'draft' ? 'draft' : 'inactive') }}">
                    {{ ['draft' => 'مسودة', 'active' => 'معتمد', 'archived' => 'مؤرشف'][$version->status] }}
                </span>
                <span class="weight-total">مجموع أوزان المعايير: {{ number_format($standardsTotal, 2) }}%</span>
            </div>
            @if ($version->isEditable())
                <form method="post" action="{{ route('standards.versions.activate', $version) }}">
                    @csrf @method('PATCH')
                    <button class="primary-button compact" type="submit">اعتماد وتجميد الإصدار</button>
                </form>
            @endif
        </div>

        @if ($version->isEditable())
            <details class="editor-box">
                <summary>إضافة معيار إلى هذا الإصدار</summary>
                <form method="post" action="{{ route('standards.store', $version) }}" class="form-grid">
                    @csrf
                    <div class="field"><label>الرمز</label><input name="code" required></div>
                    <div class="field wide"><label>اسم المعيار</label><input name="name_ar" required></div>
                    <div class="field"><label>الوزن %</label><input type="number" name="weight" min="0.01" max="100" step="0.01" required></div>
                    <div class="field"><label>الترتيب</label><input type="number" name="sort_order" min="1" required></div>
                    <div class="field wide"><label>الاسم بالإنجليزية (اختياري)</label><input name="name_en"></div>
                    <div class="full"><button class="secondary-button" type="submit">حفظ المعيار</button></div>
                </form>
            </details>
        @endif

        <div class="standards-list">
            @forelse ($version->standards as $standard)
                <article class="standard-card">
                    <div class="section-head">
                        <div><strong>{{ $standard->code }} — {{ $standard->name_ar }}</strong><small>وزن المعيار {{ $standard->weight }}%</small></div>
                        <span class="weight-total">أوزان المؤشرات {{ number_format((float) $standard->indicators->sum('weight'), 2) }}%</span>
                    </div>
                    <div class="indicator-list">
                        @forelse ($standard->indicators as $indicator)
                            <div><span>{{ $indicator->code }} — {{ $indicator->name_ar }}</span><b>{{ $indicator->weight }}%</b></div>
                        @empty
                            <p class="muted">لا توجد مؤشرات بعد.</p>
                        @endforelse
                    </div>
                    @if ($version->isEditable())
                        <details class="editor-box small-editor">
                            <summary>إضافة مؤشر</summary>
                            <form method="post" action="{{ route('indicators.store', $standard) }}" class="form-grid">
                                @csrf
                                <div class="field"><label>الرمز</label><input name="code" required></div>
                                <div class="field wide"><label>اسم المؤشر</label><input name="name_ar" required></div>
                                <div class="field"><label>الوزن %</label><input type="number" name="weight" min="0.01" max="100" step="0.01" required></div>
                                <div class="field"><label>الترتيب</label><input type="number" name="sort_order" min="1" required></div>
                                <label class="check"><input type="checkbox" name="evidence_required" value="1" checked> يتطلب دليلاً</label>
                                <div class="full"><button class="small-button" type="submit">حفظ المؤشر</button></div>
                            </form>
                        </details>
                    @endif
                </article>
            @empty
                <p class="muted">لم تُضف معايير إلى هذا الإصدار.</p>
            @endforelse
        </div>
    </section>
@empty
    <section class="panel"><p class="muted">لا توجد إصدارات معايير حتى الآن.</p></section>
@endforelse
@endsection
