@extends('layouts.app')
@section('title', 'إدارة المستخدمين')
@section('page-title', 'إدارة المستخدمين')

@section('content')
<article class="panel" style="margin-top:0">
    <h2>إضافة مستخدم جديد</h2>
    <form method="post" action="{{ route('users.store') }}">
        @csrf
        <div class="form-grid">
            <div class="field"><label>الاسم الكامل</label><input name="name" value="{{ old('name') }}" required></div>
            <div class="field"><label>اسم المستخدم</label><input name="username" value="{{ old('username') }}" required></div>
            <div class="field"><label>البريد الإلكتروني</label><input type="email" name="email" value="{{ old('email') }}" required></div>
            <div class="field"><label>رقم الهاتف</label><input name="phone" value="{{ old('phone') }}"></div>
            <div class="field"><label>الدور</label><select name="role_key" required>@foreach($roles as $role)<option value="{{ $role->key }}">{{ $role->name_ar }}</option>@endforeach</select></div>
            <div class="field"><label>الجامعة</label><select name="university_id"><option value="">بدون نطاق جامعة</option>@foreach($universities as $university)<option value="{{ $university->id }}">{{ $university->name_ar }}</option>@endforeach</select></div>
            <div class="field"><label>الكلية</label><select name="college_id"><option value="">بدون نطاق كلية</option>@foreach($universities as $university)<optgroup label="{{ $university->name_ar }}">@foreach($university->colleges as $college)<option value="{{ $college->id }}">{{ $college->name_ar }}</option>@endforeach</optgroup>@endforeach</select></div>
            <div class="field"><label>كلمة المرور المؤقتة</label><input type="password" name="password" required minlength="8"></div>
            <div class="field"><label>تأكيد كلمة المرور</label><input type="password" name="password_confirmation" required minlength="8"></div>
            <div class="full"><button class="primary-button" style="width:auto" type="submit">إنشاء الحساب والتكليف</button></div>
        </div>
    </form>
</article>

<article class="panel">
    <h2>حسابات المستخدمين</h2>
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>المستخدم</th><th>الدور</th><th>النطاق</th><th>الحالة</th><th>الإجراء</th></tr></thead>
        <tbody>
        @foreach($users as $listedUser)
            @php($scope = $listedUser->activeAssignments->first())
            <tr>
                <td><strong>{{ $listedUser->name }}</strong><br><span class="muted">{{ $listedUser->username }} · {{ $listedUser->email }}</span></td>
                <td>{{ $scope?->role?->name_ar ?? 'لا يوجد تكليف فعال' }}</td>
                <td>{{ $scope?->college?->name_ar ?? $scope?->university?->name_ar ?? 'المنصة / حسب التكليف' }}</td>
                <td><span class="status {{ $listedUser->active ? 'active' : 'inactive' }}">{{ $listedUser->active ? 'نشط' : 'موقوف' }}</span></td>
                <td>
                    @if(!auth()->user()->is($listedUser))
                    <form class="inline-form" method="post" action="{{ route('users.status', $listedUser) }}">@csrf @method('PATCH')
                        <input type="hidden" name="active" value="{{ $listedUser->active ? 0 : 1 }}">
                        <button class="small-button {{ $listedUser->active ? 'danger-button' : '' }}" type="submit">{{ $listedUser->active ? 'إيقاف' : 'تفعيل' }}</button>
                    </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</article>
@endsection
