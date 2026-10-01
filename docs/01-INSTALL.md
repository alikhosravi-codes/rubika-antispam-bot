# 01 — نصب کامل روی هاست

## 1. آپلود فایل‌ها

فایل ZIP نسخه کامل را در مسیر دلخواه آپلود کن. مثال:

```bash
cd ~/public_html
mkdir -p spam
cd spam
unzip rubika-group-manager-v89-FULL-MERGED.zip
```

اگر فایل‌ها داخل یک پوشه اضافه Extract شدند، محتوای همان پوشه را به مسیر اصلی ربات منتقل کن.

## 2. سطح دسترسی پوشه‌ها

```bash
cd ~/public_html/spam
mkdir -p storage logs backups data
chmod 755 storage logs backups data
```

اگر لاگ نوشته نشد، مالکیت پوشه را با کاربر PHP هماهنگ کن. فقط در محیط کنترل‌شده از دسترسی بازتر استفاده کن.

```bash
chmod 660 logs/bot.log
```

## 3. نصب با setup.php

یک بار این آدرس را باز کن تا کلید نصب مخصوص همین هاست ساخته شود:

```text
https://DOMAIN.com/spam/setup.php
```

فایل `storage/setup.key` را از File Manager یا SSH بخوان، سپس باز کن:

```text
https://DOMAIN.com/spam/setup.php?key=کلید_داخل_فایل
```

اگر پیام زیر آمد:

```text
دسترسی نصب مجاز نیست
کلید نصب را از storage/setup.key بخوان.
```

یعنی کلید نصب را در URL ننوشته‌ای یا اشتباه نوشته‌ای. این کلید بعد از نصب موفق حذف می‌شود.

## 4. تنظیمات مهم داخل نصب

```text
bot_token        توکن ربات روبیکا
owner_id         آیدی مالک اصلی ربات
database         SQLite یا MySQL
webhook_key      بهتر است تصادفی و طولانی باشد
cron_key         بهتر است با webhook_key فرق داشته باشد
```

برای نصب ساده، SQLite کافی است. اگر هاست `pdo_sqlite` ندارد، از MySQL استفاده کن.

## 5. تست config.php

بعد از نصب:

```bash
php -r '$c=require "config.php"; echo "bot_token=".(!empty($c["bot_token"])?"OK":"EMPTY").PHP_EOL; echo "webhook_key=".($c["webhook_key"] ?? "EMPTY").PHP_EOL; echo "cron_key=".($c["cron_key"] ?? "EMPTY").PHP_EOL;'
```

باید `bot_token=OK` بگیری.

## 6. بررسی یا ثبت مجدد وبهوک

نصاب باید هر دو endpoint را ثبت کرده باشد. برای ثبت مجدد:

```bash
WEBHOOK_KEY=$(php -r '$c=require "config.php"; echo $c["webhook_key"] ?? $c["cron_key"] ?? "";')
php set_webhook_cli.php "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY"
```

خروجی سالم معمولاً شامل این است:

```text
updateBotEndpoints / main updates/messages | HTTP 200
{"status":"OK","data":{"status":"Done"}}

updateBotEndpoints / inline button clicks | HTTP 200
{"status":"OK","data":{"status":"Done"}}

✅ WEBHOOK SET OK
```

موفقیت فقط زمانی اعلام می‌شود که هر دو `ReceiveUpdate` و `ReceiveInlineMessage` ثبت شده باشند.

## 7. تست وبهوک

```bash
curl -i -X POST "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY" \
-H "Content-Type: application/json" \
-d '{}'
```

جواب سالم:

```json
{"ok":true,"ignored":true}
```

## 8. پاکسازی بعد از نصب

```bash
rm -f setup.php setup_v80.php
```

اگر ابزار انتقال لازم نداری:

```bash
rm -f migration_export.php migration_import.php
```

## 9. تنظیم کرون پیام همگانی

فایل کامل کرون در `docs/03-CRON.md` توضیح داده شده است.
