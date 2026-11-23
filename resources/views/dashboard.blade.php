@extends('layouts.app')
@section('title', 'لوحة التحكم')
@section('page-title', 'لوحة التحكم')

@section('page-actions')
    <span>مرحبًا، {{ $user->name }}</span>
@endsection

@section('content')
<div class="stats">
    <article class="stat"><i class="stat-icon university-icon"></i><div><span>الجامعات ضمن النطاق</span><strong>{{ $universityCount }}</strong><small>مؤسسات تعليمية</small></div></article>
    <article class="stat"><i class="stat-icon college-icon"></i><div><span>الكليات ضمن النطاق</span><strong>{{ $collegeCount }}</strong><small>كليات مسجلة</small></div></article>
    <article class="stat"><i class="stat-icon permission-icon"></i><div><span>المهام التي تتطلب إجراء</span><strong>{{ $pendingCount }}</strong><small>حسب دورك الحالي</small></div></article>
    <article class="stat"><i class="stat-icon account-icon"></i><div><span>خطط التحسين الفعالة</span><strong>{{ $activePlans }}</strong><small>قيد المتابعة</small></div></article>
</div>

<article class="panel">
    <h2>نطاق التكليف</h2>
    <div class="scope-grid">
        <div class="scope-item"><small>الدور</small><strong>{{ $assignment->role->name_ar }}</strong></div>
        <div class="scope-item"><small>الجامعة</small><strong>{{ $assignment->university?->name_ar ?? 'جميع المؤسسات المخولة' }}</strong></div>
        <div class="scope-item"><small>الكلية</small><strong>{{ $assignment->college?->name_ar ?? 'حسب نطاق الدور' }}</strong></div>
    </div>
</article>

<article class="panel">
    <h2>الصلاحيات الحالية</h2>
    <div class="permissions">
        @forelse ($assignment->role->permissions as $permission)
            <span class="permission">{{ $permission->name_ar }}</span>
        @empty
            <span>لا توجد صلاحيات مرتبطة بهذا التكليف.</span>
        @endforelse
    </div>
</article>
@endsection
