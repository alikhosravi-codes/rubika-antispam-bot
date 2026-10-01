<?php
/**
 * Rubika Group Manager - Migration Importer v85
 * CLI only. Run on new host after uploading full source and backup zip:
 *   php migration_import.php rubika_migration_YYYYmmdd_His.zip "https://domain.com/spam/webhook.php?key=WEBHOOK_KEY"
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden: migration_import.php is CLI-only.\n";
    exit;
}

$root = __DIR__;
$backupArg = $argv[1] ?? '';
$webhookArg = $argv[2] ?? '';
$flags = array_slice($argv, 3);
$keepConfig = in_array('--keep-config', $flags, true);
$noWebhook = in_array('--no-webhook', $flags, true);
$allowLegacyConfigPhp = in_array('--allow-legacy-config-php', $flags, true);

if ($backupArg === '' || in_array($backupArg, ['-h', '--help'], true)) {
    echo "Rubika Group Manager Migration Importer v85\n\n";
    echo "Usage:\n";
    echo "  php migration_import.php BACKUP.zip \"https://domain.com/spam/webhook.php?key=WEBHOOK_KEY\"\n\n";
    echo "Options:\n";
    echo "  --keep-config   config.php فعلی هاست جدید را نگه می‌دارد و config بکاپ را جایگزین نمی‌کند.\n";
    echo "  --no-webhook    فقط دیتابیس/فایل‌ها را ایمپورت می‌کند و وبهوک را ست نمی‌کند.\n";
    echo "  --allow-legacy-config-php  فقط برای بکاپ قدیمی v84؛ اجازه خواندن config.php داخل ZIP.\n";
    exit($backupArg === '' ? 1 : 0);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ERROR: افزونه ZipArchive روی PHP فعال نیست. php-zip را فعال کن.\n");
    exit(1);
}

$backupFile = $backupArg;
if (!is_file($backupFile)) {
    $candidate = $root . '/' . ltrim($backupArg, '/');
    if (is_file($candidate)) $backupFile = $candidate;
}
if (!is_file($backupFile)) {
    fwrite(STDERR, "ERROR: فایل بکاپ پیدا نشد: {$backupArg}\n");
    exit(1);
}

$tmpDir = $root . '/backups/.migration_import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
if (!is_dir($root . '/backups')) @mkdir($root . '/backups', 0775, true);
if (!@mkdir($tmpDir, 0775, true)) {
    fwrite(STDERR, "ERROR: پوشه موقت import قابل ساخت نیست: {$tmpDir}\n");
    exit(1);
}

function rrmdir_migration_import(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}

function quote_ident_import(string $driver, string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException("Unsafe identifier: {$name}");
    }
    return $driver === 'mysql' ? "`{$name}`" : '"' . $name . '"';
}

function validate_migration_zip(ZipArchive $zip): void
{
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:/#', $name)) {
            throw new RuntimeException("Unsafe ZIP entry: {$name}");
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '..') throw new RuntimeException("Path traversal in ZIP entry: {$name}");
        }
        if (method_exists($zip, 'getExternalAttributesIndex')) {
            $opsys = 0; $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                $mode = ($attr >> 16) & 0xF000;
                if ($mode === 0xA000) throw new RuntimeException("Symlink is not allowed in backup ZIP: {$name}");
            }
        }
    }
}

function rebase_config_paths(array $config, string $root): array
{
    $driver = (string)($config['database']['driver'] ?? 'sqlite');
    if ($driver !== 'mysql') {
        $config['database']['driver'] = 'sqlite';
        $config['database']['sqlite_path'] = $root . '/storage/bot.sqlite';
    }
    $config['paths']['database'] = $root . '/storage/bot.sqlite';
    $config['paths']['log'] = $root . '/logs/bot.log';
    $config['paths']['backup'] = $root . '/backups';
    return $config;
}

function write_config_import(string $target, array $config): void
{
    $content = "<?php\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($target, $content, LOCK_EX) === false) {
        throw new RuntimeException('نوشتن config.php جدید انجام نشد.');
    }
    @chmod($target, 0640);
}

function destination_columns_import(PDO $pdo, string $driver, string $table): array
{
    if ($driver === 'mysql') {
        $stmt = $pdo->query('DESCRIBE ' . quote_ident_import($driver, $table));
        return array_values(array_map(fn($row) => (string)$row['Field'], $stmt->fetchAll() ?: []));
    }
    $stmt = $pdo->query('PRAGMA table_info(' . quote_ident_import($driver, $table) . ')');
    return array_values(array_map(fn($row) => (string)$row['name'], $stmt->fetchAll() ?: []));
}

function copy_dir_migration(string $from, string $to): int
{
    if (!is_dir($from)) return 0;
    if (!is_dir($to)) @mkdir($to, 0775, true);
    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen($from) + 1);
        $dest = $to . '/' . $rel;
        if ($item->isDir()) {
            if (!is_dir($dest)) @mkdir($dest, 0775, true);
        } else {
            $dir = dirname($dest);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            copy($item->getPathname(), $dest);
            $count++;
        }
    }
    return $count;
}

function make_pdo_import(array $config): PDO
{
    $driver = (string)($config['database']['driver'] ?? 'sqlite');
    if ($driver === 'mysql') {
        $m = $config['database']['mysql'] ?? [];
        $charset = (string)($m['charset'] ?? 'utf8mb4');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)($m['host'] ?? 'localhost'),
            (int)($m['port'] ?? 3306),
            (string)($m['database'] ?? ''),
            $charset
        );
        return new PDO($dsn, (string)($m['username'] ?? ''), (string)($m['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
        ]);
    }

    $dbPath = (string)($config['database']['sqlite_path'] ?? ($config['paths']['database'] ?? (__DIR__ . '/storage/bot.sqlite')));
    $dir = dirname($dbPath);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

function set_rubika_webhook_migration(array $config, string $webhookUrl): array
{
    $token = trim((string)($config['bot_token'] ?? ''));
    if ($token === '') {
        return [['name' => 'token', 'ok' => false, 'http' => 0, 'body' => 'bot_token is empty']];
    }

    $webhookKey = trim((string)($config['webhook_key'] ?? $config['cron_key'] ?? ''));
    if ($webhookKey !== '') {
        $webhookUrl = str_replace(['WEBHOOK_KEY', '{WEBHOOK_KEY}', 'AUTO_KEY'], $webhookKey, $webhookUrl);
        $replaced = 0;
        $webhookUrl = preg_replace('/([?&])key=[^&]*/i', '$1key=' . rawurlencode($webhookKey), $webhookUrl, 1, $replaced) ?: $webhookUrl;
        if ($replaced === 0) {
            $webhookUrl .= (str_contains($webhookUrl, '?') ? '&' : '?') . 'key=' . rawurlencode($webhookKey);
        }
    }

    $api = 'https://botapi.rubika.ir/v3/' . rawurlencode($token) . '/updateBotEndpoints';
    $attempts = [
        ['name' => 'ReceiveUpdate', 'payload' => ['url' => $webhookUrl, 'type' => 'ReceiveUpdate']],
        ['name' => 'ReceiveInlineMessage', 'payload' => ['url' => $webhookUrl, 'type' => 'ReceiveInlineMessage']],
    ];
    $results = [];
    foreach ($attempts as $attempt) {
        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($attempt['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $ok = false;
        if (!$err && is_string($body)) {
            $decoded = json_decode($body, true);
            $ok = (($decoded['status'] ?? '') === 'OK') || (($decoded['data']['status'] ?? '') === 'Done');
        }
        $results[] = ['name' => $attempt['name'], 'ok' => $ok, 'http' => $http, 'body' => $err ?: (string)$body, 'webhook_url' => $webhookUrl];
    }
    return $results;
}

try {
    $zip = new ZipArchive();
    if ($zip->open($backupFile) !== true) {
        throw new RuntimeException("Cannot open backup zip: {$backupFile}");
    }
    validate_migration_zip($zip);
    if (!$zip->extractTo($tmpDir)) {
        throw new RuntimeException("Cannot extract backup zip to: {$tmpDir}");
    }
    $zip->close();

    $metaFile = $tmpDir . '/database/meta.json';
    if (!is_file($metaFile)) {
        throw new RuntimeException('database/meta.json داخل بکاپ نیست؛ فایل بکاپ معتبر نیست.');
    }
    $meta = json_decode((string)file_get_contents($metaFile), true);
    if (!is_array($meta) || empty($meta['tables'])) {
        throw new RuntimeException('meta.json معتبر نیست یا جدول ندارد.');
    }

    $configTarget = $root . '/config.php';
    foreach (['storage', 'logs', 'backups', 'data'] as $dir) {
        if (!is_dir($root . '/' . $dir)) @mkdir($root . '/' . $dir, 0775, true);
    }

    if ($keepConfig) {
        if (!is_file($configTarget)) throw new RuntimeException('--keep-config انتخاب شده ولی config.php روی هاست جدید وجود ندارد.');
        $config = require $configTarget;
    } else {
        $configJsonFile = $tmpDir . '/config.json';
        $legacyConfigFile = $tmpDir . '/config.php';
        if (is_file($configJsonFile)) {
            $config = json_decode((string)file_get_contents($configJsonFile), true);
            if (!is_array($config)) throw new RuntimeException('config.json داخل بکاپ معتبر نیست.');
        } elseif ($allowLegacyConfigPhp && is_file($legacyConfigFile)) {
            fwrite(STDERR, "WARNING: config.php بکاپ قدیمی فقط چون صریحاً اجازه دادی خوانده می‌شود.\n");
            $config = require $legacyConfigFile;
            if (!is_array($config)) throw new RuntimeException('config.php قدیمی خروجی معتبر ندارد.');
        } else {
            throw new RuntimeException('config.json داخل بکاپ نیست. برای بکاپ v84 ابتدا setup را روی هاست جدید اجرا و --keep-config بزن، یا فقط برای بکاپ کاملاً مورد اعتماد --allow-legacy-config-php اضافه کن.');
        }
        if (is_file($configTarget)) {
            copy($configTarget, $root . '/backups/config.before_migration_' . date('Ymd_His') . '.php');
        }
    }

    if (!is_array($config)) throw new RuntimeException('تنظیمات مقصد معتبر نیست.');
    $config = rebase_config_paths($config, $root);
    require_once $root . '/src/Autoload.php';
    $dbObj = new RubikaGM\Database($config);
    $pdo = $dbObj->pdo();
    $driver = (string)($config['database']['driver'] ?? 'sqlite');

    if ($driver === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    } else {
        $pdo->exec('PRAGMA foreign_keys = OFF');
    }

    $totalRows = 0;
    $importedTables = 0;
    $pdo->beginTransaction();
    try {
        foreach ($meta['tables'] as $table => $info) {
            if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) continue;
            $sourceColumns = $info['columns'] ?? [];
            if (!is_array($sourceColumns) || !$sourceColumns) continue;
            $sourceColumns = array_values(array_filter(array_map('strval', $sourceColumns), fn($c) => preg_match('/^[A-Za-z0-9_]+$/', $c)));
            try {
                $destinationColumns = destination_columns_import($pdo, $driver, $table);
            } catch (Throwable) {
                continue;
            }
            $columns = array_values(array_intersect($sourceColumns, $destinationColumns));
            if (!$columns) continue;

            $tablePath = $tmpDir . '/database/tables/' . $table . '.jsonl';
            if (!is_file($tablePath)) continue;

            $pdo->exec('DELETE FROM ' . quote_ident_import($driver, $table));
            $colSql = implode(',', array_map(fn($c) => quote_ident_import($driver, $c), $columns));
            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $stmt = $pdo->prepare('INSERT INTO ' . quote_ident_import($driver, $table) . " ({$colSql}) VALUES ({$placeholders})");

            $fh = fopen($tablePath, 'rb');
            if (!$fh) throw new RuntimeException("Cannot read table dump: {$tablePath}");
            $rowCount = 0;
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') continue;
                $row = json_decode($line, true);
                if (!is_array($row)) continue;
                $values = [];
                foreach ($columns as $col) $values[] = $row[$col] ?? null;
                $stmt->execute($values);
                $rowCount++;
            }
            fclose($fh);
            $totalRows += $rowCount;
            $importedTables++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        if ($driver === 'mysql') $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        else $pdo->exec('PRAGMA foreign_keys = ON');
    }

    // state قدیمی ممکن است schema_version نداشته باشد؛ مهاجرت نسخه جاری را نهایی کن.
    $dbObj = new RubikaGM\Database($config);
    $dataCopied = copy_dir_migration($tmpDir . '/data', $root . '/data');
    if (!$keepConfig) write_config_import($configTarget, $config);

    if (@file_put_contents($root . '/storage/setup.lock', 'locked by migration import ' . date('c'), LOCK_EX) === false) {
        throw new RuntimeException('ساخت storage/setup.lock انجام نشد.');
    }
    @chmod($root . '/storage/setup.lock', 0640);
    @unlink($root . '/storage/setup.key');
    @chmod($root . '/storage', 0775);
    @chmod($root . '/logs', 0775);
    @chmod($root . '/backups', 0775);
    @chmod($root . '/data', 0775);

    echo "✅ ایمپورت انجام شد\n";
    echo "TABLES: {$importedTables}\n";
    echo "ROWS: {$totalRows}\n";
    echo "DATA FILES: {$dataCopied}\n";

    $webhookSetOk = true;
    if (!$noWebhook && $webhookArg !== '') {
        echo "\nدر حال ست‌کردن وبهوک...\n";
        $results = set_rubika_webhook_migration($config, $webhookArg);
        $okCount = 0;
        foreach ($results as $res) {
            if ($res['ok']) $okCount++;
            echo "- {$res['name']} | HTTP {$res['http']} | " . ($res['ok'] ? 'OK' : 'FAILED') . "\n";
            echo "  {$res['body']}\n";
        }
        echo "OK ATTEMPTS: {$okCount}/" . count($results) . "\n";
        $webhookSetOk = $okCount === 2;
        if (!$webhookSetOk) echo "❌ هر دو endpoint باید با موفقیت ثبت شوند.\n";
        if (!empty($results[0]['webhook_url'])) {
            echo "WEBHOOK URL: {$results[0]['webhook_url']}\n";
        }
    } elseif (!$noWebhook) {
        echo "\nوبهوک ست نشد، چون URL ندادی. نمونه:\n";
        echo "php migration_import.php " . basename($backupFile) . " \"https://domain.com/spam/webhook.php?key=WEBHOOK_KEY\"\n";
    }

    $cronKey = (string)($config['cron_key'] ?? '');
    echo "\nCron پیشنهادی برای پیام همگانی:\n";
    echo "* * * * * /usr/local/bin/php {$root}/cron_broadcast.php >/dev/null 2>&1\n";
    if ($webhookArg !== '' && $cronKey !== '') {
        $base = preg_replace('#/webhook\.php.*$#', '', $webhookArg) ?: $webhookArg;
        echo "یا URL Cron:\n";
        echo rtrim($base, '/') . '/cron_broadcast.php?key=' . $cronKey . "\n";
    }

    rrmdir_migration_import($tmpDir);
    if (!$webhookSetOk) exit(2);
} catch (Throwable $e) {
    rrmdir_migration_import($tmpDir);
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
