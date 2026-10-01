<?php
// CLI-only helper for setting Rubika webhook safely.
// Usage:
// php set_webhook_cli.php
// php set_webhook_cli.php "https://YOUR_DOMAIN/webhook.php?key=WEBHOOK_KEY"
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "ERROR: config.php not found\n");
    exit(1);
}
$config = require $configFile;
if (!is_array($config)) {
    fwrite(STDERR, "ERROR: config.php did not return array\n");
    exit(1);
}
$token = trim((string)($config['bot_token'] ?? ''));
$key = trim((string)($config['webhook_key'] ?? ($config['cron_key'] ?? '')));
if ($token === '' || str_contains($token, 'PUT_RUBIKA')) {
    fwrite(STDERR, "ERROR: bot_token is empty or invalid\n");
    exit(1);
}
if ($key === '') {
    fwrite(STDERR, "ERROR: webhook_key/cron_key is empty\n");
    exit(1);
}
$url = trim((string)($argv[1] ?? ''));
if ($url === '') {
    $host = trim((string)($config['domain'] ?? ''));
    if ($host === '') {
        fwrite(STDERR, "ERROR: pass webhook URL as first argument, example:\nphp set_webhook_cli.php 'https://example.com/webhook.php?key={$key}'\n");
        exit(1);
    }
    $url = 'https://' . preg_replace('~^https?://~', '', rtrim($host, '/')) . '/webhook.php?key=' . rawurlencode($key);
}
if (!preg_match('~^https://~i', $url)) {
    fwrite(STDERR, "ERROR: webhook URL must start with https://\n");
    exit(1);
}
$replaced = 0;
$url = preg_replace('/([?&])key=[^&]*/i', '$1key=' . rawurlencode($key), $url, 1, $replaced) ?: $url;
if ($replaced === 0) {
    $url .= (str_contains($url, '?') ? '&' : '?') . 'key=' . rawurlencode($key);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "ERROR: PHP cURL extension is missing\n");
    exit(1);
}
$attempts = [
    ['method'=>'updateBotEndpoints', 'payload'=>['url'=>$url, 'type'=>'ReceiveUpdate'], 'label'=>'main updates/messages'],
    // کلیک دکمه‌های شیشه‌ای endpoint جداگانه دارد.
    ['method'=>'updateBotEndpoints', 'payload'=>['url'=>$url, 'type'=>'ReceiveInlineMessage'], 'label'=>'inline button clicks'],
];
$endpointStatus = [];
$okCount = 0;
foreach ($attempts as $a) {
    $api = 'https://botapi.rubika.ir/v3/' . rawurlencode($token) . '/' . $a['method'];
    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($a['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $label = (string)($a['label'] ?? '');
    echo "\n===== {$a['method']}" . ($label !== '' ? " / {$label}" : '') . " | HTTP {$http} =====\n";
    echo $err ? "CURL ERROR: {$err}\n" : $res . "\n";

    $json = json_decode((string)$res, true);
    $status = strtoupper((string)($json['status'] ?? ''));
    $isOk = (($json['ok'] ?? false) === true || $status === 'OK');
    if ($isOk) {
        $okCount++;
    }
    $endpointStatus[(string)$a['payload']['type']] = $isOk;
}
$ok = !empty($endpointStatus['ReceiveUpdate']) && !empty($endpointStatus['ReceiveInlineMessage']);
echo "\nWEBHOOK URL: {$url}\n";
echo "OK ATTEMPTS: {$okCount}/" . count($attempts) . "\n";
echo $ok ? "✅ WEBHOOK SET OK\n" : "❌ WEBHOOK SET FAILED\n";
if (!$ok) {
    echo "⚠️ موفقیت ناقص قابل قبول نیست؛ هر دو endpoint باید OK باشند.\n";
}
exit($ok ? 0 : 2);
