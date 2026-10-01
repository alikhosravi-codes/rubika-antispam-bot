# 06 — نکات امنیتی

## فایل‌های حساس

این فایل‌ها نباید عمومی بمانند:

```text
config.php
storage/*.sqlite
storage/setup.key
logs/*.log
backups/*.zip
migration_export.php
migration_import.php
setup.php
setup_v80.php
set_webhook_cli.php
```

`.htaccess` بخش زیادی را روی Apache می‌بندد، ولی بعد از نصب بهتر است فایل‌های نصب و انتقال را حذف کنی. برای Nginx حتماً نمونه `nginx/rubika-group-manager.conf.example` را اعمال کن.

## بعد از نصب

```bash
rm -f setup.php setup_v80.php
```

اگر ابزار انتقال لازم نداری:

```bash
rm -f migration_export.php migration_import.php
```

اگر ست‌وبهوک انجام شد و دیگر SSH داری:

```bash
rm -f set_webhook_cli.php
```

## کلیدهای قوی

کلیدهای زیر باید طولانی و تصادفی باشند:

```text
webhook_key
cron_key
setup key داخل storage/setup.key که برای هر نصب جدا ساخته می‌شود
```

کلیدهایی مثل `1234`، `1380` یا نام دامنه امن نیستند.

## بکاپ انتقال

فایل `rubika_migration_*.zip` شامل توکن ربات و دیتابیس است. بعد از انتقال پاکش کن:

```bash
rm -f rubika_migration_*.zip
rm -f backups/rubika_migration_*.zip
```

## debug را بعد از رفع مشکل خاموش کن

در حالت عادی:

```php
'debug' => false,
'debug_raw_webhook' => false,
```

وقتی debug روشن باشد، ممکن است اطلاعات فنی داخل لاگ ذخیره شود.

## دسترسی فایل‌ها

پیشنهادی:

```bash
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 640 {} \;
chmod 660 logs/bot.log 2>/dev/null
```

اگر هاست با این سطح دسترسی مشکل داشت، طبق تنظیمات همان هاست اصلاح کن.

## مالک ربات

`owner_id` را درست وارد کن. اگر اشتباه باشد، پنل مدیریتی برای فرد اشتباه باز می‌شود یا مالک اصلی دسترسی نمی‌گیرد.

در v86، اضافه‌کننده ربات به‌صورت خودکار مدیر داخلی همان گروه می‌شود. اگر شناسه اضافه‌کننده در رویداد API موجود نباشد، اولین کاربری که دستور `نصب` را اجرا کند مدیر داخلی می‌شود؛ چون Bot API رسمی روبیکا نقش واقعی فرستنده را همیشه اعلام نمی‌کند. برای محدودکردن دوباره نصب به مالک اصلی، در `config.php` مقدار `install_security.allow_group_manager_install` را `false` قرار بده.
