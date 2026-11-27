<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>منصة اليمن لتقييم وضمان جودة واعتماد مؤسسات التعليم العالي</title>
    <link rel="stylesheet" href="{{ asset('platform/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('platform/css/ui-refresh.css') }}">
    <link rel="stylesheet" href="{{ asset('platform/css/workflow.css') }}">
    <link rel="stylesheet" href="{{ asset('platform/css/theme-2026.css') }}">
</head>
<body>
    <div id="app"></div>
    <script>
        window.PLATFORM = @json($platformConfig);
    </script>
    {{-- يغيّر رقم الإصدار مع تعديل الملف حتى لا يحتفظ المتصفح بنسخة قديمة من واجهة الدخول. --}}
    <script src="{{ asset('platform/js/data.js') }}?v={{ filemtime(public_path('platform/js/data.js')) }}"></script>
    <script src="{{ asset('platform/js/core.js') }}?v={{ filemtime(public_path('platform/js/core.js')) }}"></script>
    <script src="{{ asset('platform/js/app.js') }}?v={{ filemtime(public_path('platform/js/app.js')) }}"></script>
</body>
</html>
