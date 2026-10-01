<?php
$config = require __DIR__ . '/bootstrap.php';
use RubikaGM\{Database,RubikaClient,BotApp,UpdateNormalizer,Support};

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok'=>false, 'error'=>'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$maxBodyBytes = max(1024, (int)($config['webhook_max_body_bytes'] ?? 1048576));
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxBodyBytes) {
    http_response_code(413);
    echo json_encode(['ok'=>false, 'error'=>'payload_too_large'], JSON_UNESCAPED_UNICODE);
    exit;
}

$expectedKey = trim((string)($config['webhook_key'] ?? ($config['cron_key'] ?? '')));
$givenKey = trim((string)($_GET['key'] ?? ($_SERVER['HTTP_X_RUBIKA_WEBHOOK_KEY'] ?? '')));

if ($expectedKey === '' || $givenKey === '' || !hash_equals($expectedKey, $givenKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > $maxBodyBytes) {
        http_response_code(413);
        echo json_encode(['ok'=>false, 'error'=>'payload_too_large'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = json_decode($raw, true);
    if (!empty($config['debug_raw_webhook'])) {
        $keys = is_array($data) ? array_slice(array_keys($data), 0, 30) : [];
        Support::log($config, 'webhook_raw_received', [
            'bytes' => strlen($raw),
            'sha1' => sha1($raw),
            'type' => is_array($data) ? (string)($data['type'] ?? ($data['data']['type'] ?? '')) : '',
            'keys' => $keys,
            'raw' => mb_substr($raw, 0, 4000, 'UTF-8'),
        ]);
    }
    if (!is_array($data)) {
        if (!empty($config['debug_raw_webhook'])) {
            Support::log($config, 'webhook_invalid_json', ['raw' => mb_substr($raw, 0, 2000, 'UTF-8')]);
        }
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>'invalid_json'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $n = UpdateNormalizer::one($data);
    if (!$n) {
        if (!empty($config['debug_raw_webhook'])) {
            Support::log($config, 'webhook_ignored_update', [
                'type' => (string)($data['type'] ?? ($data['data']['type'] ?? '')),
                'keys' => array_slice(array_keys($data), 0, 30),
                'raw' => mb_substr($raw, 0, 4000, 'UTF-8'),
            ]);
        }
        echo json_encode(['ok'=>true, 'ignored'=>true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($config['debug_raw_webhook']) && (string)($n['button_id'] ?? '') !== '') {
        Support::log($config, 'webhook_button_normalized', [
            'chat_id' => (string)($n['chat_id'] ?? ''),
            'sender_id' => (string)($n['sender_id'] ?? ''),
            'message_id' => (string)($n['message_id'] ?? ''),
            'button_id' => (string)($n['button_id'] ?? ''),
            'type' => (string)($n['type'] ?? ''),
        ]);
    }
    $db = new Database($config);
    $deliveryKey = hash('sha256', $raw);
    if (!$db->claimProcessedUpdate($deliveryKey, (int)($config['webhook_dedupe_seconds'] ?? 30))) {
        echo json_encode(['ok'=>true, 'duplicate'=>true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $app = new BotApp($config, $db, new RubikaClient($config));
    $app->processUpdate($n);
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    Support::log($config, 'webhook_error', ['error'=>$e->getMessage()]);
    http_response_code(500);
    $message = !empty($config['debug']) ? $e->getMessage() : 'internal_error';
    echo json_encode(['ok'=>false, 'error'=>$message], JSON_UNESCAPED_UNICODE);
}
