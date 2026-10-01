# 04 — انتقال هاست بدون دردسر

این بخش برای انتقال سورس و دیتای ربات از هاست قدیمی به هاست جدید است.

## فایل‌های انتقال

```text
migration_export.php     روی هاست قدیمی اجرا می‌شود
migration_import.php     روی هاست جدید اجرا می‌شود
backups/                 محل ذخیره فایل خروجی
```

## مرحله 1 — خروجی از هاست قدیمی

```bash
cd ~/public_html/spam
php migration_export.php
```

خروجی داخل پوشه `backups` ساخته می‌شود:

```text
backups/rubika_migration_YYYYMMDD_HHMMSS.zip
```

این فایل شامل اطلاعات حساس است:

```text
config.json امن برای انتقال تنظیمات
توکن ربات
webhook_key
cron_key
SQLite database یا خروجی MySQL
فایل‌های data
```

## مرحله 2 — آماده‌سازی هاست جدید

روی هاست جدید، سورس کامل را Extract کن:

```bash
cd ~/public_html
mkdir -p spam
cd spam
unzip RubikaGroupManagerPHP_FULL_v85_fixed.zip
```

فایل بکاپ ساخته‌شده را همینجا آپلود کن.

## مرحله 3 — ایمپورت روی هاست جدید

```bash
php migration_import.php rubika_migration_YYYYMMDD_HHMMSS.zip "https://DOMAIN.com/spam/webhook.php?key=WEBHOOK_KEY"
```

کلمه `WEBHOOK_KEY` را تغییر نده. ابزار خودش کلید واقعی داخل تنظیمات مقصد را می‌خواند.

ابزار مسیرهای مطلق SQLite، لاگ و بکاپ را متناسب با هاست جدید بازسازی می‌کند و داده‌ها را داخل تراکنش وارد می‌کند.

### انتقال بکاپ قدیمی v84

روش امن پیشنهادی: ابتدا روی هاست جدید `setup.php` را اجرا کن و سپس:

```bash
php migration_import.php OLD_V84_BACKUP.zip "https://DOMAIN.com/spam/webhook.php?key=WEBHOOK_KEY" --keep-config
```

فقط اگر ZIP کاملاً مورد اعتماد است می‌توان از `--allow-legacy-config-php` استفاده کرد.

## مثال

```bash
php migration_import.php rubika_migration_20260709_235959.zip "https://meysammmm.ir/spam/webhook.php?key=WEBHOOK_KEY"
```

## بعد از import

تست config:

```bash
php -r '$c=require "config.php"; echo "bot_token=".(!empty($c["bot_token"])?"OK":"EMPTY").PHP_EOL; echo "webhook_key=".($c["webhook_key"] ?? "EMPTY").PHP_EOL; echo "cron_key=".($c["cron_key"] ?? "EMPTY").PHP_EOL;'
```

تست webhook:

```bash
WEBHOOK_KEY=$(php -r '$c=require "config.php"; echo $c["webhook_key"] ?? $c["cron_key"] ?? "";')
curl -i -X POST "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY" -H "Content-Type: application/json" -d '{}'
```

## پاکسازی امنیتی

بعد از انتقال موفق:

```bash
rm -f migration_export.php migration_import.php
rm -f rubika_migration_*.zip
rm -f backups/rubika_migration_*.zip
```

## اگر MySQL استفاده می‌کنی

اگر اطلاعات اتصال MySQL هاست جدید با قدیم فرق دارد، ابتدا setup را روی هاست جدید اجرا کن و import را با `--keep-config` انجام بده.

## اگر دامنه عوض شده

حتماً وبهوک را با دامنه جدید ست کن:

```bash
WEBHOOK_KEY=$(php -r '$c=require "config.php"; echo $c["webhook_key"] ?? $c["cron_key"] ?? "";')
php set_webhook_cli.php "https://NEW-DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY"
```
