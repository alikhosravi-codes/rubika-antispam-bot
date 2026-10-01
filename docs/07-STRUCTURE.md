# 07 — ساختار فایل‌ها

## ریشه سورس

```text
bootstrap.php             لود اولیه برنامه
webhook.php               ورودی وبهوک روبیکا
setup.php                 نصب تحت وب
setup_v80.php             نام قدیمی سازگار؛ setup.php را لود می‌کند
set_webhook_cli.php       ست‌کردن وبهوک با SSH
cron_broadcast.php        صف پیام همگانی
cron.php                  کرون/پولینگ قدیمی یا خاص
worker.php                اجرای worker
config.sample.php         نمونه تنظیمات
config.php                تنظیمات واقعی، بعد از نصب ساخته می‌شود
.htaccess                 محافظت از فایل‌های حساس
nginx/                    نمونه قانون مسدودسازی فایل‌های حساس در Nginx
tests/selftest.php        تست سریع دیتابیس، بکاپ، وبهوک و قفل لینک
```

## پوشه src

```text
src/BotApp.php            منطق اصلی ربات و پنل‌ها
src/BotAppHelpers.php     متدهای کمکی ربات و صفحه‌بندی‌ها
src/Database.php          دیتابیس، گروه‌ها، تنظیمات، صف‌ها
src/RubikaClient.php      ارتباط با API روبیکا
src/UpdateNormalizer.php  تبدیل آپدیت خام روبیکا به فرمت داخلی
src/Detector.php          تشخیص قفل‌ها مثل لینک، عکس، فوروارد و غیره
src/Talker.php            پاسخگوی هوشمند/کلمات سفارشی
src/MetadataBuilder.php   ساخت metadata متن‌ها
src/GroupStatsService.php آمار گروه
src/Support.php           توابع عمومی
src/Autoload.php          لودر کلاس‌ها
```

## پوشه storage

برای دیتابیس SQLite و فایل‌های داخلی استفاده می‌شود.

```text
storage/bot.sqlite
```

## پوشه logs

لاگ‌ها:

```text
logs/bot.log
```

برای دیباگ:

```bash
tail -f logs/bot.log
```

## پوشه backups

بکاپ‌های انتقال و پشتیبان داخل این پوشه ساخته می‌شوند.

## پوشه data

تنظیمات جانبی مثل پاسخگوی هوشمند:

```text
data/talker.json
```

## پوشه docs

راهنمای کامل نصب، انتقال، کرون، دیباگ و امنیت.
