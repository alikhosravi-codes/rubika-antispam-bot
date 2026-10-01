# Rubika Group Manager PHP — نسخه کامل v89

این بسته شامل سورس کامل ربات مدیریت گروه روبیکا به‌همراه پچ v89، نصاب، ابزار ست‌وبهوک، ابزار انتقال هاست، کرون پیام همگانی، داکیومنت و فایل‌های لازم برای اجرای مستقیم روی هاست PHP است.

## تغییرات نسخه v89

در v89 مالک و ادمین‌های داخلی گروه از قفل‌ها معاف هستند و تشخیص رسانه، به‌خصوص GIFهایی که به شکل MP4 بدون صدا می‌رسند، دقیق‌تر شده است. اصلاحات نسخه‌های قبلی نیز حفظ شده‌اند.

## فایل‌های مهم

```text
config.sample.php          نمونه تنظیمات
setup.php                  پنل نصب
setup_v80.php              نام سازگار قدیمی برای setup.php
webhook.php                ورودی اصلی وبهوک
set_webhook_cli.php        ست‌کردن وبهوک از SSH
cron_broadcast.php         اجرای صف پیام همگانی
migration_export.php       خروجی گرفتن برای انتقال هاست
migration_import.php       وارد کردن بکاپ روی هاست جدید
src/                       هسته برنامه
storage/                   دیتابیس SQLite و فایل‌های داخلی
data/                      فایل‌های تنظیمی مثل talker.json
logs/                      لاگ خطا و دیباگ
backups/                   بکاپ‌های انتقال/پشتیبان
systemd/                   نمونه سرویس worker
```

## راهنمای نصب

راهنمای کامل نصب در این فایل است:

```text
docs/01-INSTALL.md
```

## راهنمای وبهوک و دکمه‌های شیشه‌ای

راهنمای کامل در این فایل است:

```text
docs/02-WEBHOOK.md
```

## راهنمای انتقال هاست

راهنمای کامل در این فایل است:

```text
docs/04-MIGRATION.md
```

## راهنمای رفع خطا

اگر دکمه‌ها کار نکردند، لاگ نیامد، خطای 404 داشتی، SSL مشکل داشت، webhook جواب نداد یا cron اجرا نشد، این فایل را بخوان:

```text
docs/05-TROUBLESHOOTING.md
```

## تست سلامت سریع

داخل پوشه سورس:

```bash
php -l webhook.php
php -l setup.php
php -l set_webhook_cli.php
php -l migration_export.php
php -l migration_import.php
php -l src/BotApp.php
php -l src/Database.php
```

## بعد از نصب

حتماً این کارها را انجام بده:

```bash
chmod 755 storage logs backups data
rm -f setup.php setup_v80.php
```

اگر ابزار انتقال را لازم نداری:

```bash
rm -f migration_export.php migration_import.php
```
