# 09 — آپدیت و اعمال پچ

## قانون اصلی

هیچ‌وقت `config.php` و دیتابیس را با فایل جدید جایگزین نکن، مگر دقیقاً بدانی چه می‌کنی.

فایل‌های حساس که معمولاً نباید جایگزین شوند:

```text
config.php
storage/bot.sqlite
logs/bot.log
backups/
data/talker.json اگر پاسخ‌های سفارشی داری
```

## اعمال پچ

اگر پچ فقط چند فایل دارد، همان فایل‌ها را جایگزین کن. مثال:

```text
src/BotApp.php
src/BotAppHelpers.php
```

بعد syntax را تست کن:

```bash
php -l src/BotApp.php
php -l src/BotAppHelpers.php
```

## آپدیت کامل سورس بدون پاک شدن دیتا

1. از هاست بکاپ بگیر:

```bash
php migration_export.php
```

2. فایل‌های جدید را روی فایل‌های قبلی Extract کن.
3. `config.php` قدیمی را نگه دار.
4. دیتابیس `storage/bot.sqlite` را نگه دار.
5. وبهوک را دوباره ست کن:

```bash
WEBHOOK_KEY=$(php -r '$c=require "config.php"; echo $c["webhook_key"] ?? $c["cron_key"] ?? "";')
php set_webhook_cli.php "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY"
```

## بعد از آپدیت

داخل روبیکا پنل جدید باز کن. روی دکمه‌های قدیمی تست نکن، چون ممکن است callback قدیمی داشته باشند.

## اگر بعد از آپدیت خطا آمد

```bash
tail -n 150 logs/bot.log
```

و فایل‌هایی که جایگزین کردی را بررسی کن.
