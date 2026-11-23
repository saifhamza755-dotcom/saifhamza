@php
    $layoutAssignment = $assignment ?? auth()->user()->activeAssignments()->with('role')->first();
    $roleKey = $layoutAssignment->role->key;
    $menu = [['label' => 'لوحة التحكم', 'route' => 'dashboard', 'icon' => 'grid']];
    if (auth()->user()->hasPermission('users.manage')) {
        $menu[] = ['label' => 'إدارة المستخدمين', 'route' => 'users.index', 'icon' => 'users'];
    }
    if (auth()->user()->hasPermission('institutions.manage')) {
        $menu[] = ['label' => $roleKey === 'university' ? 'هيكل الجامعة' : 'الهيكل المؤسسي', 'route' => 'institutions.index', 'icon' => 'building'];
    }
    if (auth()->user()->hasPermission('standards.manage')) {
        $menu[] = ['label' => 'المعايير والمؤشرات', 'route' => 'standards.index', 'icon' => 'checklist'];
    }
    if (auth()->user()->hasPermission('cycles.manage')) {
        $menu[] = ['label' => 'دورات التقييم', 'route' => 'cycles.index', 'icon' => 'cycle'];
    }
    if (auth()->user()->hasPermission('self_assessment.manage')) {
        $menu[] = ['label' => 'التقويم الذاتي', 'route' => 'assessments.index', 'icon' => 'checklist'];
    }
    if (auth()->user()->hasPermission('college_reports.approve')) {
        $menu[] = ['label' => 'مراجعة تقويم الكلية', 'route' => 'assessment-reviews.index', 'icon' => 'checklist'];
    }
    if (auth()->user()->hasPermission('university_reports.manage')) {
        $menu[] = ['label' => $roleKey === 'central' ? 'اعتماد تقارير الجامعات' : 'التقرير الجامعي', 'route' => 'university-reports.index', 'icon' => 'building'];
    }
    if (auth()->user()->hasPermission('evaluation.manage')) {
        $menu[] = ['label' => $roleKey === 'evaluator' ? 'مهام التقييم' : 'التقييم الخارجي', 'route' => 'external-evaluations.index', 'icon' => 'checklist'];
    }
    if (auth()->user()->hasPermission('improvement.manage')) {
        $menu[] = ['label' => 'خطط التحسين', 'route' => 'improvement-plans.index', 'icon' => 'cycle'];
    }
    if (auth()->user()->hasPermission('accreditation.decide')) {
        $menu[] = ['label' => 'قرارات الاعتماد', 'route' => 'accreditation.index', 'icon' => 'building'];
    }
    if (auth()->user()->hasPermission('audit.view')) {
        $menu[] = ['label' => 'سجل التدقيق', 'route' => 'audit.index', 'icon' => 'checklist'];
    }
    $unreadNotifications = auth()->user()->appNotifications()->whereNull('read_at')->count();
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | منصة اليمن للجودة والاعتماد</title>
    <link rel="stylesheet" href="{{ asset('css/platform.css') }}">
</head>
<body class="app-body">
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="brand-mark">ج</span>
            <div><strong>منصة الجودة والاعتماد</strong><span>التعليم العالي · الجمهورية اليمنية</span></div>
        </div>
        <div class="nav-label">القائمة الرئيسية</div>
        <nav class="nav">
            @foreach ($menu as $item)
                <a class="{{ request()->routeIs($item['route'], \Illuminate\Support\Str::before($item['route'], '.').'.*') ? 'active' : '' }}" href="{{ route($item['route']) }}">
                    <span class="nav-icon icon-{{ $item['icon'] }}"></span><span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="user-card">
            <span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
            <div class="user-details"><strong>{{ auth()->user()->name }}</strong><small>{{ $layoutAssignment->role->name_ar }}</small></div>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" aria-label="تسجيل الخروج">↪</button></form>
        </div>
    </aside>
    <button class="sidebar-overlay" id="sidebarOverlay" type="button" aria-label="إغلاق القائمة"></button>
    <main class="main">
        <header class="topbar">
            <div class="title-wrap">
                <button class="menu-toggle" id="menuToggle" type="button" aria-label="فتح القائمة">☰</button>
                <div><span class="eyebrow">مساحة العمل</span><h1>@yield('page-title')</h1></div>
            </div>
            <div class="page-actions">
                @yield('page-actions')
                <a class="notification-link" href="{{ route('notifications.index') }}" aria-label="الإشعارات"><span>♢</span>@if($unreadNotifications)<b>{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</b>@endif</a>
            </div>
        </header>
        <section class="content">
            @if (session('success'))<div class="flash success">{{ session('success') }}</div>@endif
            @if ($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
            @yield('content')
        </section>
    </main>
</div>
@stack('scripts')
<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    document.getElementById('menuToggle').addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
    overlay.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
</script>
</body>
</html>
