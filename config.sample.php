<?php
return [
    'config_version' => 89,
    // توکن بات رسمی روبیکا را از BotFather روبیکا بگیرید و اینجا قرار دهید.
    'bot_token' => 'PUT_RUBIKA_BOT_TOKEN_HERE',

    // شناسه مالک اصلی. اولین بار در گروه دستور «آیدی» بزنید یا از پیام‌های دریافتی استخراج کنید.
    'owner_id' => 'PUT_OWNER_USER_ID_HERE',

    // نام نمایشی ربات در پیام نصب و پنل؛ از پنل اصلی هم قابل تغییر است.
    'bot_display_name' => 'ربات مدیریت گروه',

    // اختیاری: Bot ID ربات برای تشخیص اضافه شدن خودکار ربات به گروه.
    'bot_id' => '',

    // مقدار قدیمی برای سازگاری؛ مدیریت از داخل خود ربات انجام می‌شود.
    'admin_password' => 'CHANGE_ME_12345',

    // برای محدود کردن اجرای cron_broadcast.php. در URL به شکل cron_broadcast.php?key=... استفاده می‌شود.
    'cron_key' => 'CHANGE_CRON_KEY_12345',

    // کلید امنیتی webhook.php. نصاب معمولاً این مقدار را برابر cron_key قرار می‌دهد.
    'webhook_key' => 'CHANGE_CRON_KEY_12345',

    // مدیریت از داخل خود ربات انجام می‌شود.
    'panel_url' => '',

    'timezone' => 'Asia/Tehran',
    'debug' => false,
    // برای عیب‌یابی وبهوک/دکمه‌های شیشه‌ای؛ در حالت عادی خاموش بماند.
    'debug_raw_webhook' => false,
    'webhook_max_body_bytes' => 1048576,
    'webhook_dedupe_seconds' => 30,

    // تنظیمات عملکرد. در حالت عادی getGroup فقط JSON سبک جدول groups را می‌خواند.
    // جدول‌های ساخت‌یافته mirror هستند و برای گزارش/بک‌فیل استفاده می‌شوند.
    'performance' => [
        'load_structured_settings_in_get_group' => false,
    ],

    // مالک و ادمین‌های داخلی گروه همیشه از همه قفل‌ها و حذف پیام معاف‌اند.
    // کلید apply_locks_to_privileged فقط برای سازگاری باقی مانده و در v89 نادیده گرفته می‌شود.
    'moderation' => [
        'apply_locks_to_privileged' => false,
        'apply_locks_to_trusted' => false,
    ],

    // بعضی آپدیت‌های رسمی روبیکا برای File فقط شناسه/نام/حجم می‌فرستند.
    // چون GIF روبیکا MP4 بدون صداست، MP4های مبهم با دانلود محدود و بررسی ترک صدا تشخیص داده می‌شوند.
    'media_detection' => [
        'probe_ambiguous_mp4_for_gif' => true,
        'probe_timeout_seconds' => 5,
        'probe_max_bytes' => 524288,
    ],

    // تعداد مقصدهایی که در هر اجرای cron_broadcast.php از صف پیام همگانی ارسال می‌شود.
    'broadcast_queue_limit' => 20,
    'broadcast_worker_interval' => 15,

    // اگر ارسال به یک مقصد موقتاً خطا داد، تا چند بار در اجرای بعدی کرون دوباره تلاش شود.
    'broadcast_max_attempts' => 3,

    // پاکسازی دوره‌ای دیتابیس برای جلوگیری از سنگین شدن state و صف‌های قدیمی همگانی.
    'maintenance_cleanup_interval' => 86400,
    'state_cleanup_days' => 3,
    'broadcast_cleanup_days' => 30,

    // Bot API رسمی روبیکا parse_mode ندارد؛ قالب‌بندی توسط metadata انجام می‌شود.
    // اگر metadata روی متن فارسی/ایموجی جابجا شد، metadata_index_unit را بین utf16 / char تغییر بده.
    'parse_mode' => '',
    'code_meta_type' => 'Mono',
    'metadata_index_unit' => 'utf16',

    // امنیت نصب اولیه گروه.
    // v86: مدیر اضافه‌کننده ربات خودکار ثبت می‌شود و هر مدیر گروه نیز می‌تواند اولین «نصب» را انجام دهد.
    // چون Bot API رسمی نقش واقعی فرستنده را همیشه اعلام نمی‌کند، در حالت fallback اولین اجراکننده «نصب» مدیر داخلی می‌شود.
    // برای رفتار قدیمی، allow_group_manager_install را false بگذارید؛ سپس owner_only_first_install اعمال می‌شود.
    'install_security' => [
        'allow_group_manager_install' => true,
        'owner_only_first_install' => true,
        'allowed_installer_ids' => [],
    ],

    // کنترل رشد فایل لاگ و داده‌های تاریخی.
    'log_max_bytes' => 5 * 1024 * 1024,
    'log_rotate_files' => 3,
    'violation_cleanup_days' => 90,
    'violation_max_rows' => 100000,       // حتی اگر تاریخ‌ها جدید باشند، قدیمی‌ترین رکوردها بعد از این سقف حذف می‌شوند.
    'flood_event_retention_seconds' => 3600, // رخداد ضداسپم بیشتر از این مدت نگهداری نمی‌شود.
    'flood_event_max_per_user' => 500,    // سقف رخداد ذخیره‌شده برای هر کاربر/گروه.
    'flood_event_trim_batch' => 50,       // پاکسازی دسته‌ای؛ برای کاهش write و جلوگیری از قفل SQLite.
    'flood_event_max_rows' => 250000,     // سقف کلی جدول flood_events.
    'message_stats_cleanup_days' => 365,
    'processed_update_cleanup_days' => 2,

    // sqlite برای نصب ساده و سریع کافی است. اگر هاست فقط MySQL دارد، driver را mysql بگذارید.
    'database' => [
        'driver' => 'sqlite', // sqlite یا mysql
        'sqlite_path' => __DIR__ . '/storage/bot.sqlite',
        'sqlite_journal_mode' => 'WAL', // در فایل‌سیستم‌های ناسازگار: DELETE
        'sqlite_busy_timeout_ms' => 15000,
        'sqlite_write_retries' => 6,
        'sqlite_retry_base_us' => 40000,
        'sqlite_wal_autocheckpoint' => 1000,
        'sqlite_journal_size_limit' => 64 * 1024 * 1024,
        'mysql' => [
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'rubika_group_manager',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
    ],

    'paths' => [
        'database' => __DIR__ . '/storage/bot.sqlite', // برای سازگاری نسخه‌های قبلی
        'log'      => __DIR__ . '/logs/bot.log',
        'backup'   => __DIR__ . '/backups',
    ],

    'polling' => [
        'limit' => 50,
        'sleep_ms' => 650,
        'timeout' => 20,
    ],

    'defaults' => [
        'enabled' => false,
        'max_warnings' => 3,
        'delete_command_messages' => false,
        'soft_ban_after_max_warning' => true,
        'notify_on_delete' => true,
        'locks' => [
            'warnings_enabled' => true,
            'punish_action' => 'ban',
            'warning_limits' => [],
            'link' => true,
            'text' => false,
            'rubika_id' => true,
            'sticker' => false,
            'photo' => false,
            'video' => false,
            'voice' => false,
            'file' => false,
            'gif' => false,
            'music' => false,
            'swear' => true,
            'bad_words' => true,
            'english_text' => false,
            'reply' => false,
            'forward' => true,
            'edit' => false,
            'emoji' => false,
            'crash_code' => true,
            'poll' => false,
            'phone' => false,
            'number' => false,
            'hashtag' => false,
            'metadata' => true,
            'caption' => false,
            'content' => false,
            'flood' => true,
            'repeat' => true,
            'large_file' => true,
            'email' => true,
            'media' => false,
            'contact' => true,
            'location' => false,
        ],
        'flood' => [
            'max_messages' => 5,
            'seconds' => 8,
        ],
        'repeat' => [
            'max_repeats' => 3,
            'seconds' => 60,
        ],
        'bad_words' => ['تبلیغ', 'خرید فالوور', 'کسب درآمد', 'عضوگیری'],
        'trusted_users' => [],
        'group_admins' => [],
    ],

    // banChatMember و unbanChatMember در Bot API رسمی روبیکا پشتیبانی می‌شوند.
    // با خاموش کردن این گزینه، فقط محدودیت داخلی/حذف پیام اجرا می‌شود.
    'experimental' => [
        'hard_ban_enabled' => true,
    ],
];
