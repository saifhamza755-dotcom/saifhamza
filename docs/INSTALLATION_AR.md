# دليل تثبيت وتشغيل منصة الجودة

## المتطلبات

- PHP 8.2 أو أحدث مع إضافات MySQL وmbstring وopenssl وfileinfo وzip.
- Composer 2.
- MySQL أو MariaDB.
- خادم ويب Apache أو Nginx، ويمكن استخدام XAMPP محليًا.

## التثبيت المحلي

1. انسخ المشروع إلى الجهاز.
2. نفّذ `composer install` داخل مجلد المشروع.
3. انسخ `.env.example` إلى `.env` ولا تشارك ملف `.env` مع أي شخص.
4. أنشئ قاعدة بيانات باسم `yemen_qa` واضبط بيانات الاتصال في `.env`.
5. نفّذ `php artisan key:generate`.
6. نفّذ `php artisan migrate --seed` لإنشاء الجداول والحسابات التجريبية.
7. نفّذ `php artisan serve` ثم افتح `http://127.0.0.1:8000`.

## التحقق

- شغّل `php artisan test`.
- شغّل `php vendor/bin/pint --test` على Linux/macOS أو `php vendor\bin\pint --test` على Windows.
- تأكد أن `storage` و`bootstrap/cache` قابلان للكتابة على الخادم.

## حسابات التطوير

الحسابات: `admin` و`central` و`university` و`college` و`dean` و`evaluator`.
كلمة المرور التجريبية: `Demo@1234`. يجب حذفها أو تغييرها قبل النشر.
