<?php
// RubikaGroupManagerPHP v85 one-time setup wizard with automatic Rubika endpoint setup.
// بعد از نصب موفق، نصاب خودش قفل می‌شود. برای تنظیم مجدد وبهوک از set_webhook_cli.php در ترمینال استفاده کن.
header('Content-Type: text/html; charset=utf-8');

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function randomSetupSecret(int $bytes = 24): string {
    try { return bin2hex(random_bytes($bytes)); } catch (Throwable $e) { return sha1(uniqid('', true) . microtime(true)); }
}
function isLocalHostName(string $host): bool {
    $host = strtolower(preg_replace('~:\d+$~', '', trim($host)));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}
function isOkRubikaResponse(array $res): bool {
    $status = strtoupper((string)($res['status'] ?? ''));
    return $status === 'OK' || ($res['ok'] ?? false) === true;
}
function rubikaRequest(string $token, string $method, array $payload, int $timeout = 20): array {
    if (!function_exists('curl_init')) {
        return ['ok'=>false, 'error'=>'curl_extension_missing', '_method'=>$method];
    }
    $url = 'https://botapi.rubika.ir/v3/' . rawurlencode($token) . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno) return ['ok'=>false, 'error'=>'curl:' . $error, '_http_status'=>$status, '_method'=>$method];
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) return ['ok'=>false, 'error'=>'invalid_json', 'raw'=>$raw, '_http_status'=>$status, '_method'=>$method];
    $decoded['_http_status'] = $status;
    $decoded['_method'] = $method;
    return $decoded;
}
function detectBaseUrl(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'YOUR_DOMAIN');
    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::\d{1,5})?$/i', $host)) {
        $host = 'YOUR_DOMAIN';
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    // برای دامنه‌های واقعی، پیش‌فرض را HTTPS بگذار تا وبهوک روبیکا قابل قبول باشد.
    $scheme = ($isHttps || !isLocalHostName($host)) ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($dir === '/' || $dir === '.') $dir = '';
    return $scheme . '://' . $host . $dir;
}
function buildDefaultWebhookUrl(string $webhookKey): string {
    return detectBaseUrl() . '/webhook.php?key=' . rawurlencode($webhookKey);
}
function webhookUrlWithKey(string $url, string $key): string {
    $url = trim($url);
    if ($url === '') return '';
    $url = preg_replace('/([?&])key=[^&]*/i', '$1key=' . rawurlencode($key), $url, 1, $count) ?: $url;
    if (($count ?? 0) === 0) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'key=' . rawurlencode($key);
    }
    return $url;
}
function setRubikaWebhook(string $token, string $webhookUrl): array {
    $endpoints = [
        'ReceiveUpdate' => ['url'=>$webhookUrl, 'type'=>'ReceiveUpdate'],
        'ReceiveInlineMessage' => ['url'=>$webhookUrl, 'type'=>'ReceiveInlineMessage'],
    ];
    $results = [];
    $allOk = true;
    foreach ($endpoints as $name => $payload) {
        $res = rubikaRequest($token, 'updateBotEndpoints', $payload, 20);
        $res['_payload'] = $payload;
        $res['_endpoint'] = $name;
        $res['_ok'] = isOkRubikaResponse($res);
        $results[$name] = $res;
        if (!$res['_ok']) $allOk = false;
    }
    return ['ok'=>$allOk, 'endpoints'=>$results, 'attempts'=>array_values($results)];
}
function writeSetupLock(string $lockFile, string $setupKeyFile): bool {
    $dir = dirname($lockFile);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;
    $ok = @file_put_contents($lockFile, 'locked_at=' . date('c') . PHP_EOL, LOCK_EX) !== false;
    if ($ok) {
        @chmod($lockFile, 0640);
        @unlink($setupKeyFile);
    }
    return $ok;
}
function envChecks(string $root): array {
    $drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
    return [
        'PHP' => PHP_VERSION,
        'cURL' => function_exists('curl_init') ? 'OK' : 'MISSING',
        'JSON' => function_exists('json_encode') ? 'OK' : 'MISSING',
        'PDO' => class_exists('PDO') ? 'OK' : 'MISSING',
        'PDO drivers' => $drivers ? implode(', ', $drivers) : 'MISSING',
        'mbstring' => function_exists('mb_strlen') ? 'OK' : 'POLYFILL',
        'ZipArchive' => class_exists('ZipArchive') ? 'OK' : 'OPTIONAL_MISSING',
        'root writable for config.php' => is_writable($root) ? 'OK' : 'CHECK_PERMISSION',
        'storage writable' => is_writable($root . '/storage') ? 'OK' : 'CHECK_PERMISSION',
        'Detected base URL' => detectBaseUrl(),
    ];
}

function verifyDatabaseConfig(array $config, string $root): void {
    $driver = (string)($config['database']['driver'] ?? 'sqlite');
    if (!class_exists('PDO') || !in_array($driver, PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException("PDO driver {$driver} روی هاست فعال نیست.");
    }
    require_once $root . '/src/Autoload.php';
    new RubikaGM\Database($config);
}

$root = __DIR__;
$earlyLockFile = $root . '/storage/setup.lock';
if (is_file($earlyLockFile)) {
    http_response_code(403);
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:tahoma;padding:30px"><h3>نصاب قفل است</h3><p>نصب قبلاً کامل شده است. فایل‌های setup.php و setup_v80.php را حذف کن.</p></body></html>';
    exit;
}
$setupKeyFile = $root . '/storage/setup.key';
if (!is_dir(dirname($setupKeyFile))) @mkdir(dirname($setupKeyFile), 0775, true);
if (!is_file($setupKeyFile)) {
    $newKey = randomSetupSecret(24);
    if (@file_put_contents($setupKeyFile, $newKey . PHP_EOL, LOCK_EX) === false) {
        http_response_code(500);
        echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body><h3>ساخت کلید نصب انجام نشد</h3><p>پوشه storage باید قابل نوشتن باشد.</p></body></html>';
        exit;
    }
    @chmod($setupKeyFile, 0600);
}
$setupKey = trim((string)@file_get_contents($setupKeyFile));
if ($setupKey === '' || !hash_equals($setupKey, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:tahoma;padding:30px"><h3>دسترسی نصب مجاز نیست</h3><p>کلید یک‌بارمصرف داخل فایل <code>storage/setup.key</code> ساخته شده است. آن را از File Manager یا SSH بخوان و به آدرس اضافه کن.</p><code>setup.php?key=کلید_داخل_فایل</code></body></html>';
    exit;
}

$configFile = $root . '/config.php';
$sampleFile = $root . '/config.sample.php';
$lockFile = $root . '/storage/setup.lock';
$errors = [];
$ok = false;
$webhookResult = null;
$createdConfig = false;
$existingConfig = file_exists($configFile);
$config = null;

if (is_file($lockFile)) {
    http_response_code(403);
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:tahoma;padding:30px"><h3>نصاب قفل است</h3><p>نصب قبلاً کامل شده است. برای امنیت، setup.php و setup_v80.php را حذف کن. برای تنظیم دوباره وبهوک از ترمینال و فایل set_webhook_cli.php استفاده کن.</p></body></html>';
    exit;
}

if (!file_exists($sampleFile) && !$existingConfig) {
    $errors[] = 'فایل config.sample.php پیدا نشد. setup.php باید کنار فایل‌های سورس v89 باشد.';
}
if ($existingConfig) {
    $loaded = @require $configFile;
    if (is_array($loaded)) $config = $loaded;
}

$generatedCronKey = randomSetupSecret(20);
$generatedWebhookKey = randomSetupSecret(24);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'install');

    if ($action === 'set_webhook_only') {
        if (!$config) {
            $errors[] = 'config.php قابل خواندن نیست. اول نصب را کامل کن.';
        } else {
            $botToken = trim((string)($config['bot_token'] ?? ''));
            $webhookKey = trim((string)($config['webhook_key'] ?? ($config['cron_key'] ?? '')));
            $webhookUrl = trim((string)($_POST['webhook_url'] ?? '')) ?: buildDefaultWebhookUrl($webhookKey);
            $webhookUrl = webhookUrlWithKey($webhookUrl, $webhookKey);
            if ($botToken === '' || str_contains($botToken, 'PUT_RUBIKA')) $errors[] = 'توکن داخل config.php معتبر نیست.';
            if ($webhookKey === '') $errors[] = 'webhook_key یا cron_key داخل config.php خالی است.';
            if ($webhookUrl === '' || !preg_match('~^https://~i', $webhookUrl)) $errors[] = 'آدرس وبهوک باید HTTPS باشد.';
            if (!$errors) {
                $webhookResult = setRubikaWebhook($botToken, $webhookUrl);
                $ok = (bool)$webhookResult['ok'];
                if ($ok && !writeSetupLock($lockFile, $setupKeyFile)) {
                    $ok = false;
                    $errors[] = 'وبهوک تنظیم شد، اما قفل امنیتی نصب ساخته نشد. دسترسی پوشه storage را اصلاح کن.';
                }
            }
        }
    } else {
        if ($existingConfig) {
            $errors[] = 'config.php از قبل وجود دارد. برای امنیت، نصاب آن را بازنویسی نمی‌کند. برای ست کردن وبهوک از دکمه «تنظیم وبهوک» پایین صفحه استفاده کن.';
        }
        if (!$errors) {
            $botToken = trim((string)($_POST['bot_token'] ?? ''));
            $ownerId = trim((string)($_POST['owner_id'] ?? ''));
            $cronKey = trim((string)($_POST['cron_key'] ?? ''));
            $webhookKey = trim((string)($_POST['webhook_key'] ?? ''));
            $botName = trim((string)($_POST['bot_display_name'] ?? 'ربات مدیریت گروه'));
            $timezone = trim((string)($_POST['timezone'] ?? 'Asia/Tehran'));
            $dbDriver = trim((string)($_POST['database_driver'] ?? 'sqlite'));
            $autoWebhook = !empty($_POST['auto_webhook']);
            $webhookUrl = trim((string)($_POST['webhook_url'] ?? '')) ?: buildDefaultWebhookUrl($webhookKey);
            $webhookUrl = webhookUrlWithKey($webhookUrl, $webhookKey);

            if ($botToken === '' || $botToken === 'PUT_RUBIKA_BOT_TOKEN_HERE') $errors[] = 'توکن ربات را وارد کن.';
            if ($ownerId === '' || $ownerId === 'PUT_OWNER_USER_ID_HERE') $errors[] = 'owner_id را وارد کن.';
            if ($cronKey === '' || strlen($cronKey) < 16) $errors[] = 'cron_key باید حداقل ۱۶ کاراکتر باشد.';
            if ($webhookKey === '' || strlen($webhookKey) < 16) $errors[] = 'webhook_key باید حداقل ۱۶ کاراکتر باشد.';
            if (!in_array($dbDriver, ['sqlite','mysql'], true)) $errors[] = 'نوع دیتابیس معتبر نیست.';
            if ($autoWebhook && !preg_match('~^https://~i', $webhookUrl)) $errors[] = 'برای ست کردن خودکار وبهوک، آدرس باید HTTPS باشد.';
            if (!in_array($timezone, timezone_identifiers_list(), true)) $errors[] = 'Timezone واردشده معتبر نیست.';

            if (!$errors) {
                $config = require $sampleFile;
                $config['bot_token'] = $botToken;
                $config['owner_id'] = $ownerId;
                $config['cron_key'] = $cronKey;
                $config['webhook_key'] = $webhookKey;
                $config['bot_display_name'] = $botName !== '' ? $botName : 'ربات مدیریت گروه';
                $config['timezone'] = $timezone !== '' ? $timezone : 'Asia/Tehran';
                $config['database']['driver'] = $dbDriver;
                if ($dbDriver === 'mysql') {
                    $config['database']['mysql']['host'] = trim((string)($_POST['mysql_host'] ?? 'localhost')) ?: 'localhost';
                    $config['database']['mysql']['database'] = trim((string)($_POST['mysql_database'] ?? ''));
                    $config['database']['mysql']['username'] = trim((string)($_POST['mysql_username'] ?? ''));
                    $config['database']['mysql']['password'] = (string)($_POST['mysql_password'] ?? '');
                    if ($config['database']['mysql']['database'] === '' || $config['database']['mysql']['username'] === '') {
                        $errors[] = 'برای MySQL نام دیتابیس و نام کاربری لازم است.';
                    }
                }
            }
            if (!$errors) {
                try {
                    verifyDatabaseConfig($config, $root);
                } catch (Throwable $e) {
                    $errors[] = 'اتصال/ساخت دیتابیس انجام نشد: ' . $e->getMessage();
                }
            }
            if (!$errors) {
                foreach (['storage','logs','backups','data'] as $dir) {
                    if (!is_dir($root . '/' . $dir)) @mkdir($root . '/' . $dir, 0775, true);
                }
                $content = "<?php\nreturn " . var_export($config, true) . ";\n";
                if (@file_put_contents($configFile, $content) === false) {
                    $errors[] = 'نوشتن config.php انجام نشد. دسترسی پوشه را بررسی کن.';
                } else {
                    @chmod($configFile, 0640);
                    $createdConfig = true;
                    if ($autoWebhook) {
                        $webhookResult = setRubikaWebhook($botToken, $webhookUrl);
                        if (!empty($webhookResult['ok']) && !writeSetupLock($lockFile, $setupKeyFile)) {
                            $errors[] = 'وبهوک تنظیم شد، اما قفل امنیتی نصب ساخته نشد.';
                        }
                    } else {
                        if (!writeSetupLock($lockFile, $setupKeyFile)) {
                            $errors[] = 'قفل امنیتی نصب ساخته نشد. دسترسی پوشه storage را اصلاح کن.';
                        }
                    }
                    $ok = !$errors && (!$autoWebhook || !empty($webhookResult['ok']));
                }
            }
        }
    }
}

$currentWebhookKey = $config ? trim((string)($config['webhook_key'] ?? ($config['cron_key'] ?? ''))) : (string)($_POST['webhook_key'] ?? $generatedWebhookKey);
$defaultWebhookUrl = buildDefaultWebhookUrl($currentWebhookKey !== '' ? $currentWebhookKey : $generatedWebhookKey);
$checks = envChecks($root);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نصب RubikaGroupManagerPHP v89</title>
<style>body{font-family:tahoma,Arial;background:#f6f7fb;margin:0;padding:24px;color:#222}.box{max-width:900px;margin:auto;background:#fff;border-radius:16px;padding:22px;box-shadow:0 8px 28px #0001}input,select{width:100%;padding:11px;margin:6px 0 14px;border:1px solid #ddd;border-radius:10px;box-sizing:border-box}button{background:#2185d0;color:#fff;border:0;border-radius:10px;padding:12px 18px;cursor:pointer}.err{background:#ffecec;border:1px solid #ffb7b7;padding:12px;border-radius:10px;margin:12px 0}.ok{background:#eaffea;border:1px solid #91dd91;padding:12px;border-radius:10px;margin:12px 0}.warn{background:#fff8df;border:1px solid #efd27a;padding:12px;border-radius:10px;margin:12px 0}code,pre{direction:ltr;text-align:left;display:block;background:#f1f1f1;padding:8px;border-radius:8px;overflow:auto}.row{display:flex;gap:12px;align-items:center}.row input[type=checkbox]{width:auto}.small{color:#666;font-size:13px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.kv{background:#f8f8f8;border-radius:10px;padding:8px;direction:ltr;text-align:left}@media(max-width:700px){.grid{grid-template-columns:1fr}}</style></head>
<body><div class="box">
<h2>نصب RubikaGroupManagerPHP v89</h2>
<div class="warn">اگر دامنه یا SSL درست نباشد، ست وبهوک موفق نمی‌شود. اول مطمئن شو آدرس <code>webhook.php</code> از وب باز می‌شود.</div>
<h3>بررسی محیط</h3><div class="grid"><?php foreach($checks as $k=>$v): ?><div class="kv"><b><?=e($k)?></b>: <?=e($v)?></div><?php endforeach; ?></div>
<?php if ($errors): ?><div class="err"><?php foreach($errors as $er) echo '<div>'.e($er).'</div>'; ?></div><?php endif; ?>
<?php if ($ok): ?>
<div class="ok"><b>عملیات انجام شد.</b><?php if ($createdConfig): ?><br>فایل <code>config.php</code> ساخته شد.<?php endif; ?></div>
<?php if ($webhookResult !== null): ?>
    <?php if (!empty($webhookResult['ok'])): ?>
        <div class="ok">هر دو endpoint پیام‌های معمولی و دکمه‌های شیشه‌ای با موفقیت ثبت شدند.<pre><?=e(json_encode($webhookResult['endpoints'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre></div>
    <?php else: ?>
        <div class="err">config ساخته شد، اما ست کردن وبهوک موفق نبود. خروجی تلاش‌ها:<pre><?=e(json_encode($webhookResult['attempts'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre></div>
    <?php endif; ?>
<?php endif; ?>
<div class="warn">برای امنیت، بعد از اتمام نصب حتماً فایل‌های <code>setup.php</code> و <code>setup_v80.php</code> را از هاست حذف کن.</div>
<p>کرون پیام همگانی:</p><code>* * * * * /usr/local/bin/php <?=e($root)?>/cron_broadcast.php</code>
<?php endif; ?>

<?php if ($existingConfig): ?>
<div class="warn">config.php از قبل وجود دارد. نصاب آن را بازنویسی نمی‌کند. از این بخش فقط برای ست/آپدیت وبهوک استفاده کن. بعد از موفقیت، نصاب قفل می‌شود.</div>
<form method="post">
<input type="hidden" name="action" value="set_webhook_only">
<label>آدرس وبهوک</label><input name="webhook_url" value="<?=e($defaultWebhookUrl)?>">
<button type="submit">تنظیم وبهوک روبیکا</button>
</form>
<?php elseif (!$ok): ?>
<form method="post">
<input type="hidden" name="action" value="install">
<label>توکن ربات روبیکا</label><input name="bot_token" required>
<label>شناسه مالک اصلی owner_id</label><input name="owner_id" required>
<label>کلید کرون</label><input name="cron_key" value="<?=e($_POST['cron_key'] ?? $generatedCronKey)?>" required>
<label>کلید وبهوک</label><input name="webhook_key" value="<?=e($_POST['webhook_key'] ?? $generatedWebhookKey)?>" required>
<label>نام نمایشی ربات</label><input name="bot_display_name" value="<?=e($_POST['bot_display_name'] ?? 'ربات مدیریت گروه')?>">
<label>Timezone</label><input name="timezone" value="<?=e($_POST['timezone'] ?? 'Asia/Tehran')?>">
<label>دیتابیس</label><select name="database_driver"><option value="sqlite">SQLite پیشنهادی</option><option value="mysql">MySQL</option></select>
<h3>اگر MySQL انتخاب کردی</h3>
<label>Host</label><input name="mysql_host" value="localhost">
<label>Database</label><input name="mysql_database">
<label>Username</label><input name="mysql_username">
<label>Password</label><input name="mysql_password" type="password">
<h3>وبهوک</h3>
<div class="row"><input type="checkbox" name="auto_webhook" value="1" checked><label>بعد از ساخت config.php، وبهوک روبیکا خودکار ست شود</label></div>
<label>آدرس وبهوک</label><input name="webhook_url" value="<?=e($defaultWebhookUrl)?>">
<div class="small">آدرس باید HTTPS باشد. نصاب کلید را به URL اضافه می‌کند و webhook.php هم همان کلید را بررسی می‌کند.</div>
<button type="submit">ساخت config.php و تنظیم وبهوک</button>
</form>
<?php endif; ?>
</div></body></html>
