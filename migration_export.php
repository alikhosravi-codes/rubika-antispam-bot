<?php
/**
 * Rubika Group Manager - Migration Exporter v85
 * CLI only. Run on old host:
 *   php migration_export.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden: migration_export.php is CLI-only.\n";
    exit;
}

$root = __DIR__;
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "ERROR: config.php پیدا نشد. این فایل را داخل پوشه اصلی ربات اجرا کن.\n");
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ERROR: افزونه ZipArchive روی PHP فعال نیست. php-zip را فعال کن.\n");
    exit(1);
}

$config = require $configFile;
if (!is_array($config)) {
    fwrite(STDERR, "ERROR: config.php خروجی آرایه معتبر ندارد.\n");
    exit(1);
}

date_default_timezone_set((string)($config['timezone'] ?? 'Asia/Tehran'));

$backupDir = (string)($config['paths']['backup'] ?? ($root . '/backups'));
if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true)) {
    fwrite(STDERR, "ERROR: پوشه backups قابل ساخت نیست: {$backupDir}\n");
    exit(1);
}

$timestamp = date('Ymd_His');
$outFile = $backupDir . "/rubika_migration_{$timestamp}.zip";
$tmpDir = $backupDir . "/.migration_tmp_{$timestamp}_" . bin2hex(random_bytes(4));
if (!@mkdir($tmpDir, 0775, true)) {
    fwrite(STDERR, "ERROR: پوشه موقت قابل ساخت نیست: {$tmpDir}\n");
    exit(1);
}

function rrmdir_migration_export(string $dir): void
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

function make_pdo_from_config(array $config): PDO
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
    if (!is_file($dbPath)) {
        throw new RuntimeException("SQLite database not found: {$dbPath}");
    }
    return new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

function quote_ident_export(string $driver, string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException("Unsafe identifier: {$name}");
    }
    return $driver === 'mysql' ? "`{$name}`" : '"' . $name . '"';
}

function list_tables_export(PDO $pdo, string $driver): array
{
    if ($driver === 'mysql') {
        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        return array_values(array_filter(array_map(fn($r) => (string)$r[0], $rows)));
    }
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    return array_values(array_map(fn($r) => (string)$r['name'], $stmt->fetchAll()));
}

function list_columns_export(PDO $pdo, string $driver, string $table): array
{
    if ($driver === 'mysql') {
        $stmt = $pdo->query('DESCRIBE ' . quote_ident_export($driver, $table));
        $cols = [];
        foreach ($stmt->fetchAll() as $row) {
            $cols[] = (string)$row['Field'];
        }
        return $cols;
    }
    $stmt = $pdo->query('PRAGMA table_info(' . quote_ident_export($driver, $table) . ')');
    $cols = [];
    foreach ($stmt->fetchAll() as $row) {
        $cols[] = (string)$row['name'];
    }
    return $cols;
}

function add_dir_to_zip(ZipArchive $zip, string $baseDir, string $zipPrefix, array $excludeNames = []): int
{
    if (!is_dir($baseDir)) return 0;
    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $name = $item->getFilename();
        if (in_array($name, $excludeNames, true)) continue;
        $path = $item->getPathname();
        $rel = str_replace('\\', '/', substr($path, strlen($baseDir) + 1));
        $zipPath = rtrim($zipPrefix, '/') . '/' . $rel;
        if ($item->isDir()) {
            $zip->addEmptyDir($zipPath);
        } else {
            $zip->addFile($path, $zipPath);
            $count++;
        }
    }
    return $count;
}

try {
    $driver = (string)($config['database']['driver'] ?? 'sqlite');
    $pdo = make_pdo_from_config($config);
    $tables = list_tables_export($pdo, $driver);

    $zip = new ZipArchive();
    if ($zip->open($outFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Cannot create zip: {$outFile}");
    }

    $meta = [
        'tool' => 'RubikaGroupManagerPHP migration toolkit',
        'tool_version' => 'v85',
        'generated_at' => date('c'),
        'php_version' => PHP_VERSION,
        'database_driver' => $driver,
        'tables' => [],
    ];

    $zip->addFromString('MIGRATION_NOTICE.txt', "این بکاپ شامل config.json، توکن ربات، کلیدها و دیتابیس است. آن را عمومی نکنید.\n");
    $configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($configJson)) throw new RuntimeException('Cannot encode config.json');
    $zip->addFromString('config.json', $configJson);

    foreach ($tables as $table) {
        $columns = list_columns_export($pdo, $driver, $table);
        if (!$columns) continue;
        $tableFile = $tmpDir . '/' . $table . '.jsonl';
        $fh = fopen($tableFile, 'wb');
        if (!$fh) throw new RuntimeException("Cannot write temp table dump: {$tableFile}");

        $count = 0;
        $stmt = $pdo->query('SELECT * FROM ' . quote_ident_export($driver, $table));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            $count++;
        }
        fclose($fh);

        $zip->addFile($tableFile, 'database/tables/' . $table . '.jsonl');
        $meta['tables'][$table] = [
            'columns' => $columns,
            'rows' => $count,
        ];
    }

    // Export data folder, but not logs/backups to avoid huge recursive archives.
    $dataFiles = add_dir_to_zip($zip, $root . '/data', 'data', ['.gitkeep']);

    // Also include a safe SQLite snapshot when the current driver is sqlite.
    if ($driver !== 'mysql') {
        $dbPath = (string)($config['database']['sqlite_path'] ?? ($config['paths']['database'] ?? ($root . '/storage/bot.sqlite')));
        $snap = $tmpDir . '/bot.sqlite';
        try {
            $quotedSnap = str_replace("'", "''", $snap);
            $pdo->exec("VACUUM INTO '{$quotedSnap}'");
            if (is_file($snap)) {
                $zip->addFile($snap, 'storage/bot.sqlite.snapshot');
            }
        } catch (Throwable $e) {
            if (is_file($dbPath)) {
                $zip->addFile($dbPath, 'storage/bot.sqlite.snapshot');
            }
        }
    }

    $meta['data_files'] = $dataFiles;
    $zip->addFromString('database/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $zip->close();
    @chmod($outFile, 0640);

    rrmdir_migration_export($tmpDir);

    echo "✅ بکاپ مهاجرت ساخته شد\n";
    echo "FILE: {$outFile}\n";
    echo "SIZE: " . number_format((int)filesize($outFile)) . " bytes\n";
    echo "TABLES: " . count($tables) . "\n";
    echo "\nروی هاست جدید این فایل را کنار سورس آپلود کن و بزن:\n";
    echo "php migration_import.php " . basename($outFile) . " \"https://NEW_DOMAIN/spam/webhook.php?key=WEBHOOK_KEY\"\n";
} catch (Throwable $e) {
    rrmdir_migration_export($tmpDir);
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
