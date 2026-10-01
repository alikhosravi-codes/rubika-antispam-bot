<?php
require_once __DIR__ . '/src/Autoload.php';

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    $configFile = __DIR__ . '/config.sample.php';
}
$config = require $configFile;

date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');

foreach (['storage', 'logs', 'backups'] as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

return $config;
