# 02 — وبهوک و دکمه‌های شیشه‌ای

## وبهوک چیست؟

روبیكا وقتی پیام یا کلیک دکمه شیشه‌ای دریافت می‌شود، باید اطلاعات را به فایل زیر بفرستد:

```text
webhook.php
```

آدرس نمونه:

```text
https://DOMAIN.com/spam/webhook.php?key=WEBHOOK_KEY
```

## ست‌کردن وبهوک با SSH

```bash
cd ~/public_html/spam
WEBHOOK_KEY=$(php -r '$c=require "config.php"; echo $c["webhook_key"] ?? $c["cron_key"] ?? "";')
php set_webhook_cli.php "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY"
```

این ابزار دو endpoint اصلی را ست می‌کند:

```text
ReceiveUpdate          پیام‌ها و آپدیت‌های معمولی
ReceiveInlineMessage   کلیک دکمه‌های شیشه‌ای
```

## تست دستی webhook.php

```bash
curl -i -X POST "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY" \
-H "Content-Type: application/json" \
-d '{}'
```

اگر `HTTP 200` و `{"ok":true,"ignored":true}` گرفتی، فایل وبهوک از وب قابل دسترس است.

## فعال‌کردن لاگ خام برای دکمه‌ها

برای عیب‌یابی:

```bash
php -r '
$p="config.php";
$s=file_get_contents($p);
$s=preg_replace("/\'debug\'\s*=>\s*false,/", "\'debug\' => true,", $s, 1);
if (strpos($s, "debug_raw_webhook") === false) {
    $s=preg_replace("/\'debug\'\s*=>\s*true,/", "\'debug\' => true,\n    \'debug_raw_webhook\' => true,", $s, 1);
} else {
    $s=preg_replace("/\'debug_raw_webhook\'\s*=>\s*false,/", "\'debug_raw_webhook\' => true,", $s, 1);
}
file_put_contents($p,$s);
'
```

بعد:

```bash
mkdir -p logs
touch logs/bot.log
chmod 666 logs/bot.log
: > logs/bot.log
tail -f logs/bot.log
```

حالا داخل روبیکا یک پنل جدید باز کن و روی دکمه تازه کلیک کن.

## تشخیص مشکل دکمه‌ها

### حالت 1: پیام معمولی لاگ دارد، دکمه لاگ ندارد

وبهوک پیام‌ها وصل است، ولی endpoint دکمه شیشه‌ای یا سمت روبیکا مشکل دارد. دوباره `set_webhook_cli.php` را اجرا کن.

### حالت 2: نه پیام لاگ دارد نه دکمه

وبهوک به سورس نمی‌رسد. مسیر، SSL، دامنه، یا کلید اشتباه است.

### حالت 3: لاگ `webhook_raw_received` آمد ولی دکمه عمل نکرد

فرمت callback روبیکا با نرمالایزر نمی‌خواند و باید `src/UpdateNormalizer.php` بر اساس لاگ خام پچ شود.

## نکته مهم

روی دکمه‌های قدیمی تست نکن. بعد از هر ست‌وبهوک یا آپدیت، داخل ربات یک پنل جدید باز کن و دکمه تازه را بزن.
