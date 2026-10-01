# 05 — رفع خطاهای رایج

## 1. آدرس setup باز نمی‌شود و 404 می‌دهد

اول مسیر فایل را پیدا کن:

```bash
find /home/$USER -maxdepth 6 -type f \( -name "setup.php" -o -name "webhook.php" -o -name "config.php" \) 2>/dev/null
```

اگر سورس داخل `public_html/spam` بود، آدرس باید این باشد:

```text
https://DOMAIN.com/spam/setup.php
```

اگر مستقیم داخل `public_html` بود:

```text
https://DOMAIN.com/setup.php
```

## 2. پیام «دسترسی نصب مجاز نیست»

یعنی `?key=...` را وارد نکرده‌ای یا کلید اشتباه است. فایل `storage/setup.key` را از File Manager یا SSH بخوان.

نمونه درست:

```text
https://DOMAIN.com/spam/setup.php?key=کلید_داخل_storage/setup.key
```

## 3. وبهوک تست می‌شود ولی ربات جواب نمی‌دهد

تست کن:

```bash
WEBHOOK_KEY=$(php -r '$c=require "config.php"; echo $c["webhook_key"] ?? $c["cron_key"] ?? "";')
curl -i -X POST "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY" -H "Content-Type: application/json" -d '{}'
```

اگر `HTTP 200` و `ignored=true` آمد، فایل باز است. بعد وبهوک را ست کن:

```bash
php set_webhook_cli.php "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY"
```

## 4. دکمه‌های شیشه‌ای کار نمی‌کنند

1. وبهوک را دوباره ست کن:

```bash
php set_webhook_cli.php "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY"
```

2. debug خام را روشن کن:

```bash
php -r '$p="config.php"; $s=file_get_contents($p); $s=str_replace("\'debug\' => false", "\'debug\' => true", $s); if(strpos($s,"debug_raw_webhook")===false){$s=str_replace("\'debug\' => true,", "\'debug\' => true,\n    \'debug_raw_webhook\' => true,", $s);} else {$s=str_replace("\'debug_raw_webhook\' => false", "\'debug_raw_webhook\' => true", $s);} file_put_contents($p,$s);'
```

3. لاگ را ببین:

```bash
mkdir -p logs
touch logs/bot.log
chmod 666 logs/bot.log
: > logs/bot.log
tail -f logs/bot.log
```

4. داخل روبیکا پنل جدید بساز و روی دکمه تازه کلیک کن.

## 5. لاگ نوشته نمی‌شود

```bash
mkdir -p logs
touch logs/bot.log
chmod 666 logs/bot.log
```

اگر باز هم لاگ نیامد، احتمالاً درخواست به webhook نمی‌رسد.

## 6. خطای SSL

اگر curl خطای SSL داد، اول AutoSSL هاست را برای دامنه فعال کن. روبیکا معمولاً با SSL نامعتبر وبهوک را درست صدا نمی‌زند.

تست:

```bash
curl -I https://DOMAIN.com/spam/webhook.php
```

## 7. 404 cPanel حتی با فایل تست

فایل تست بساز:

```bash
echo OK > ~/public_html/spam/root-test.txt
curl -i https://DOMAIN.com/spam/root-test.txt
```

اگر 404 cPanel آمد، مشکل از سورس نیست؛ vhost دامنه یا document root هاست خراب است.

## 8. config.php پیدا نمی‌شود

داخل مسیر سورس باید `config.php` باشد. اگر نیست، setup کامل نشده یا import انجام نشده.

## 9. SQLite خطا می‌دهد

چک کن:

```bash
php -m | grep -i sqlite
```

اگر چیزی نیامد، یا باید `pdo_sqlite` را فعال کنی یا MySQL استفاده کنی.

اگر افزونه فعال است ولی خطای قفل/فایل‌سیستم می‌بینی، در `config.php` مقدار
`database.sqlite_journal_mode` را از `WAL` به `DELETE` تغییر بده.

## 10. MySQL وصل نمی‌شود

اطلاعات داخل `config.php` را بررسی کن:

```text
host
database
username
password
port
```

بعد از اصلاح، دوباره ربات را تست کن.
