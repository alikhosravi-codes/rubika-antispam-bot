<?php
require __DIR__ . '/../src/Autoload.php';

use RubikaGM\BotApp;
use RubikaGM\UpdateNormalizer;
use RubikaGM\RubikaClient;

function ok89(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
}

function n89(string $id, array $message): array {
    $n = UpdateNormalizer::one([
        'type' => 'NewMessage',
        'chat_id' => 'g0_v89',
        'new_message' => array_merge([
            'message_id' => $id,
            'sender_id' => 'u0_v89',
        ], $message),
    ]);
    if (!is_array($n)) {
        fwrite(STDERR, "FAIL: normalizer returned null for {$id}\n");
        exit(1);
    }
    return $n;
}

// ادمین همیشه bypass می‌شود، حتی اگر تنظیم قدیمی true باقی مانده باشد.
$app = (new ReflectionClass(BotApp::class))->newInstanceWithoutConstructor();
$bypass = new ReflectionMethod(BotApp::class, 'shouldBypassLocks');
$bypass->setAccessible(true);
ok89($bypass->invoke($app, true, false, ['apply_locks_to_privileged' => true]) === true, 'privileged sender always bypasses locks');
ok89($bypass->invoke($app, false, true, ['apply_locks_to_trusted' => false]) === true, 'trusted sender bypasses when trusted moderation is disabled');
ok89($bypass->invoke($app, false, true, ['apply_locks_to_trusted' => true]) === false, 'trusted sender can be moderated when explicitly enabled');
ok89($bypass->invoke($app, false, false, []) === false, 'normal sender is moderated');

$round = n89('round-video', [
    'video_note' => ['id' => 'round-1', 'mime_type' => 'video/mp4'],
]);
ok89(!empty($round['has_video']) && $round['content_type'] === 'video', 'round/video_note is detected as video');
ok89(($round['file_id'] ?? '') === 'round-1', 'content-node id is exposed as file_id');

$nestedGif = n89('nested-gif', [
    'attachment' => [
        'animation_message' => [
            'id' => 'gif-nested-1',
            'file_name' => 'clip.mp4',
            'is_animation' => true,
        ],
    ],
]);
ok89(!empty($nestedGif['has_gif']) && empty($nestedGif['has_video']), 'nested animation flag is detected only as GIF');

$unknownFile = n89('unknown-file-id', [
    'file' => ['id' => 'generic-file-id'],
]);
ok89(($unknownFile['file_id'] ?? '') === 'generic-file-id', 'generic file id is retained for secondary detection');
ok89(!empty($unknownFile['has_document']), 'unknown generic file stays document before a successful probe');

$heic = n89('heic-photo', [
    'file' => ['id' => 'img-heic', 'file_name' => 'camera.heif'],
]);
ok89(!empty($heic['has_photo']) && empty($heic['has_document']), 'HEIF image is detected as photo, not generic file');

$video3gp = n89('video-3gp', [
    'file' => ['id' => 'vid-3gp', 'file_name' => 'mobile.3gp'],
]);
ok89(!empty($video3gp['has_video']) && empty($video3gp['has_document']), '3GP is detected as video, not generic file');

// پاسخ‌های تو در توی getFile باید قابل استخراج باشند.
$client = new RubikaClient(['bot_token' => 'test-token']);
$recursive = new ReflectionMethod(RubikaClient::class, 'firstScalarRecursive');
$recursive->setAccessible(true);
$getFileResponse = [
    'status' => 'OK',
    'result' => [
        'data' => [
            'file' => [
                'file_type' => 'Animation',
                'download_url' => 'https://cdn.example.test/f/a.mp4',
            ],
        ],
    ],
];
ok89($recursive->invoke($client, $getFileResponse, ['type','file_type']) === 'Animation', 'nested getFile type is extracted');
ok89($recursive->invoke($client, $getFileResponse, ['download_url','url']) === 'https://cdn.example.test/f/a.mp4', 'nested getFile URL is extracted');

echo "ALL V89 MEDIA/ADMIN REGRESSION TESTS PASSED\n";
