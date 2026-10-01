# 00 — شروع سریع

این فایل برای شروع سریع است. اگر اولین بار سورس را روی هاست می‌بری، همین مراحل کافی است.

## پیش‌نیازها

```text
PHP 8.1 یا بالاتر
curl extension
pdo extension
pdo_sqlite برای SQLite یا pdo_mysql برای MySQL
دسترسی SSH بهتر است، ولی نصب از مرورگر هم ممکن است
SSL فعال روی دامنه
```

## مسیر پیشنهادی نصب

اگر می‌خواهی ربات داخل پوشه `spam` باشد:

```text
/home/USER/public_html/spam
https://DOMAIN.com/spam/
```

اگر مستقیم روی دامنه باشد:

```text
/home/USER/public_html
https://DOMAIN.com/
```

## نصب سریع

1. ZIP سورس را روی هاست آپلود و Extract کن.
2. مطمئن شو فایل‌های زیر داخل پوشه هستند:

```text
setup.php
webhook.php
config.sample.php
src/
storage/
logs/
```

3. یک بار آدرس نصب را بدون کلید باز کن:

```text
https://DOMAIN.com/spam/setup.php
```

کلید را از `storage/setup.key` بخوان و سپس باز کن:

```text
https://DOMAIN.com/spam/setup.php?key=کلید_داخل_فایل
```

4. اطلاعات را وارد کن:

```text
bot_token
owner_id
database type
```

5. نصاب هر دو endpoint را ثبت می‌کند. برای ثبت مجدد وبهوک:

```bash
cd ~/public_html/spam
WEBHOOK_KEY=$(php -r '$c=require "config.php"; echo $c["webhook_key"] ?? $c["cron_key"] ?? "";')
php set_webhook_cli.php "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY"
```

6. تست وبهوک:

```bash
curl -i -X POST "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY" \
-H "Content-Type: application/json" \
-d '{}'
```

اگر دیدی:

```json
{"ok":true,"ignored":true}
```

مسیر وبهوک سالم است.

## بعد از نصب

```bash
rm -f setup.php setup_v80.php
chmod 755 storage logs backups data
```

## اگر دکمه‌های شیشه‌ای کار نکردند

فایل زیر را بخوان:

```text
docs/02-WEBHOOK.md
docs/05-TROUBLESHOOTING.md
```
