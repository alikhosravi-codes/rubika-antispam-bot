<?php
$config = require __DIR__ . '/bootstrap.php';
use RubikaGM\{Database,RubikaClient,BotApp,Support};

if (PHP_SAPI !== 'cli') {
    $key = (string)($_GET['key'] ?? '');
    $cronKey = trim((string)($config['cron_key'] ?? ''));
    if ($cronKey === '' || $key === '' || !hash_equals($cronKey, $key)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
}
$lockFile = __DIR__ . '/storage/cron.lock';
$fh = fopen($lockFile, 'c');
if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
    echo json_encode(['ok'=>false, 'error'=>'locked'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $app = new BotApp($config, new Database($config), new RubikaClient($config));
    $result = $app->runOnce();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Support::log($config, 'cron_error', ['error'=>$e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>!empty($config['debug']) ? $e->getMessage() : 'internal_error'], JSON_UNESCAPED_UNICODE);
} finally {
    if ($fh) flock($fh, LOCK_UN);
}
