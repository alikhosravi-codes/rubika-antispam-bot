<?php
namespace RubikaGM;

use PDO;

final class Database
{
    private const SCHEMA_VERSION = 2;
    private PDO $pdo;
    private array $config;
    private string $driver;
    private array $groupCache = [];
    private int $groupCacheTtl = 600;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->driver = (string)($config['database']['driver'] ?? 'sqlite');
        if ($this->driver === 'mysql') {
            $m = $config['database']['mysql'] ?? [];
            $charset = $m['charset'] ?? 'utf8mb4';
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $m['host'] ?? 'localhost', (int)($m['port'] ?? 3306), $m['database'] ?? '', $charset);
            $this->pdo = new PDO($dsn, (string)($m['username'] ?? ''), (string)($m['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
            ]);
        } else {
            $dbPath = (string)($config['database']['sqlite_path'] ?? $config['paths']['database']);
            $dir = dirname($dbPath);
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $journalMode = strtoupper((string)($config['database']['sqlite_journal_mode'] ?? 'WAL'));
            if (!in_array($journalMode, ['DELETE', 'TRUNCATE', 'PERSIST', 'MEMORY', 'WAL', 'OFF'], true)) {
                $journalMode = 'WAL';
            }
            $this->pdo->exec('PRAGMA journal_mode = ' . $journalMode . ';');
            $this->pdo->exec('PRAGMA synchronous = NORMAL;');
            $this->pdo->exec('PRAGMA foreign_keys = ON;');
        }
        $this->migrate();
        try {
            $this->backfillStructuredSettings(false);
        } catch (\Throwable $e) {
            Support::log($this->config, 'structured_settings_backfill_error', ['error' => $e->getMessage()]);
        }
        try {
            $this->maintenanceCleanup(false);
        } catch (\Throwable $e) {
            Support::log($this->config, 'maintenance_cleanup_error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    public function pdo(): PDO { return $this->pdo; }

    private function migrate(): void
    {
        if ($this->driver === 'mysql') {
            $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS state ( `key` VARCHAR(191) PRIMARY KEY, `value` LONGTEXT NOT NULL, updated_at INT NOT NULL ) {$engine}");
            $currentVersion = (int)($this->getState('schema_version', '0') ?? '0');
            if ($currentVersion >= self::SCHEMA_VERSION) return;
            $sqls = [
                "CREATE TABLE IF NOT EXISTS groups ( chat_id VARCHAR(191) PRIMARY KEY, title TEXT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0, max_warnings INT NOT NULL DEFAULT 3, locks_json LONGTEXT NOT NULL, bad_words_json LONGTEXT NOT NULL, trusted_json LONGTEXT NOT NULL, admins_json LONGTEXT NOT NULL, flood_json LONGTEXT NOT NULL, repeat_json LONGTEXT NOT NULL, created_at INT NOT NULL, updated_at INT NOT NULL ) {$engine}",
                "CREATE TABLE IF NOT EXISTS warnings ( chat_id VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, count INT NOT NULL DEFAULT 0, updated_at INT NOT NULL, PRIMARY KEY(chat_id, user_id) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS warning_scopes ( chat_id VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, scope_key VARCHAR(80) NOT NULL, count INT NOT NULL DEFAULT 0, updated_at INT NOT NULL, PRIMARY KEY(chat_id, user_id, scope_key), INDEX idx_warning_scopes_chat_scope(chat_id, scope_key) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS soft_bans ( chat_id VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, reason TEXT NULL, created_at INT NOT NULL, expires_at INT NULL, PRIMARY KEY(chat_id, user_id) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS flood_events ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chat_id VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, message_hash VARCHAR(191) NOT NULL, created_at INT NOT NULL, INDEX idx_flood_lookup(chat_id, user_id, created_at) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS violations ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chat_id VARCHAR(191) NOT NULL, user_id VARCHAR(191) NULL, message_id VARCHAR(191) NULL, reason TEXT NOT NULL, text TEXT NULL, action VARCHAR(80) NULL, created_at INT NOT NULL, INDEX idx_violations_chat_time(chat_id, created_at) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS message_stats ( chat_id VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, day_key VARCHAR(16) NOT NULL, count INT NOT NULL DEFAULT 0, updated_at INT NOT NULL, PRIMARY KEY(chat_id, user_id, day_key), INDEX idx_message_stats_chat_day(chat_id, day_key) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS message_senders ( chat_id VARCHAR(191) NOT NULL, message_id VARCHAR(191) NOT NULL, sender_id VARCHAR(191) NOT NULL, sender_name VARCHAR(191) NULL, created_at INT NOT NULL, PRIMARY KEY(chat_id, message_id), INDEX idx_message_senders_created(created_at) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS private_chats ( chat_id VARCHAR(191) PRIMARY KEY, user_id VARCHAR(191) NULL, updated_at INT NOT NULL ) {$engine}",
                "CREATE TABLE IF NOT EXISTS broadcast_jobs ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, message LONGTEXT NOT NULL, created_by VARCHAR(191) NULL, status VARCHAR(32) NOT NULL DEFAULT 'queued', total INT NOT NULL DEFAULT 0, sent INT NOT NULL DEFAULT 0, failed INT NOT NULL DEFAULT 0, created_at INT NOT NULL, updated_at INT NOT NULL ) {$engine}",
                "CREATE TABLE IF NOT EXISTS broadcast_targets ( job_id BIGINT UNSIGNED NOT NULL, chat_id VARCHAR(191) NOT NULL, target_type VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0, last_error TEXT NULL, updated_at INT NOT NULL, PRIMARY KEY(job_id, chat_id), INDEX idx_broadcast_targets_status(status, job_id) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS group_locks ( chat_id VARCHAR(191) NOT NULL, lock_key VARCHAR(80) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0, updated_at INT NOT NULL, PRIMARY KEY(chat_id, lock_key), INDEX idx_group_locks_chat(chat_id) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS group_lock_actions ( chat_id VARCHAR(191) NOT NULL, lock_key VARCHAR(80) NOT NULL, action VARCHAR(32) NOT NULL, updated_at INT NOT NULL, PRIMARY KEY(chat_id, lock_key), INDEX idx_group_lock_actions_chat(chat_id) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS group_lock_warning_limits ( chat_id VARCHAR(191) NOT NULL, lock_key VARCHAR(80) NOT NULL, warning_limit INT NOT NULL DEFAULT 0, updated_at INT NOT NULL, PRIMARY KEY(chat_id, lock_key), INDEX idx_group_lock_warning_limits_chat(chat_id) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS group_admins ( chat_id VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, name VARCHAR(191) NULL, updated_at INT NOT NULL, PRIMARY KEY(chat_id, user_id), INDEX idx_group_admins_chat(chat_id) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS group_trusted_users ( chat_id VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, updated_at INT NOT NULL, PRIMARY KEY(chat_id, user_id), INDEX idx_group_trusted_chat(chat_id) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS group_bad_words ( chat_id VARCHAR(191) NOT NULL, word VARCHAR(191) NOT NULL, updated_at INT NOT NULL, PRIMARY KEY(chat_id, word), INDEX idx_group_bad_words_chat(chat_id) ) {$engine}",
                "CREATE TABLE IF NOT EXISTS processed_updates ( delivery_key VARCHAR(64) PRIMARY KEY, created_at INT NOT NULL, INDEX idx_processed_updates_created(created_at) ) {$engine}",
            ];
            foreach ($sqls as $sql) $this->pdo->exec($sql);
            $this->ensureSoftBansExpiresAtColumn();
            $this->setState('schema_version', (string)self::SCHEMA_VERSION);
            return;
        }
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS state (key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at INTEGER NOT NULL)');
        $currentVersion = (int)($this->getState('schema_version', '0') ?? '0');
        if ($currentVersion >= self::SCHEMA_VERSION) return;
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS groups (
  chat_id TEXT PRIMARY KEY,
  title TEXT,
  enabled INTEGER NOT NULL DEFAULT 0,
  max_warnings INTEGER NOT NULL DEFAULT 3,
  locks_json TEXT NOT NULL,
  bad_words_json TEXT NOT NULL,
  trusted_json TEXT NOT NULL,
  admins_json TEXT NOT NULL,
  flood_json TEXT NOT NULL,
  repeat_json TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS warnings (
  chat_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  count INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(chat_id, user_id)
);
CREATE TABLE IF NOT EXISTS warning_scopes (
  chat_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  scope_key TEXT NOT NULL,
  count INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(chat_id, user_id, scope_key)
);
CREATE INDEX IF NOT EXISTS idx_warning_scopes_chat_scope ON warning_scopes(chat_id, scope_key);
CREATE TABLE IF NOT EXISTS soft_bans (
  chat_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  reason TEXT,
  created_at INTEGER NOT NULL,
  expires_at INTEGER NULL,
  PRIMARY KEY(chat_id, user_id)
);
CREATE TABLE IF NOT EXISTS flood_events (
  chat_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  message_hash TEXT NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_flood_lookup ON flood_events(chat_id, user_id, created_at);
CREATE TABLE IF NOT EXISTS violations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  chat_id TEXT NOT NULL,
  user_id TEXT,
  message_id TEXT,
  reason TEXT NOT NULL,
  text TEXT,
  action TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_violations_chat_time ON violations(chat_id, created_at);
CREATE TABLE IF NOT EXISTS message_stats (
  chat_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  day_key TEXT NOT NULL,
  count INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(chat_id, user_id, day_key)
);
CREATE INDEX IF NOT EXISTS idx_message_stats_chat_day ON message_stats(chat_id, day_key);
CREATE TABLE IF NOT EXISTS message_senders (
  chat_id TEXT NOT NULL,
  message_id TEXT NOT NULL,
  sender_id TEXT NOT NULL,
  sender_name TEXT,
  created_at INTEGER NOT NULL,
  PRIMARY KEY(chat_id, message_id)
);
CREATE INDEX IF NOT EXISTS idx_message_senders_created ON message_senders(created_at);
CREATE TABLE IF NOT EXISTS private_chats (
  chat_id TEXT PRIMARY KEY,
  user_id TEXT,
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS broadcast_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  message TEXT NOT NULL,
  created_by TEXT,
  status TEXT NOT NULL DEFAULT 'queued',
  total INTEGER NOT NULL DEFAULT 0,
  sent INTEGER NOT NULL DEFAULT 0,
  failed INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS broadcast_targets (
  job_id INTEGER NOT NULL,
  chat_id TEXT NOT NULL,
  target_type TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  attempts INTEGER NOT NULL DEFAULT 0,
  last_error TEXT,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(job_id, chat_id)
);
CREATE INDEX IF NOT EXISTS idx_broadcast_targets_status ON broadcast_targets(status, job_id);
CREATE TABLE IF NOT EXISTS group_locks (
  chat_id TEXT NOT NULL,
  lock_key TEXT NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(chat_id, lock_key)
);
CREATE INDEX IF NOT EXISTS idx_group_locks_chat ON group_locks(chat_id);
CREATE TABLE IF NOT EXISTS group_lock_actions (
  chat_id TEXT NOT NULL,
  lock_key TEXT NOT NULL,
  action TEXT NOT NULL,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(chat_id, lock_key)
);
CREATE INDEX IF NOT EXISTS idx_group_lock_actions_chat ON group_lock_actions(chat_id);
CREATE TABLE IF NOT EXISTS group_lock_warning_limits (
  chat_id TEXT NOT NULL,
  lock_key TEXT NOT NULL,
  warning_limit INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(chat_id, lock_key)
);
CREATE INDEX IF NOT EXISTS idx_group_lock_warning_limits_chat ON group_lock_warning_limits(chat_id);
CREATE TABLE IF NOT EXISTS group_admins (
  chat_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  name TEXT,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(chat_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_group_admins_chat ON group_admins(chat_id);
CREATE TABLE IF NOT EXISTS group_trusted_users (
  chat_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(chat_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_group_trusted_chat ON group_trusted_users(chat_id);
CREATE TABLE IF NOT EXISTS group_bad_words (
  chat_id TEXT NOT NULL,
  word TEXT NOT NULL,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(chat_id, word)
);
CREATE INDEX IF NOT EXISTS idx_group_bad_words_chat ON group_bad_words(chat_id);
CREATE TABLE IF NOT EXISTS processed_updates (
  delivery_key TEXT PRIMARY KEY,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_processed_updates_created ON processed_updates(created_at);
SQL);
        $this->ensureSoftBansExpiresAtColumn();
        $this->setState('schema_version', (string)self::SCHEMA_VERSION);
    }


    private function ensureSoftBansExpiresAtColumn(): void
    {
        try {
            if ($this->driver === 'mysql') {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM soft_bans LIKE 'expires_at'");
                if (!$stmt->fetch()) {
                    $this->pdo->exec('ALTER TABLE soft_bans ADD COLUMN expires_at INT NULL');
                }
            } else {
                $cols = $this->pdo->query('PRAGMA table_info(soft_bans)')->fetchAll();
                $has = false;
                foreach ($cols as $c) {
                    if (($c['name'] ?? '') === 'expires_at') {
                        $has = true;
                        break;
                    }
                }
                if (!$has) {
                    $this->pdo->exec('ALTER TABLE soft_bans ADD COLUMN expires_at INTEGER NULL');
                }
            }
        } catch (\Throwable $e) {
            Support::log($this->config, 'soft_bans_expiry_migration_error', ['error' => $e->getMessage()]);
        }
    }

    public function cleanupExpiredSoftBans(?string $chatId = null, ?string $userId = null): void
    {
        $sql = 'DELETE FROM soft_bans WHERE expires_at IS NOT NULL AND expires_at > 0 AND expires_at <= ?';
        $params = [time()];
        if ($chatId !== null) {
            $sql .= ' AND chat_id = ?';
            $params[] = $chatId;
        }
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $this->pdo->prepare($sql)->execute($params);
    }

    public function getState(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM state WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['value'] : $default;
    }

    public function setState(string $key, string $value): void
    {
        if ($this->driver === 'mysql') {
            $sql = 'INSERT INTO state(`key`,`value`,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO state(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at';
        }
        $this->pdo->prepare($sql)->execute([$key, $value, time()]);
    }

    public function claimProcessedUpdate(string $deliveryKey, int $dedupeSeconds = 30): bool
    {
        $deliveryKey = strtolower(trim($deliveryKey));
        if ($deliveryKey === '' || !preg_match('/^[a-f0-9]{64}$/', $deliveryKey)) return false;
        $cutoff = time() - max(1, min(300, $dedupeSeconds));
        $this->pdo->prepare('DELETE FROM processed_updates WHERE delivery_key=? AND created_at < ?')->execute([$deliveryKey, $cutoff]);
        $sql = $this->driver === 'mysql'
            ? 'INSERT IGNORE INTO processed_updates(delivery_key,created_at) VALUES(?,?)'
            : 'INSERT OR IGNORE INTO processed_updates(delivery_key,created_at) VALUES(?,?)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$deliveryKey, time()]);
        return $stmt->rowCount() === 1;
    }

    public function getGroup(string $chatId, ?string $title = null): array
    {
        $now = time();
        if (isset($this->groupCache[$chatId]) && ($this->groupCache[$chatId]['expires'] ?? 0) > $now) {
            return $this->groupCache[$chatId]['data'];
        }

        $stmt = $this->pdo->prepare('SELECT * FROM groups WHERE chat_id = ?');
        $stmt->execute([$chatId]);
        $row = $stmt->fetch();
        if (!$row) return $this->createGroup($chatId, $title);
        $group = $this->normalizeGroup($row);
        $this->groupCache[$chatId] = ['expires' => $now + $this->groupCacheTtl, 'data' => $group];
        return $group;
    }

    public function createGroup(string $chatId, ?string $title = null): array
    {
        $d = $this->config['defaults'];
        $now = time();
        $insert = $this->driver === 'mysql' ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';
        $stmt = $this->pdo->prepare("{$insert} groups(chat_id,title,enabled,max_warnings,locks_json,bad_words_json,trusted_json,admins_json,flood_json,repeat_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
        $locksJson = Support::jsonEncode($d['locks'] ?? []);
        $badWordsJson = Support::jsonEncode($d['bad_words'] ?? []);
        $trustedJson = Support::jsonEncode($d['trusted_users'] ?? []);
        $adminsJson = Support::jsonEncode($d['group_admins'] ?? []);
        $stmt->execute([
            $chatId, $title, !empty($d['enabled']) ? 1 : 0, (int)($d['max_warnings'] ?? 3),
            $locksJson, $badWordsJson, $trustedJson, $adminsJson,
            Support::jsonEncode($d['flood'] ?? ['max_messages'=>5,'seconds'=>8]), Support::jsonEncode($d['repeat'] ?? ['max_repeats'=>3,'seconds'=>60]),
            $now, $now,
        ]);
        $this->syncStructuredSettings($chatId, [
            'locks_json' => $locksJson,
            'bad_words_json' => $badWordsJson,
            'trusted_json' => $trustedJson,
            'admins_json' => $adminsJson,
        ]);
        unset($this->groupCache[$chatId]);
        return $this->getGroup($chatId, $title);
    }

    public function virtualGroup(string $chatId, ?string $title = null): array
    {
        // برای پیوی و contextهای موقت استفاده می‌شود و هیچ رکوردی در جدول groups نمی‌سازد.
        $d = $this->config['defaults'] ?? [];
        $now = time();
        return $this->normalizeGroup([
            'chat_id' => $chatId,
            'title' => $title,
            'enabled' => !empty($d['enabled']) ? 1 : 0,
            'max_warnings' => (int)($d['max_warnings'] ?? 3),
            'locks_json' => Support::jsonEncode($d['locks'] ?? []),
            'bad_words_json' => Support::jsonEncode($d['bad_words'] ?? []),
            'trusted_json' => Support::jsonEncode($d['trusted_users'] ?? []),
            'admins_json' => Support::jsonEncode($d['group_admins'] ?? []),
            'flood_json' => Support::jsonEncode($d['flood'] ?? ['max_messages'=>5,'seconds'=>8]),
            'repeat_json' => Support::jsonEncode($d['repeat'] ?? ['max_repeats'=>3,'seconds'=>60]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function normalizeGroup(array $row): array
    {
        $row['enabled'] = (bool)$row['enabled'];
        $row['max_warnings'] = (int)$row['max_warnings'];
        $row['locks'] = Support::jsonDecode($row['locks_json'] ?? '{}', []);
        $row['bad_words'] = $this->normalizeTextList(Support::jsonDecode($row['bad_words_json'] ?? '[]', []));
        $row['trusted_users'] = $this->normalizeIdList(Support::jsonDecode($row['trusted_json'] ?? '[]', []));
        $row['group_admins'] = $this->normalizeIdList(Support::jsonDecode($row['admins_json'] ?? '[]', []));
        $row['flood'] = Support::jsonDecode($row['flood_json'] ?? '{}', ['max_messages'=>5,'seconds'=>8]);
        $row['repeat'] = Support::jsonDecode($row['repeat_json'] ?? '{}', ['max_repeats'=>3,'seconds'=>60]);

        // v79: برای سرعت webhook، getGroup دیگر به‌صورت پیش‌فرض جدول‌های mirror تنظیمات را نمی‌خواند.
        // منبع اصلی همچنان JSON داخل groups است و جدول‌های ساخت‌یافته با updateGroup همگام می‌شوند.
        // اگر در نصب خاصی بخواهید جدول‌های ساخت‌یافته منبع خواندن باشند، این گزینه را دستی true کنید.
        $loadStructured = !empty($this->config['performance']['load_structured_settings_in_get_group']);
        $chatId = (string)($row['chat_id'] ?? '');
        if ($loadStructured && $chatId !== '') {
            try {
                $structured = $this->structuredGroupSettings($chatId);
                if ($structured['has_locks']) {
                    $row['locks'] = array_replace($row['locks'], $structured['locks']);
                    $row['locks']['punish_actions'] = array_replace((array)($row['locks']['punish_actions'] ?? []), $structured['punish_actions']);
                    $row['locks']['warning_limits'] = array_replace((array)($row['locks']['warning_limits'] ?? []), $structured['warning_limits']);
                }
                if ($structured['has_bad_words']) {
                    $row['bad_words'] = $this->normalizeTextList($structured['bad_words']);
                }
                if ($structured['has_trusted_users']) {
                    $row['trusted_users'] = $this->normalizeIdList($structured['trusted_users']);
                }
                if ($structured['has_admins']) {
                    $row['group_admins'] = $this->normalizeIdList($structured['group_admins']);
                }
            } catch (\Throwable $e) {
                Support::log($this->config, 'structured_settings_load_error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            }
        }
        return $row;
    }

    private function normalizeIdList(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $id = trim((string)($item['user_id'] ?? $item['id'] ?? $item['userId'] ?? ''));
            } else {
                $id = trim((string)$item);
            }
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
        }
        return $out;
    }

    private function normalizeTextList(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            $text = trim((string)$item);
            $key = mb_strtolower($text, 'UTF-8');
            if ($text === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $text;
        }
        return $out;
    }

    public function updateGroup(string $chatId, array $fields): void
    {
        $allowed = ['title','enabled','max_warnings','locks_json','bad_words_json','trusted_json','admins_json','flood_json','repeat_json'];
        $sets = [];
        $params = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $sets[] = "$k = ?";
            $params[] = is_bool($v) ? ($v ? 1 : 0) : $v;
        }
        if (!$sets) return;
        unset($this->groupCache[$chatId]);
        $sets[] = 'updated_at = ?';
        $params[] = time();
        $params[] = $chatId;
        $this->pdo->prepare('UPDATE groups SET ' . implode(', ', $sets) . ' WHERE chat_id = ?')->execute($params);
        $this->syncStructuredSettings($chatId, $fields);
    }


    /**
     * جدول‌های ساخت‌یافته تنظیمات گروه برای نسخه‌های جدید.
     * JSON قدیمی برای سازگاری نگه داشته شده، اما این جدول‌ها منبع سریع‌تر/تمیزتر پنل و گزارش هستند.
     */
    private function structuredGroupSettings(string $chatId): array
    {
        $out = [
            'has_locks' => false,
            'locks' => [],
            'punish_actions' => [],
            'warning_limits' => [],
            'has_admins' => false,
            'group_admins' => [],
            'has_trusted_users' => false,
            'trusted_users' => [],
            'has_bad_words' => false,
            'bad_words' => [],
        ];

        $stmt = $this->pdo->prepare('SELECT lock_key, enabled FROM group_locks WHERE chat_id=?');
        $stmt->execute([$chatId]);
        foreach (($stmt->fetchAll() ?: []) as $r) {
            $out['has_locks'] = true;
            $out['locks'][(string)$r['lock_key']] = !empty($r['enabled']);
        }

        $stmt = $this->pdo->prepare('SELECT lock_key, action FROM group_lock_actions WHERE chat_id=?');
        $stmt->execute([$chatId]);
        foreach (($stmt->fetchAll() ?: []) as $r) {
            $out['has_locks'] = true;
            $action = (string)$r['action'];
            if (in_array($action, ['delete','silence','kick','ban','none'], true)) {
                $out['punish_actions'][(string)$r['lock_key']] = $action;
            }
        }

        $stmt = $this->pdo->prepare('SELECT lock_key, warning_limit FROM group_lock_warning_limits WHERE chat_id=?');
        $stmt->execute([$chatId]);
        foreach (($stmt->fetchAll() ?: []) as $r) {
            $out['has_locks'] = true;
            $limit = max(0, min(20, (int)$r['warning_limit']));
            if ($limit > 0) {
                $out['warning_limits'][(string)$r['lock_key']] = $limit;
            }
        }

        $stmt = $this->pdo->prepare('SELECT user_id FROM group_admins WHERE chat_id=? ORDER BY updated_at ASC');
        $stmt->execute([$chatId]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $uid) {
            $out['has_admins'] = true;
            $uid = trim((string)$uid);
            if ($uid !== '') {
                $out['group_admins'][] = $uid;
            }
        }

        $stmt = $this->pdo->prepare('SELECT user_id FROM group_trusted_users WHERE chat_id=? ORDER BY updated_at ASC');
        $stmt->execute([$chatId]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $uid) {
            $out['has_trusted_users'] = true;
            $uid = (string)$uid;
            if ($uid !== '') {
                $out['trusted_users'][] = $uid;
            }
        }

        $stmt = $this->pdo->prepare('SELECT word FROM group_bad_words WHERE chat_id=? ORDER BY updated_at ASC');
        $stmt->execute([$chatId]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $word) {
            $out['has_bad_words'] = true;
            $word = trim((string)$word);
            if ($word !== '') {
                $out['bad_words'][] = $word;
            }
        }

        return $out;
    }

    private function syncStructuredSettings(string $chatId, array $fields): void
    {
        try {
            if (array_key_exists('locks_json', $fields)) {
                $this->syncLocksTable($chatId, Support::jsonDecode((string)$fields['locks_json'], []));
            }
            if (array_key_exists('admins_json', $fields)) {
                $this->syncAdminsTable($chatId, Support::jsonDecode((string)$fields['admins_json'], []));
            }
            if (array_key_exists('trusted_json', $fields)) {
                $this->syncTrustedTable($chatId, Support::jsonDecode((string)$fields['trusted_json'], []));
            }
            if (array_key_exists('bad_words_json', $fields)) {
                $this->syncBadWordsTable($chatId, Support::jsonDecode((string)$fields['bad_words_json'], []));
            }
        } catch (\Throwable $e) {
            Support::log($this->config, 'structured_settings_sync_error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    private function syncLocksTable(string $chatId, array $locks): void
    {
        $now = time();
        $this->pdo->prepare('DELETE FROM group_locks WHERE chat_id=?')->execute([$chatId]);
        $this->pdo->prepare('DELETE FROM group_lock_actions WHERE chat_id=?')->execute([$chatId]);
        $this->pdo->prepare('DELETE FROM group_lock_warning_limits WHERE chat_id=?')->execute([$chatId]);

        $lockStmt = $this->pdo->prepare('INSERT INTO group_locks(chat_id,lock_key,enabled,updated_at) VALUES(?,?,?,?)');
        foreach ($locks as $k => $v) {
            if (in_array((string)$k, ['warnings_enabled','punish_action','warning_limits','punish_actions'], true)) {
                continue;
            }
            if (is_bool($v) || is_int($v)) {
                $lockStmt->execute([$chatId, (string)$k, !empty($v) ? 1 : 0, $now]);
            }
        }

        $actionStmt = $this->pdo->prepare('INSERT INTO group_lock_actions(chat_id,lock_key,action,updated_at) VALUES(?,?,?,?)');
        foreach ((array)($locks['punish_actions'] ?? []) as $k => $action) {
            $action = (string)$action;
            if (in_array($action, ['delete','silence','kick','ban','none'], true)) {
                $actionStmt->execute([$chatId, (string)$k, $action, $now]);
            }
        }

        $limitStmt = $this->pdo->prepare('INSERT INTO group_lock_warning_limits(chat_id,lock_key,warning_limit,updated_at) VALUES(?,?,?,?)');
        foreach ((array)($locks['warning_limits'] ?? []) as $k => $limit) {
            $limit = max(0, min(20, (int)$limit));
            if ($limit > 0) {
                $limitStmt->execute([$chatId, (string)$k, $limit, $now]);
            }
        }
    }

    private function syncAdminsTable(string $chatId, array $admins): void
    {
        $now = time();
        $this->pdo->prepare('DELETE FROM group_admins WHERE chat_id=?')->execute([$chatId]);
        $stmt = $this->pdo->prepare('INSERT INTO group_admins(chat_id,user_id,name,updated_at) VALUES(?,?,?,?)');
        foreach ($admins as $item) {
            if (is_array($item)) {
                $uid = trim((string)($item['user_id'] ?? $item['id'] ?? ''));
                $name = trim((string)($item['name'] ?? ''));
            } else {
                $uid = trim((string)$item);
                $name = '';
            }
            if ($uid !== '') {
                $stmt->execute([$chatId, $uid, $name !== '' ? $name : null, $now]);
            }
        }
    }

    private function syncTrustedTable(string $chatId, array $trusted): void
    {
        $now = time();
        $this->pdo->prepare('DELETE FROM group_trusted_users WHERE chat_id=?')->execute([$chatId]);
        $stmt = $this->pdo->prepare('INSERT INTO group_trusted_users(chat_id,user_id,updated_at) VALUES(?,?,?)');
        foreach ($trusted as $uid) {
            $uid = trim((string)$uid);
            if ($uid !== '') {
                $stmt->execute([$chatId, $uid, $now]);
            }
        }
    }

    private function syncBadWordsTable(string $chatId, array $words): void
    {
        $now = time();
        $this->pdo->prepare('DELETE FROM group_bad_words WHERE chat_id=?')->execute([$chatId]);
        $stmt = $this->pdo->prepare('INSERT INTO group_bad_words(chat_id,word,updated_at) VALUES(?,?,?)');
        $seen = [];
        foreach ($words as $word) {
            $word = trim((string)$word);
            $key = mb_strtolower($word, 'UTF-8');
            if ($word !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $stmt->execute([$chatId, $word, $now]);
            }
        }
    }

    public function exportGroupSettings(string $chatId): array
    {
        $g = $this->getGroup($chatId);
        return [
            'version' => 1,
            'exported_at' => time(),
            'source_chat_id' => $chatId,
            'title' => (string)($g['title'] ?? ''),
            'enabled' => (bool)($g['enabled'] ?? false),
            'max_warnings' => (int)($g['max_warnings'] ?? 3),
            'locks' => $g['locks'] ?? [],
            'bad_words' => $this->normalizeTextList($g['bad_words'] ?? []),
            'trusted_users' => $this->normalizeIdList($g['trusted_users'] ?? []),
            'group_admins' => $this->normalizeIdList($g['group_admins'] ?? []),
            'flood' => $g['flood'] ?? ['max_messages' => 5, 'seconds' => 8],
            'repeat' => $g['repeat'] ?? ['max_repeats' => 3, 'seconds' => 60],
        ];
    }

    public function saveGroupSettingsExport(string $chatId): string
    {
        $backupDir = $this->config['paths']['backup'];
        if (!is_dir($backupDir)) mkdir($backupDir, 0775, true);
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $chatId) ?: 'group';
        $target = rtrim($backupDir, '/') . '/group-settings-' . $safe . '-' . date('Ymd-His') . '.json';
        file_put_contents($target, Support::jsonEncode($this->exportGroupSettings($chatId)), LOCK_EX);
        return $target;
    }

    public function importGroupSettings(string $chatId, array $data, bool $includeAdmins = true): void
    {
        $current = $this->getGroup($chatId);
        $locks = is_array($data['locks'] ?? null) ? $data['locks'] : ($current['locks'] ?? []);
        $badWords = $this->normalizeTextList(is_array($data['bad_words'] ?? null) ? $data['bad_words'] : ($current['bad_words'] ?? []));
        $trusted = $this->normalizeIdList(is_array($data['trusted_users'] ?? null) ? $data['trusted_users'] : ($current['trusted_users'] ?? []));
        $admins = $this->normalizeIdList($includeAdmins && is_array($data['group_admins'] ?? null) ? $data['group_admins'] : ($current['group_admins'] ?? []));
        $flood = is_array($data['flood'] ?? null) ? $data['flood'] : ($current['flood'] ?? ['max_messages' => 5, 'seconds' => 8]);
        $repeat = is_array($data['repeat'] ?? null) ? $data['repeat'] : ($current['repeat'] ?? ['max_repeats' => 3, 'seconds' => 60]);
        $maxWarnings = max(1, min(20, (int)($data['max_warnings'] ?? $current['max_warnings'] ?? 3)));
        $enabled = array_key_exists('enabled', $data) ? !empty($data['enabled']) : !empty($current['enabled']);

        $this->updateGroup($chatId, [
            'enabled' => $enabled ? 1 : 0,
            'max_warnings' => $maxWarnings,
            'locks_json' => Support::jsonEncode($locks),
            'bad_words_json' => Support::jsonEncode(array_values($badWords)),
            'trusted_json' => Support::jsonEncode(array_values($trusted)),
            'admins_json' => Support::jsonEncode(array_values($admins)),
            'flood_json' => Support::jsonEncode($flood),
            'repeat_json' => Support::jsonEncode($repeat),
        ]);
    }


    public function backfillStructuredSettings(bool $force = false): array
    {
        $result = ['groups_checked' => 0, 'groups_synced' => 0, 'skipped' => false];
        if (!$force) {
            $done = $this->getState('structured_settings_backfilled_v1', '0');
            if ($done === '1') {
                $result['skipped'] = true;
                return $result;
            }
        }

        $rows = $this->pdo->query('SELECT chat_id, locks_json, bad_words_json, trusted_json, admins_json FROM groups')->fetchAll() ?: [];
        foreach ($rows as $row) {
            $chatId = (string)($row['chat_id'] ?? '');
            if ($chatId === '') continue;
            $result['groups_checked']++;
            $this->syncStructuredSettings($chatId, [
                'locks_json' => (string)($row['locks_json'] ?? '{}'),
                'bad_words_json' => (string)($row['bad_words_json'] ?? '[]'),
                'trusted_json' => (string)($row['trusted_json'] ?? '[]'),
                'admins_json' => (string)($row['admins_json'] ?? '[]'),
            ]);
            $result['groups_synced']++;
        }
        $this->setState('structured_settings_backfilled_v1', '1');
        return $result;
    }


    public function removeGroupFromPanel(string $chatId): void
    {
        // گروه را از پنل خارج می‌کند و ربات را برای آن گروه خاموش نگه می‌دارد.
        // رکورد را حذف کامل نمی‌کنیم تا اگر بعداً پیام جدیدی از گروه رسید، دوباره با تنظیمات پیش‌فرض فعال نشود.
        $this->updateGroup($chatId, [
            'enabled' => 0,
            'admins_json' => Support::jsonEncode([]),
        ]);
        foreach (['warnings', 'warning_scopes', 'soft_bans', 'flood_events', 'message_stats', 'group_locks', 'group_lock_actions', 'group_lock_warning_limits', 'group_admins', 'group_trusted_users', 'group_bad_words'] as $table) {
            try {
                $this->pdo->prepare("DELETE FROM {$table} WHERE chat_id = ?")->execute([$chatId]);
            } catch (\Throwable $e) {
                Support::log($this->config, 'remove_group_cleanup_error', ['table' => $table, 'error' => $e->getMessage()]);
            }
        }
    }

    public function addWarning(string $chatId, string $userId): int
    {
        $now = time();
        if ($this->driver === 'mysql') {
            $sql = 'INSERT INTO warnings(chat_id,user_id,count,updated_at) VALUES(?,?,1,?) ON DUPLICATE KEY UPDATE count=count+1, updated_at=VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO warnings(chat_id,user_id,count,updated_at) VALUES(?,?,1,?) ON CONFLICT(chat_id,user_id) DO UPDATE SET count=count+1, updated_at=excluded.updated_at';
        }
        $this->pdo->prepare($sql)->execute([$chatId, $userId, $now]);
        return $this->getWarning($chatId, $userId);
    }

    public function getWarning(string $chatId, string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT count FROM warnings WHERE chat_id=? AND user_id=?');
        $stmt->execute([$chatId, $userId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['count'] : 0;
    }

    public function addScopedWarning(string $chatId, string $userId, string $scopeKey): int
    {
        $scopeKey = trim($scopeKey);
        if ($scopeKey === '') {
            return $this->addWarning($chatId, $userId);
        }
        $now = time();
        if ($this->driver === 'mysql') {
            $sql = 'INSERT INTO warning_scopes(chat_id,user_id,scope_key,count,updated_at) VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE count=count+1, updated_at=VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO warning_scopes(chat_id,user_id,scope_key,count,updated_at) VALUES(?,?,?,1,?) ON CONFLICT(chat_id,user_id,scope_key) DO UPDATE SET count=count+1, updated_at=excluded.updated_at';
        }
        $this->pdo->prepare($sql)->execute([$chatId, $userId, $scopeKey, $now]);
        return $this->getScopedWarning($chatId, $userId, $scopeKey);
    }

    public function getScopedWarning(string $chatId, string $userId, string $scopeKey): int
    {
        $stmt = $this->pdo->prepare('SELECT count FROM warning_scopes WHERE chat_id=? AND user_id=? AND scope_key=?');
        $stmt->execute([$chatId, $userId, $scopeKey]);
        $row = $stmt->fetch();
        return $row ? (int)$row['count'] : 0;
    }

    public function resetWarning(string $chatId, string $userId): void
    {
        $this->pdo->prepare('DELETE FROM warnings WHERE chat_id=? AND user_id=?')->execute([$chatId, $userId]);
    }

    public function resetScopedWarnings(string $chatId, string $userId): void
    {
        $this->pdo->prepare('DELETE FROM warning_scopes WHERE chat_id=? AND user_id=?')->execute([$chatId, $userId]);
    }

    public function resetScopedWarning(string $chatId, string $userId, string $scopeKey): void
    {
        $this->pdo->prepare('DELETE FROM warning_scopes WHERE chat_id=? AND user_id=? AND scope_key=?')->execute([$chatId, $userId, $scopeKey]);
    }

    public function softBan(string $chatId, string $userId, string $reason, ?int $expiresAt = null): void
    {
        $now = time();
        if ($this->driver === 'mysql') {
            $sql = 'INSERT INTO soft_bans(chat_id,user_id,reason,created_at,expires_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason), created_at=VALUES(created_at), expires_at=VALUES(expires_at)';
        } else {
            $sql = 'INSERT INTO soft_bans(chat_id,user_id,reason,created_at,expires_at) VALUES(?,?,?,?,?) ON CONFLICT(chat_id,user_id) DO UPDATE SET reason=excluded.reason, created_at=excluded.created_at, expires_at=excluded.expires_at';
        }
        $this->pdo->prepare($sql)->execute([$chatId, $userId, $reason, $now, $expiresAt]);
    }

    public function unSoftBan(string $chatId, string $userId): void
    {
        $this->pdo->prepare('DELETE FROM soft_bans WHERE chat_id=? AND user_id=?')->execute([$chatId, $userId]);
    }

    public function clearSoftBans(string $chatId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM soft_bans WHERE chat_id=?');
        $stmt->execute([$chatId]);
        return $stmt->rowCount();
    }

    public function listSoftBans(string $chatId, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $stmt = $this->pdo->prepare('SELECT user_id, reason, created_at, expires_at FROM soft_bans WHERE chat_id=? AND (expires_at IS NULL OR expires_at = 0 OR expires_at > ?) ORDER BY created_at DESC LIMIT ' . $limit);
        $stmt->execute([$chatId, time()]);
        return $stmt->fetchAll() ?: [];
    }

    public function listBanRecords(string $chatId, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $stmt = $this->pdo->prepare("SELECT user_id, reason, created_at, expires_at FROM soft_bans WHERE chat_id=? AND (expires_at IS NULL OR expires_at = 0 OR expires_at > ?) AND (reason LIKE ? OR reason LIKE ? OR reason LIKE ?) ORDER BY created_at DESC LIMIT " . $limit);
        $stmt->execute([$chatId, time(), '%بن%', '%سیک%', '%اخراج%']);
        return $stmt->fetchAll() ?: [];
    }

    public function unBanRecord(string $chatId, string $userId): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM soft_bans WHERE chat_id=? AND user_id=? AND (reason LIKE ? OR reason LIKE ? OR reason LIKE ?)");
        $stmt->execute([$chatId, $userId, '%بن%', '%سیک%', '%اخراج%']);
        return $stmt->rowCount();
    }

    public function clearBanRecords(string $chatId): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM soft_bans WHERE chat_id=? AND (reason LIKE ? OR reason LIKE ? OR reason LIKE ?)");
        $stmt->execute([$chatId, '%بن%', '%سیک%', '%اخراج%']);
        return $stmt->rowCount();
    }

    public function isSoftBanned(string $chatId, string $userId): bool
    {
        $this->cleanupExpiredSoftBans($chatId, $userId);
        $stmt = $this->pdo->prepare('SELECT 1 FROM soft_bans WHERE chat_id=? AND user_id=? LIMIT 1');
        $stmt->execute([$chatId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    public function addViolation(string $chatId, ?string $userId, ?string $messageId, string $reason, ?string $text, string $action): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO violations(chat_id,user_id,message_id,reason,text,action,created_at) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([$chatId, $userId, $messageId, $reason, mb_substr((string)$text, 0, 1000, 'UTF-8'), $action, time()]);
    }

    public function floodCount(string $chatId, string $userId, string $messageHash, int $windowSeconds): array
    {
        $now = time();
        $cutoff = $now - $windowSeconds;
        if (mt_rand(1, 100) === 1) {
            $this->pdo->prepare('DELETE FROM flood_events WHERE created_at < ?')->execute([$now - 3600]);
        }
        $this->pdo->prepare('INSERT INTO flood_events(chat_id,user_id,message_hash,created_at) VALUES(?,?,?,?)')->execute([$chatId, $userId, $messageHash, $now]);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM flood_events WHERE chat_id=? AND user_id=? AND created_at >= ?');
        $stmt->execute([$chatId, $userId, $cutoff]);
        $total = (int)$stmt->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM flood_events WHERE chat_id=? AND user_id=? AND message_hash=? AND created_at >= ?');
        $stmt->execute([$chatId, $userId, $messageHash, $cutoff]);
        $repeat = (int)$stmt->fetchColumn();
        return ['total'=>$total, 'repeat'=>$repeat];
    }

    public function stats(string $chatId): array
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM violations WHERE chat_id=?');
        $stmt->execute([$chatId]);
        $violations = (int)$stmt->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM soft_bans WHERE chat_id=? AND (expires_at IS NULL OR expires_at = 0 OR expires_at > ?)');
        $stmt->execute([$chatId, time()]);
        $bans = (int)$stmt->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM warnings WHERE chat_id=? AND count>0');
        $stmt->execute([$chatId]);
        $warned = (int)$stmt->fetchColumn();
        return ['violations'=>$violations,'soft_bans'=>$bans,'warned_users'=>$warned];
    }


    public function recordMessageStat(string $chatId, string $userId): void
    {
        $day = date('Ymd');
        $now = time();
        if ($this->driver === 'mysql') {
            $sql = 'INSERT INTO message_stats(chat_id,user_id,day_key,count,updated_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE count=count+1, updated_at=VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO message_stats(chat_id,user_id,day_key,count,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(chat_id,user_id,day_key) DO UPDATE SET count=count+1, updated_at=excluded.updated_at';
        }
        $this->pdo->prepare($sql)->execute([$chatId, $userId, $day, 1, $now]);
    }

    public function rememberMessageSender(string $chatId, string $messageId, string $senderId, string $senderName = ''): void
    {
        $chatId = trim($chatId);
        $messageId = trim($messageId);
        $senderId = trim($senderId);
        if ($chatId === '' || $messageId === '' || $senderId === '') {
            return;
        }

        $now = time();
        $senderName = mb_substr(trim($senderName), 0, 120, 'UTF-8');
        if ($this->driver === 'mysql') {
            $sql = 'INSERT INTO message_senders(chat_id,message_id,sender_id,sender_name,created_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE sender_id=VALUES(sender_id), sender_name=VALUES(sender_name), created_at=VALUES(created_at)';
        } else {
            $sql = 'INSERT INTO message_senders(chat_id,message_id,sender_id,sender_name,created_at) VALUES(?,?,?,?,?) ON CONFLICT(chat_id,message_id) DO UPDATE SET sender_id=excluded.sender_id, sender_name=excluded.sender_name, created_at=excluded.created_at';
        }
        $this->pdo->prepare($sql)->execute([$chatId, $messageId, $senderId, $senderName, $now]);

        if (mt_rand(1, 500) === 1) {
            $this->cleanupOldMessageSenders();
        }
    }

    public function messageSender(string $chatId, string $messageId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT sender_id, sender_name FROM message_senders WHERE chat_id=? AND message_id=? LIMIT 1');
        $stmt->execute([$chatId, $messageId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function recentMessageIds(string $chatId, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare('SELECT message_id FROM message_senders WHERE chat_id=? ORDER BY created_at DESC LIMIT ' . $limit);
        $stmt->execute([$chatId]);
        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []));
    }

    private function cleanupOldMessageSenders(): void
    {
        try {
            $this->pdo->prepare('DELETE FROM message_senders WHERE created_at < ?')->execute([time() - 604800]);
        } catch (\Throwable) {
        }
    }

    public function messageStats(string $chatId): array
    {
        $day = date('Ymd');
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(count),0) FROM message_stats WHERE chat_id=?');
        $stmt->execute([$chatId]);
        $total = (int)$stmt->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(count),0) FROM message_stats WHERE chat_id=? AND day_key=?');
        $stmt->execute([$chatId, $day]);
        $today = (int)$stmt->fetchColumn();
        return ['total_messages' => $total, 'today_messages' => $today];
    }

    public function userStats(string $chatId, string $userId): array
    {
        $day = date('Ymd');
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(count),0) FROM message_stats WHERE chat_id=? AND user_id=?');
        $stmt->execute([$chatId, $userId]);
        $total = (int)$stmt->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(count),0) FROM message_stats WHERE chat_id=? AND user_id=? AND day_key=?');
        $stmt->execute([$chatId, $userId, $day]);
        $today = (int)$stmt->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT count FROM warnings WHERE chat_id=? AND user_id=?');
        $stmt->execute([$chatId, $userId]);
        $warn = (int)($stmt->fetchColumn() ?: 0);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM violations WHERE chat_id=? AND user_id=?');
        $stmt->execute([$chatId, $userId]);
        $violations = (int)$stmt->fetchColumn();
        return ['total_messages'=>$total, 'today_messages'=>$today, 'warnings'=>$warn, 'violations'=>$violations];
    }

    public function topUsers(string $chatId, int $limit = 3): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = $this->pdo->prepare('SELECT user_id, COALESCE(SUM(count),0) AS total FROM message_stats WHERE chat_id=? GROUP BY user_id ORDER BY total DESC LIMIT ' . $limit);
        $stmt->execute([$chatId]);
        return $stmt->fetchAll() ?: [];
    }

    public function topUsersToday(string $chatId, int $limit = 3): array
    {
        $limit = max(1, min(20, $limit));
        $day = date('Ymd');
        $stmt = $this->pdo->prepare('SELECT user_id, COALESCE(SUM(count),0) AS total FROM message_stats WHERE chat_id=? AND day_key=? GROUP BY user_id ORDER BY total DESC LIMIT ' . $limit);
        $stmt->execute([$chatId, $day]);
        return $stmt->fetchAll() ?: [];
    }


    public function topViolators(string $chatId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare("SELECT user_id, COUNT(*) AS total, MAX(created_at) AS last_time FROM violations WHERE chat_id=? AND user_id IS NOT NULL AND user_id <> '' GROUP BY user_id ORDER BY total DESC, last_time DESC LIMIT " . $limit);
        $stmt->execute([$chatId]);
        return $stmt->fetchAll() ?: [];
    }

    public function recentViolations(string $chatId, int $limit = 8): array
    {
        $limit = max(1, min(30, $limit));
        $stmt = $this->pdo->prepare('SELECT user_id, reason, action, created_at FROM violations WHERE chat_id=? ORDER BY created_at DESC LIMIT ' . $limit);
        $stmt->execute([$chatId]);
        return $stmt->fetchAll() ?: [];
    }

    public function registerPrivateChat(string $chatId, string $userId = ''): void
    {
        if ($chatId === '') return;
        $now = time();
        if ($this->driver === 'mysql') {
            $sql = 'INSERT INTO private_chats(chat_id,user_id,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), updated_at=VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO private_chats(chat_id,user_id,updated_at) VALUES(?,?,?) ON CONFLICT(chat_id) DO UPDATE SET user_id=excluded.user_id, updated_at=excluded.updated_at';
        }
        $this->pdo->prepare($sql)->execute([$chatId, $userId !== '' ? $userId : null, $now]);
    }

    public function unregisterPrivateChat(string $chatId, string $userId = ''): void
    {
        if ($chatId !== '') {
            $this->pdo->prepare('DELETE FROM private_chats WHERE chat_id=?')->execute([$chatId]);
        }
        if ($userId !== '' && $userId !== $chatId) {
            $this->pdo->prepare('DELETE FROM private_chats WHERE user_id=?')->execute([$userId]);
        }
    }

    public function privateChatCount(): int
    {
        try {
            return (int)$this->pdo->query('SELECT COUNT(*) FROM private_chats')->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function privateChats(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT chat_id FROM private_chats ORDER BY updated_at DESC');
            return array_values(array_filter(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [])));
        } catch (\Throwable) {
            return [];
        }
    }


    public function createBroadcastJob(string $message, string $createdBy, array $targets): int
    {
        $now = time();
        $targets = array_values($targets);
        $total = count($targets);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO broadcast_jobs(message,created_by,status,total,sent,failed,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$message, $createdBy, 'queued', $total, 0, 0, $now, $now]);
            $jobId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare('INSERT INTO broadcast_targets(job_id,chat_id,target_type,status,attempts,last_error,updated_at) VALUES(?,?,?,?,?,?,?)');
            foreach ($targets as $t) {
                $chatId = trim((string)($t['chat_id'] ?? ''));
                if ($chatId === '') continue;
                $type = trim((string)($t['type'] ?? $t['target_type'] ?? 'group')) ?: 'group';
                $stmt->execute([$jobId, $chatId, $type, 'pending', 0, null, $now]);
            }
            $this->pdo->commit();
            return $jobId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function pendingBroadcastTargets(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $sql = "SELECT t.job_id, t.chat_id, t.target_type, t.attempts, t.last_error, j.message
                FROM broadcast_targets t
                JOIN broadcast_jobs j ON j.id = t.job_id
                WHERE t.status = 'pending' AND j.status IN ('queued','running')
                ORDER BY t.job_id ASC, t.updated_at ASC
                LIMIT {$limit}";
        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    public function broadcastQueueSummary(): array
    {
        $jobs = ['queued' => 0, 'running' => 0, 'done' => 0, 'partial' => 0, 'failed' => 0, 'canceled' => 0];
        try {
            $stmt = $this->pdo->query("SELECT status, COUNT(*) AS c FROM broadcast_jobs GROUP BY status");
            foreach (($stmt->fetchAll() ?: []) as $row) {
                $jobs[(string)$row['status']] = (int)$row['c'];
            }
        } catch (\Throwable) {}

        $targets = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'canceled' => 0];
        try {
            $stmt = $this->pdo->query("SELECT status, COUNT(*) AS c FROM broadcast_targets GROUP BY status");
            foreach (($stmt->fetchAll() ?: []) as $row) {
                $targets[(string)$row['status']] = (int)$row['c'];
            }
        } catch (\Throwable) {}

        $latestErrors = [];
        try {
            $stmt = $this->pdo->query("SELECT job_id, chat_id, attempts, last_error, updated_at FROM broadcast_targets WHERE last_error IS NOT NULL AND last_error <> '' ORDER BY updated_at DESC LIMIT 5");
            $latestErrors = $stmt->fetchAll() ?: [];
        } catch (\Throwable) {}

        return ['jobs' => $jobs, 'targets' => $targets, 'latest_errors' => $latestErrors];
    }

    public function markBroadcastRunning(int $jobId): void
    {
        $this->pdo->prepare("UPDATE broadcast_jobs SET status='running', updated_at=? WHERE id=? AND status='queued'")
            ->execute([time(), $jobId]);
    }

    public function markBroadcastTarget(int $jobId, string $chatId, bool $ok, string $error = '', int $maxAttempts = 3): void
    {
        $now = time();
        $stmt = $this->pdo->prepare('SELECT attempts FROM broadcast_targets WHERE job_id=? AND chat_id=? LIMIT 1');
        $stmt->execute([$jobId, $chatId]);
        $currentAttempts = (int)($stmt->fetchColumn() ?: 0);
        $nextAttempts = $currentAttempts + 1;

        if ($ok) {
            $status = 'sent';
            $storedError = '';
        } else {
            $status = ($nextAttempts >= max(1, $maxAttempts)) ? 'failed' : 'pending';
            $storedError = mb_substr($error, 0, 500, 'UTF-8');
        }

        $this->pdo->prepare('UPDATE broadcast_targets SET status=?, attempts=?, last_error=?, updated_at=? WHERE job_id=? AND chat_id=?')
            ->execute([$status, $nextAttempts, $storedError, $now, $jobId, $chatId]);
        $this->refreshBroadcastJobStats($jobId);
    }

    public function refreshBroadcastJobStats(int $jobId): array
    {
        $stmt = $this->pdo->prepare("SELECT status, COUNT(*) AS c FROM broadcast_targets WHERE job_id=? GROUP BY status");
        $stmt->execute([$jobId]);
        $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        foreach (($stmt->fetchAll() ?: []) as $row) {
            $counts[(string)$row['status']] = (int)$row['c'];
        }
        $sent = $counts['sent'] ?? 0;
        $failed = $counts['failed'] ?? 0;
        $pending = $counts['pending'] ?? 0;
        if ($pending > 0) {
            $status = 'running';
        } elseif ($failed > 0 && $sent > 0) {
            $status = 'partial';
        } elseif ($failed > 0) {
            $status = 'failed';
        } else {
            $status = 'done';
        }
        $this->pdo->prepare('UPDATE broadcast_jobs SET status=?, sent=?, failed=?, updated_at=? WHERE id=?')
            ->execute([$status, $sent, $failed, time(), $jobId]);
        return ['job_id'=>$jobId, 'status'=>$status, 'pending'=>$pending, 'sent'=>$sent, 'failed'=>$failed];
    }

    public function latestBroadcastJobs(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        return $this->pdo->query('SELECT id,status,total,sent,failed,created_at,updated_at FROM broadcast_jobs ORDER BY id DESC LIMIT ' . $limit)->fetchAll() ?: [];
    }

    public function cancelBroadcastJob(int $jobId): array
    {
        if ($jobId <= 0) {
            return ['ok' => false, 'job_id' => $jobId, 'canceled_targets' => 0, 'reason' => 'invalid_job_id'];
        }

        $stmt = $this->pdo->prepare('SELECT id,status,total,sent,failed FROM broadcast_jobs WHERE id=? LIMIT 1');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            return ['ok' => false, 'job_id' => $jobId, 'canceled_targets' => 0, 'reason' => 'not_found'];
        }

        $status = (string)($job['status'] ?? '');
        if (in_array($status, ['done', 'failed', 'partial', 'canceled'], true)) {
            return ['ok' => false, 'job_id' => $jobId, 'canceled_targets' => 0, 'reason' => 'not_cancelable', 'status' => $status];
        }

        $now = time();
        $targetStmt = $this->pdo->prepare("UPDATE broadcast_targets SET status='canceled', last_error='canceled_by_owner', updated_at=? WHERE job_id=? AND status='pending'");
        $targetStmt->execute([$now, $jobId]);
        $canceledTargets = $targetStmt->rowCount();
        $this->pdo->prepare("UPDATE broadcast_jobs SET status='canceled', updated_at=? WHERE id=?")->execute([$now, $jobId]);

        return ['ok' => true, 'job_id' => $jobId, 'canceled_targets' => $canceledTargets, 'status' => 'canceled'];
    }


    public function cancelAllBroadcastJobs(): array
    {
        $now = time();
        $jobs = $this->pdo->query("SELECT id FROM broadcast_jobs WHERE status IN ('queued','running') ORDER BY id ASC")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        $jobIds = array_values(array_map('intval', $jobs));
        if (!$jobIds) {
            return ['ok' => true, 'jobs' => 0, 'canceled_targets' => 0];
        }

        $this->pdo->beginTransaction();
        try {
            $canceledTargets = 0;
            $targetStmt = $this->pdo->prepare("UPDATE broadcast_targets SET status='canceled', last_error='canceled_by_owner_all', updated_at=? WHERE job_id=? AND status='pending'");
            $jobStmt = $this->pdo->prepare("UPDATE broadcast_jobs SET status='canceled', updated_at=? WHERE id=? AND status IN ('queued','running')");
            foreach ($jobIds as $jobId) {
                $targetStmt->execute([$now, $jobId]);
                $canceledTargets += $targetStmt->rowCount();
                $jobStmt->execute([$now, $jobId]);
            }
            $this->pdo->commit();
            return ['ok' => true, 'jobs' => count($jobIds), 'canceled_targets' => $canceledTargets];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }


    /**
     * پاکسازی سبک و دوره‌ای برای جلوگیری از سنگین شدن دیتابیس.
     * - stateهای موقت دکمه‌ها و پنل‌ها را پاک می‌کند.
     * - صف‌های همگانی تمام‌شده/لغوشده قدیمی را پاک می‌کند.
     * - soft-banهای زمان‌دار منقضی‌شده را حذف می‌کند.
     */
    public function maintenanceCleanup(bool $force = false): array
    {
        $now = time();
        $interval = max(300, (int)($this->config['maintenance_cleanup_interval'] ?? 86400));
        $last = (int)$this->getState('last_maintenance_cleanup', '0');
        if (!$force && $last > 0 && ($now - $last) < $interval) {
            return ['ok' => true, 'skipped' => true, 'last' => $last, 'next_after' => $last + $interval];
        }

        $stateDays = max(1, (int)($this->config['state_cleanup_days'] ?? 3));
        $broadcastDays = max(1, (int)($this->config['broadcast_cleanup_days'] ?? 30));
        $violationDays = max(1, (int)($this->config['violation_cleanup_days'] ?? 90));
        $statsDays = max(1, (int)($this->config['message_stats_cleanup_days'] ?? 365));
        $processedDays = max(1, (int)($this->config['processed_update_cleanup_days'] ?? 2));
        $stateCutoff = $now - ($stateDays * 86400);
        $broadcastCutoff = $now - ($broadcastDays * 86400);

        $result = [
            'ok' => true,
            'skipped' => false,
            'state_deleted' => 0,
            'broadcast_targets_deleted' => 0,
            'broadcast_jobs_deleted' => 0,
            'expired_soft_bans_deleted' => 0,
            'processed_updates_deleted' => 0,
            'violations_deleted' => 0,
            'message_stats_deleted' => 0,
            'flood_events_deleted' => 0,
            'message_senders_deleted' => 0,
        ];

        $stmt = $this->pdo->prepare("DELETE FROM state WHERE updated_at < ? AND (`key` LIKE ? OR `key` LIKE ? OR `key` LIKE ?)");
        $stmt->execute([$stateCutoff, 'button_alias:%', 'panel_group:%', 'pending_message:%']);
        $result['state_deleted'] = $stmt->rowCount();

        $stmt = $this->pdo->prepare('DELETE FROM processed_updates WHERE created_at < ?');
        $stmt->execute([$now - ($processedDays * 86400)]);
        $result['processed_updates_deleted'] = $stmt->rowCount();

        $stmt = $this->pdo->prepare('DELETE FROM violations WHERE created_at < ?');
        $stmt->execute([$now - ($violationDays * 86400)]);
        $result['violations_deleted'] = $stmt->rowCount();

        $statsCutoffDay = date('Ymd', $now - ($statsDays * 86400));
        $stmt = $this->pdo->prepare('DELETE FROM message_stats WHERE day_key < ?');
        $stmt->execute([$statsCutoffDay]);
        $result['message_stats_deleted'] = $stmt->rowCount();

        $stmt = $this->pdo->prepare('DELETE FROM flood_events WHERE created_at < ?');
        $stmt->execute([$now - 3600]);
        $result['flood_events_deleted'] = $stmt->rowCount();

        $stmt = $this->pdo->prepare('DELETE FROM message_senders WHERE created_at < ?');
        $stmt->execute([$now - 604800]);
        $result['message_senders_deleted'] = $stmt->rowCount();

        $stmt = $this->pdo->prepare('DELETE FROM soft_bans WHERE expires_at IS NOT NULL AND expires_at > 0 AND expires_at <= ?');
        $stmt->execute([$now]);
        $result['expired_soft_bans_deleted'] = $stmt->rowCount();

        if ($this->driver === 'mysql') {
            $stmt = $this->pdo->prepare("DELETE t FROM broadcast_targets t INNER JOIN broadcast_jobs j ON j.id = t.job_id WHERE j.status IN ('done','partial','failed','canceled') AND j.updated_at < ?");
            $stmt->execute([$broadcastCutoff]);
            $result['broadcast_targets_deleted'] = $stmt->rowCount();
        } else {
            $stmt = $this->pdo->prepare("DELETE FROM broadcast_targets WHERE job_id IN (SELECT id FROM broadcast_jobs WHERE status IN ('done','partial','failed','canceled') AND updated_at < ?)");
            $stmt->execute([$broadcastCutoff]);
            $result['broadcast_targets_deleted'] = $stmt->rowCount();
        }

        $stmt = $this->pdo->prepare("DELETE FROM broadcast_jobs WHERE status IN ('done','partial','failed','canceled') AND updated_at < ?");
        $stmt->execute([$broadcastCutoff]);
        $result['broadcast_jobs_deleted'] = $stmt->rowCount();

        $this->setState('last_maintenance_cleanup', (string)$now);
        return $result;
    }


    public function resetAllData(): array
    {
        $tables = ['groups','group_locks','group_lock_actions','group_lock_warning_limits','group_admins','group_trusted_users','group_bad_words','warnings','warning_scopes','soft_bans','flood_events','violations','message_stats','message_senders','private_chats','broadcast_targets','broadcast_jobs','processed_updates','state'];
        $counts = [];

        foreach ($tables as $table) {
            try {
                $counts[$table] = (int)$this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            } catch (\Throwable) {
                $counts[$table] = 0;
            }
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($tables as $table) {
                $this->pdo->exec("DELETE FROM {$table}");
            }
            if ($this->driver !== 'mysql') {
                try {
                    $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('violations')");
                } catch (\Throwable) {
                    // sqlite_sequence may not exist on all SQLite databases.
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->groupCache = [];
        $this->setState('schema_version', (string)self::SCHEMA_VERSION);
        return $counts;
    }

    public function backup(): string
    {
        $backupDir = $this->config['paths']['backup'];
        if (!is_dir($backupDir)) mkdir($backupDir, 0775, true);
        $suffix = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if ($this->driver === 'mysql') {
            $target = rtrim($backupDir, '/') . '/backup-' . $suffix . '.json';
            $fh = fopen($target, 'xb');
            if (!$fh) throw new \RuntimeException('ساخت فایل بکاپ MySQL انجام نشد.');
            $tables = ['state','groups','group_locks','group_lock_actions','group_lock_warning_limits','group_admins','group_trusted_users','group_bad_words','warnings','warning_scopes','soft_bans','flood_events','violations','message_stats','message_senders','private_chats','broadcast_jobs','broadcast_targets','processed_updates'];
            fwrite($fh, "{\n");
            foreach ($tables as $tableIndex => $table) {
                if ($tableIndex > 0) fwrite($fh, ",\n");
                fwrite($fh, json_encode($table, JSON_UNESCAPED_UNICODE) . ':[');
                $stmt = $this->pdo->query("SELECT * FROM {$table}");
                $rowIndex = 0;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($rowIndex++ > 0) fwrite($fh, ',');
                    fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                }
                fwrite($fh, ']');
            }
            fwrite($fh, "\n}\n");
            fclose($fh);
            @chmod($target, 0640);
            return $target;
        }
        $target = rtrim($backupDir, '/') . '/backup-' . $suffix . '.sqlite';
        $quotedTarget = str_replace("'", "''", $target);
        $this->pdo->exec("VACUUM INTO '{$quotedTarget}'");
        if (!is_file($target)) throw new \RuntimeException('ساخت snapshot امن SQLite انجام نشد.');
        @chmod($target, 0640);
        return $target;
    }
}
