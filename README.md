# Rubika Group Manager PHP — v89 FULL

نسخه کامل v89 بر پایه سورس کامل قبلی ساخته شده و پچ v89 مربوط به معافیت قطعی مالک/ادمین از قفل‌ها و اصلاح تشخیص رسانه (به‌ویژه GIF/MP4) روی آن ادغام شده است. امکانات نصب، وبهوک، کرون، دیتابیس و ابزارهای انتقال نسخه کامل حفظ شده‌اند.

## از کجا شروع کنم؟

برای نصب ساده از این فایل‌ها استفاده کن:

```text
setup.php                  پنل نصب تحت وب
setup_v80.php              نام سازگار قدیمی؛ منطق اصلی در setup.php
set_webhook_cli.php        ست‌کردن وبهوک از ترمینال
migration_export.php       گرفتن بکاپ انتقال از هاست قدیمی
migration_import.php       ایمپورت بکاپ روی هاست جدید
cron_broadcast.php         کرون صف پیام همگانی
webhook.php                ورودی اصلی ربات و دکمه‌های شیشه‌ای
```

راهنمای کامل داخل پوشه `docs` است:

```text
docs/00-START-HERE.md              شروع سریع
docs/01-INSTALL.md                 نصب روی هاست
docs/02-WEBHOOK.md                 ست‌کردن وبهوک و دکمه‌های شیشه‌ای
docs/03-CRON.md                    کرون پیام همگانی و worker
docs/04-MIGRATION.md               انتقال کامل از هاست قدیمی به هاست جدید
docs/05-TROUBLESHOOTING.md         رفع خطاهای رایج
docs/06-SECURITY.md                نکات امنیتی بعد از نصب
docs/07-STRUCTURE.md               ساختار فایل‌ها و پوشه‌ها
docs/08-COMMANDS.md                راهنمای کلی دستورات ربات
docs/09-UPDATE-PATCH.md            آپدیت و جایگزینی پچ‌ها
```

## نصب خیلی سریع

1. سورس را داخل مسیر موردنظر آپلود کن، مثلاً:

```bash
~/public_html/spam
```

2. یک بار آدرس زیر را باز کن تا کلید یک‌بارمصرف ساخته شود:

```text
https://DOMAIN.com/spam/setup.php
```

کلید را از فایل `storage/setup.key` در File Manager یا SSH بخوان، سپس باز کن:

```text
https://DOMAIN.com/spam/setup.php?key=کلید_داخل_storage/setup.key
```

3. نصاب هر دو endpoint را خودکار ثبت می‌کند. برای ثبت مجدد از ترمینال:

```bash
cd ~/public_html/spam
WEBHOOK_KEY=$(php -r '$c=require "config.php"; echo $c["webhook_key"] ?? $c["cron_key"] ?? "";')
php set_webhook_cli.php "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY"
```

4. تست وبهوک:

```bash
curl -i -X POST "https://DOMAIN.com/spam/webhook.php?key=$WEBHOOK_KEY" \
-H "Content-Type: application/json" \
-d '{}'
```

جواب سالم معمولاً این است:

```json
{"ok":true,"ignored":true}
```

5. برای پیام همگانی، کرون دقیقه‌ای بگذار:

```bash
* * * * * /usr/local/bin/php /home/USER/public_html/spam/cron_broadcast.php >/dev/null 2>&1
```

یا اگر فقط کرون URL داری:

```text
https://DOMAIN.com/spam/cron_broadcast.php?key=CRON_KEY
```

## انتقال به هاست جدید

روی هاست قدیمی:

```bash
cd ~/public_html/spam
php migration_export.php
```

روی هاست جدید:

```bash
cd ~/public_html/spam
php migration_import.php rubika_migration_YYYYMMDD_HHMMSS.zip "https://DOMAIN.com/spam/webhook.php?key=WEBHOOK_KEY"
```

`WEBHOOK_KEY` را همین‌طور بنویس؛ ابزار خودش کلید واقعی داخل تنظیمات بکاپ را جایگزین می‌کند.

## نکته امنیتی مهم

بعد از نصب یا انتقال موفق، این فایل‌ها را از روی هاست حذف کن یا حداقل دسترسی‌شان را ببند:

```bash
rm -f setup.php setup_v80.php migration_export.php migration_import.php set_webhook_cli.php
rm -f rubika_migration_*.zip
```

فایل‌های بکاپ انتقال شامل توکن ربات و دیتابیس هستند؛ عمومی‌شان نکن.

## اولین نصب گروه

از نسخه v86، وقتی ربات به گروه اضافه شود، شناسه شخص اضافه‌کننده از رویداد روبیکا جدا از شناسه خود ربات استخراج و همان شخص به‌عنوان مدیر داخلی گروه ثبت می‌شود. گروه نیز خودکار فعال می‌شود و بعد از مدیرشدن ربات و دادن مجوز حذف پیام، قفل‌ها کار می‌کنند.

اگر API روبیکا شناسه اضافه‌کننده را نفرستد، یکی از مدیران گروه باید فقط یک‌بار دستور `نصب` را داخل گروه اجرا کند. گزینه `install_security.allow_group_manager_install` به‌صورت پیش‌فرض فعال است. برای بازگرداندن محدودیت قدیمی، آن را `false` بگذارید؛ سپس `owner_only_first_install` و `allowed_installer_ids` اعمال می‌شوند.

اگر وب‌سرور Nginx است، نمونه `nginx/rubika-group-manager.conf.example` را با مسیر نصب خود هماهنگ و داخل تنظیمات دامنه قرار بده.
