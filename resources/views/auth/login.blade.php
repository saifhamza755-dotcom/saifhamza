<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل الدخول | منصة اليمن للجودة والاعتماد</title>
    <link rel="stylesheet" href="{{ asset('css/platform.css') }}">
</head>
<body>
<main class="login-page">
    <section class="login-panel">
        <div class="login-card">
            <div class="login-brand"><span class="brand-mark">ج</span><div><strong>منصة الجودة والاعتماد</strong><small>التعليم العالي · اليمن</small></div></div>
            <h1>تسجيل الدخول</h1>
            <p class="subtitle">أدخل بيانات حسابك للوصول إلى مساحة عملك.</p>

            @if ($errors->any())
                <div class="error-box">{{ $errors->first() }}</div>
            @endif

            <form method="post" action="{{ route('login.store') }}">
                @csrf
                <div class="field">
                    <label for="login">اسم المستخدم أو البريد الإلكتروني</label>
                    <input id="login" name="login" value="{{ old('login') }}" required autofocus autocomplete="username">
                </div>
                <div class="field">
                    <label for="password">كلمة المرور</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password">
                </div>
                <label class="check"><input type="checkbox" name="remember" value="1"> تذكرني على هذا الجهاز</label>
                <button class="primary-button" type="submit">تسجيل الدخول</button>
            </form>

            <div class="demo-box">
                <strong>حسابات التطوير:</strong><br>
                admin، central، university، college، dean، evaluator<br>
                كلمة المرور المؤقتة: <b>Demo@1234</b>
            </div>
        </div>
    </section>

    <section class="login-hero">
        <span class="badge"><i></i> منظومة وطنية رقمية</span>
        <h2>معًا نحو تعليم عالٍ أكثر جودة وتميزًا</h2>
        <p>منصة موحدة تدعم دورة الجودة كاملة، من التقويم الذاتي والأدلة إلى التقييم والتحسين والاعتماد.</p>
        <div class="hero-points">
            <div>01 &nbsp; تقويم ذاتي موثق بالأدلة</div>
            <div>02 &nbsp; متابعة دقيقة للتقييم والتحسين</div>
            <div>03 &nbsp; قرارات اعتماد مبنية على البيانات</div>
        </div>
    </section>
</main>
</body>
</html>
