# راهنمای کامل انتقال هاست — Rubika Group Manager

این راهنما برای زمانی است که می‌خواهی ربات، تنظیمات، دیتابیس و توکن را از هاست قدیمی به هاست جدید منتقل کنی بدون اینکه دوباره همه چیز را دستی نصب کنی.

## خلاصه سریع

روی هاست قدیمی:

```bash
cd ~/public_html/spam
php migration_export.php
```

فایل ساخته‌شده را از پوشه `backups` دانلود کن و روی هاست جدید کنار سورس آپلود کن.

روی هاست جدید:

```bash
cd ~/public_html/spam
php migration_import.php rubika_migration_YYYYMMDD_HHMMSS.zip "https://DOMAIN.com/spam/webhook.php?key=WEBHOOK_KEY"
```

`WEBHOOK_KEY` را تغییر نده. ابزار خودش از تنظیمات مقصد مقدار واقعی را می‌خواند.

## چه چیزهایی منتقل می‌شود؟

```text
config.json (تنظیمات قابل انتقال بدون اجرای فایل PHP)
توکن ربات
owner_id
cron_key
webhook_key
دیتابیس SQLite یا خروجی MySQL
گروه‌ها
قفل‌ها
ادمین‌های داخلی
کاربران معتمد
کلمات بد
صف پیام همگانی
فایل‌های data مثل talker.json
```

مسیرهای SQLite، لاگ و بکاپ هنگام import برای هاست جدید بازسازی می‌شوند. برای بکاپ قدیمی v84 بهتر است ابتدا setup را روی هاست جدید اجرا و سپس `--keep-config` استفاده کنی.

## بعد از انتقال

1. وبهوک را تست کن:

```bash
WEBHOOK_KEY=$(php -r '$c=require "config.php"; echo $c["webhook_key"] ?? $c["cron_key"] ?? "";')
curl -i -X POST "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY" -H "Content-Type: application/json" -d '{}'
```

2. کرون را روی هاست جدید تنظیم کن.
3. فایل‌های انتقال را حذف کن:

```bash
rm -f migration_export.php migration_import.php
rm -f rubika_migration_*.zip
```

## نکته امنیتی

بکاپ انتقال شامل توکن ربات، کلیدها و دیتابیس است. این فایل را در مسیر عمومی هاست نگه ندار.
