# 03 — کرون و پیام همگانی

## چرا کرون لازم است؟

ارسال پیام همگانی مستقیم داخل وبهوک باعث کندی و تایم‌اوت می‌شود. این سورس پیام‌های همگانی را در صف ذخیره می‌کند و `cron_broadcast.php` هر بار تعدادی را ارسال می‌کند.

## فایل اصلی کرون پیام همگانی

```text
cron_broadcast.php
```

## گرفتن کلید کرون

```bash
cd ~/public_html/spam
php -r '$c=require "config.php"; echo $c["cron_key"].PHP_EOL;'
```

## اجرای دستی کرون

```bash
php cron_broadcast.php
```

اگر از URL استفاده می‌کنی:

```text
https://DOMAIN.com/spam/cron_broadcast.php?key=CRON_KEY
```

## مشاهده وضعیت صف

```text
https://DOMAIN.com/spam/cron_broadcast.php?key=CRON_KEY&status=1
```

با کلید ضعیف این URL را عمومی نکن.

## کرون پیشنهادی برای cPanel

اگر مسیر PHP این است:

```text
/usr/local/bin/php
```

کرون:

```cron
* * * * * /usr/local/bin/php /home/USER/public_html/spam/cron_broadcast.php >/dev/null 2>&1
```

اگر مسیر PHP فرق دارد:

```bash
which php
```

بعد مسیر را در cron جایگزین کن.

## اگر فقط URL Cron داری

```cron
* * * * * /usr/bin/curl -fsS "https://DOMAIN.com/spam/cron_broadcast.php?key=CRON_KEY" >/dev/null 2>&1
```

## تنظیم سرعت ارسال

داخل `config.php`:

```php
'broadcast_queue_limit' => 20,
'broadcast_worker_interval' => 15,
'broadcast_max_attempts' => 3,
```

توضیح:

```text
broadcast_queue_limit       تعداد ارسال در هر اجرای کرون
broadcast_worker_interval   فاصله داخلی/سازگاری worker
broadcast_max_attempts      تعداد تلاش مجدد برای خطاهای موقت
```

برای هاست ضعیف مقدار `broadcast_queue_limit` را پایین‌تر بگذار.

## فرق cron.php و cron_broadcast.php

```text
cron_broadcast.php   برای صف پیام همگانی؛ روی هاست معمولی همین کافی است.
cron.php             برای polling/worker قدیمی یا اجرای خاص؛ در نصب webhook معمولاً لازم نیست.
worker.php           برای اجرای دائمی با systemd، اگر سرور اختصاصی/VPS داری.
```

## تست اینکه کرون کار می‌کند

```bash
tail -f logs/bot.log
```

بعد یک پیام همگانی از پنل بساز و منتظر اجرای کرون بمان.
