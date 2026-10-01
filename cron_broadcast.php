<?php
// cron_broadcast.php
// کرون جداگانه ارسال صف پیام همگانی روبیکا
// CLI: /usr/local/bin/php /home/USER/public_html/RubikaGroupManagerPHP/cron_broadcast.php
// URL: https://domain.com/RubikaGroupManagerPHP/cron_broadcast.php?key=CRON_KEY

$config = require __DIR__ . '/bootstrap.php';

use RubikaGM\Database;
use RubikaGM\RubikaClient;
use RubikaGM\BotApp;
use RubikaGM\Support;

header('Content-Type: application/json; charset=utf-8');

if (PHP_SAPI !== 'cli') {
    $key = (string)($_GET['key'] ?? '');
    $cronKey = (string)($config['cron_key'] ?? '');
    if ($cronKey === '' || !hash_equals($cronKey, $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden', 'hint' => 'key را با مقدار cron_key داخل config.php ارسال کن.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$limit = (int)($config['broadcast_queue_limit'] ?? 20);
if (PHP_SAPI !== 'cli' && isset($_GET['limit'])) {
    $limit = (int)$_GET['limit'];
} elseif (PHP_SAPI === 'cli' && isset($argv[1]) && preg_match('/^\d+$/', (string)$argv[1])) {
    $limit = (int)$argv[1];
}
$limit = max(1, min(100, $limit));

$lockFile = __DIR__ . '/storage/cron_broadcast.lock';
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$fh = @fopen($lockFile, 'c');
if (!$fh) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'lock_file_open_failed',
        'lock_file' => $lockFile,
        'hint' => 'پوشه storage باید قابل نوشتن باشد.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!flock($fh, LOCK_EX | LOCK_NB)) {
    echo json_encode(['ok' => false, 'error' => 'broadcast_cron_locked', 'hint' => 'اجرای قبلی هنوز تمام نشده است.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $db = new Database($config);

    if ((PHP_SAPI !== 'cli' && isset($_GET['status'])) || (PHP_SAPI === 'cli' && in_array('--status', $argv ?? [], true))) {
        echo json_encode([
            'ok' => true,
            'type' => 'broadcast_status_only',
            'database_driver' => (string)($config['database']['driver'] ?? 'sqlite'),
            'database_path' => (string)($config['database']['sqlite_path'] ?? $config['paths']['database'] ?? ''),
            'storage_writable' => is_writable(__DIR__ . '/storage'),
            'summary' => $db->broadcastQueueSummary(),
            'latest_jobs' => $db->latestBroadcastJobs(10),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $app = new BotApp($config, $db, new RubikaClient($config));
    $result = $app->processBroadcastQueue($limit);

    echo json_encode([
        'ok' => true,
        'type' => 'broadcast_queue_only',
        'limit' => $limit,
        'database_driver' => (string)($config['database']['driver'] ?? 'sqlite'),
        'storage_writable' => is_writable(__DIR__ . '/storage'),
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Support::log($config, 'cron_broadcast_error', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => !empty($config['debug']) ? $e->getMessage() : 'internal_error',
        'file' => !empty($config['debug']) ? basename($e->getFile()) : null,
        'line' => !empty($config['debug']) ? $e->getLine() : null,
        'hint' => 'اگر خطای could not find driver دیدی، افزونه pdo_sqlite یا pdo_mysql روی هاست فعال نیست.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} finally {
    if ($fh) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
