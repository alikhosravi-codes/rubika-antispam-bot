<?php
$config = require __DIR__ . '/bootstrap.php';
use RubikaGM\{Database,RubikaClient,BotApp,Support};

try {
    $db = new Database($config);
    $client = new RubikaClient($config);
    $app = new BotApp($config, $db, $client);
    echo "Rubika Group Manager worker started...\n";
    $app->runForever();
} catch (Throwable $e) {
    Support::log($config, 'worker_boot_error', ['error'=>$e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
