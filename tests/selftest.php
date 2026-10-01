<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && getenv('RUBIKAGM_SELFTEST_WASM') !== '1') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/src/Autoload.php';

use RubikaGM\Database;
use RubikaGM\Detector;
use RubikaGM\UpdateNormalizer;

function ok(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "OK: {$message}\n";
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($dir);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: pdo_sqlite is not installed\n");
    exit(2);
}

$tmpBase = getenv('RUBIKAGM_TEST_TMP') ?: sys_get_temp_dir();
$tmp = rtrim($tmpBase, '/') . '/rubikagm-selftest-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);

try {
    $config = require dirname(__DIR__) . '/config.sample.php';
    $config['bot_token'] = 'selftest-token';
    $config['owner_id'] = 'u-owner';
    $config['database']['driver'] = 'sqlite';
    $config['database']['sqlite_path'] = $tmp . '/storage/test.sqlite';
    if (getenv('RUBIKAGM_SELFTEST_WASM') === '1') {
        $config['database']['sqlite_journal_mode'] = 'DELETE';
    }
    $config['paths']['database'] = $tmp . '/storage/test.sqlite';
    $config['paths']['log'] = $tmp . '/logs/test.log';
    $config['paths']['backup'] = $tmp . '/backups';
    $config['maintenance_cleanup_interval'] = 86400;

    $db = new Database($config);
    ok($db->addWarning('g-test', 'u-test') === 1, 'global warning increments');
    ok($db->addScopedWarning('g-test', 'u-test', 'link') === 1, 'scoped warning increments');
    $db->resetScopedWarning('g-test', 'u-test', 'link');
    ok($db->getScopedWarning('g-test', 'u-test', 'link') === 0, 'one warning scope resets independently');
    ok($db->getWarning('g-test', 'u-test') === 1, 'global warning survives scoped reset');

    $deliveryKey = hash('sha256', 'same-update');
    ok($db->claimProcessedUpdate($deliveryKey, 30), 'first webhook delivery is claimed');
    ok(!$db->claimProcessedUpdate($deliveryKey, 30), 'duplicate webhook delivery is rejected');

    $db->registerPrivateChat('p-test', 'u-test');
    $db->unregisterPrivateChat('p-test', 'u-test');
    ok($db->privateChatCount() === 0, 'StoppedBot target can be removed');

    $stopped = UpdateNormalizer::one(['type'=>'StoppedBot', 'chat_id'=>'p-test', 'user_id'=>'u-test']);
    ok(($stopped['type'] ?? '') === 'StoppedBot', 'StoppedBot is normalized');
    $updated = UpdateNormalizer::one(['type'=>'UpdatedMessage', 'chat_id'=>'g-test', 'updated_message'=>['message_id'=>'m1','sender_id'=>'u1','text'=>'edited']]);
    ok(!empty($updated['is_edited']), 'UpdatedMessage is marked edited');

    $botJoin = UpdateNormalizer::one([
        'type'=>'NewChatMember',
        'chat_id'=>'g-test',
        'message_id'=>'m-join',
        'sender_id'=>'u-group-admin',
        'member'=>['user_id'=>'b-rubika-bot', 'name'=>'Test Bot'],
    ]);
    ok(($botJoin['join_user_id'] ?? '') === 'b-rubika-bot', 'joined bot id is preserved');
    ok(($botJoin['join_actor_id'] ?? '') === 'u-group-admin', 'group admin actor is preserved separately');

    $group = [
        'locks'=>['link'=>true,'rubika_id'=>false,'flood'=>false,'repeat'=>false],
        'bad_words'=>[], 'flood'=>['seconds'=>8,'max_messages'=>5], 'repeat'=>['seconds'=>60,'max_repeats'=>3],
    ];
    $baseUpdate = ['chat_id'=>'g-test','sender_id'=>'u1','has_caption'=>false];
    $mentionReasons = Detector::reasons($baseUpdate + ['text'=>'@sample_user'], $group, $db);
    ok(!in_array('لینک', $mentionReasons, true), 'link lock does not block a plain mention');
    $linkReasons = Detector::reasons($baseUpdate + ['text'=>'https://rubika.ir/sample'], $group, $db);
    ok(in_array('لینک', $linkReasons, true), 'link lock detects an actual link');

    $backup = $db->backup();
    ok(is_file($backup) && filesize($backup) > 0, 'SQLite backup uses a valid snapshot');

    echo "ALL SELF-TESTS PASSED\n";
} finally {
    remove_tree($tmp);
}
