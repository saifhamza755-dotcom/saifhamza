@extends('layouts.app')
@section('title', 'الهيكل المؤسسي')
@section('page-title', $assignment->role->key === 'university' ? 'هيكل الجامعة' : 'الهيكل المؤسسي')

@section('content')
<article class="panel" style="margin-top:0">
    <h2>إضافة عنصر إلى الهيكل</h2>
    <form method="post" action="{{ route('institutions.store') }}">
        @csrf
        <div class="form-grid">
            <div class="field"><label>نوع العنصر</label><select id="entity_type" name="entity_type" required>
                @if($assignment->role->key === 'central')<option value="university">جامعة</option>@endif
                <option value="college">كلية</option><option value="department">قسم</option><option value="program">برنامج أكاديمي</option>
            </select></div>
            <div class="field"><label>الاسم العربي</label><input name="name_ar" required></div>
            <div class="field"><label>الاسم الإنجليزي</label><input name="name_en"></div>
            <div class="field"><label>الرمز</label><input name="code" required></div>
            <div class="field university-parent"><label>الجامعة</label><select name="university_id"><option value="">اختر الجامعة</option>@foreach($universities as $university)<option value="{{ $university->id }}">{{ $university->name_ar }}</option>@endforeach</select></div>
            <div class="field college-parent"><label>الكلية</label><select name="college_id"><option value="">اختر الكلية</option>@foreach($universities as $university)@foreach($university->colleges as $college)<option value="{{ $college->id }}">{{ $university->name_ar }} — {{ $college->name_ar }}</option>@endforeach @endforeach</select></div>
            <div class="field department-parent"><label>القسم</label><select name="department_id"><option value="">اختر القسم</option>@foreach($universities as $university)@foreach($university->colleges as $college)@foreach($college->departments as $department)<option value="{{ $department->id }}">{{ $college->name_ar }} — {{ $department->name_ar }}</option>@endforeach @endforeach @endforeach</select></div>
            <div class="field university-fields"><label>نوع الجامعة</label><select name="type"><option value="public">حكومية</option><option value="private">أهلية</option></select></div>
            <div class="field university-fields"><label>المدينة</label><input name="city"></div>
            <div class="field program-fields"><label>الدرجة العلمية</label><select name="degree"><option value="bachelor">بكالوريوس</option><option value="diploma">دبلوم</option><option value="master">ماجستير</option><option value="doctorate">دكتوراه</option></select></div>
            <div class="full"><button class="primary-button" style="width:auto" type="submit">حفظ العنصر</button></div>
        </div>
    </form>
</article>

<article class="panel"><h2>الهيكل المسجل</h2><div class="structure">
@foreach($universities as $university)
    <section class="university-card">
        <div class="university-head"><div><strong>{{ $university->name_ar }}</strong> <span class="muted">({{ $university->code }})</span></div><div><span class="status {{ $university->active ? 'active' : 'inactive' }}">{{ $university->active ? 'نشطة' : 'موقوفة' }}</span>
            @if($assignment->role->key === 'central')<form class="inline-form" method="post" action="{{ route('institutions.status', ['type' => 'university', 'id' => $university->id]) }}">@csrf @method('PATCH')<input type="hidden" name="active" value="{{ $university->active ? 0 : 1 }}"><button class="small-button" type="submit">{{ $university->active ? 'إيقاف' : 'تفعيل' }}</button></form>@endif
        </div></div>
        @foreach($university->colleges as $college)
            <div class="college-card">
                <div class="entity-row"><strong>{{ $college->name_ar }}</strong><span>{{ $college->code }} <form class="inline-form" method="post" action="{{ route('institutions.status', ['type' => 'college', 'id' => $college->id]) }}">@csrf @method('PATCH')<input type="hidden" name="active" value="{{ $college->active ? 0 : 1 }}"><button class="small-button" type="submit">{{ $college->active ? 'إيقاف' : 'تفعيل' }}</button></form></span></div>
                @foreach($college->departments as $department)
                    <div class="entity-row"><span>قسم: {{ $department->name_ar }}</span><span>{{ $department->code }} <form class="inline-form" method="post" action="{{ route('institutions.status', ['type' => 'department', 'id' => $department->id]) }}">@csrf @method('PATCH')<input type="hidden" name="active" value="{{ $department->active ? 0 : 1 }}"><button class="small-button" type="submit">{{ $department->active ? 'إيقاف' : 'تفعيل' }}</button></form></span></div>
                    @foreach($department->programs as $program)<div class="entity-row"><span>برنامج: {{ $program->name_ar }}</span><span>{{ $program->code }} <form class="inline-form" method="post" action="{{ route('institutions.status', ['type' => 'program', 'id' => $program->id]) }}">@csrf @method('PATCH')<input type="hidden" name="active" value="{{ $program->active ? 0 : 1 }}"><button class="small-button" type="submit">{{ $program->active ? 'إيقاف' : 'تفعيل' }}</button></form></span></div>@endforeach
                @endforeach
            </div>
        @endforeach
    </section>
@endforeach
</div></article>
@endsection

@push('scripts')
<script>
const typeSelect = document.getElementById('entity_type');
function updateInstitutionForm() {
    const type = typeSelect.value;
    document.querySelectorAll('.university-parent,.college-parent,.department-parent,.university-fields,.program-fields').forEach(el => el.style.display = 'none');
    if (type === 'university') document.querySelectorAll('.university-fields').forEach(el => el.style.display = 'grid');
    if (type === 'college') document.querySelectorAll('.university-parent').forEach(el => el.style.display = 'grid');
    if (type === 'department') document.querySelectorAll('.college-parent').forEach(el => el.style.display = 'grid');
    if (type === 'program') { document.querySelectorAll('.department-parent,.program-fields').forEach(el => el.style.display = 'grid'); }
}
typeSelect.addEventListener('change', updateInstitutionForm); updateInstitutionForm();
</script>
@endpush
