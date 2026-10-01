<?php
require __DIR__ . '/../src/Autoload.php';

use RubikaGM\UpdateNormalizer;
use RubikaGM\RubikaClient;

function ok88(bool $value, string $message): void {
    if (!$value) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
}

$official = UpdateNormalizer::one([
    'type' => 'NewMessage',
    'chat_id' => 'g-test',
    'new_message' => [
        'message_id' => 'm-mp4',
        'sender_id' => 'u-test',
        'file' => [
            'file_id' => 'file-mp4-1',
            'file_name' => 'animation.mp4',
            'size' => '12345',
        ],
    ],
]);
ok88(is_array($official), 'official File payload normalizes');
ok88(($official['file_id'] ?? '') === 'file-mp4-1', 'file_id is exposed for secondary probe');
ok88(!empty($official['content_type_ambiguous']), 'MP4 without explicit type is marked ambiguous');
ok88(!empty($official['has_video']), 'ambiguous MP4 remains video-compatible before probe');

$typedGif = UpdateNormalizer::one([
    'type' => 'NewMessage',
    'chat_id' => 'g-test',
    'new_message' => [
        'message_id' => 'm-gif',
        'sender_id' => 'u-test',
        'file' => [
            'file_id' => 'file-gif-1',
            'file_name' => 'animation.mp4',
            'file_type' => 'Gif',
            'size' => '12345',
        ],
    ],
]);
ok88(!empty($typedGif['has_gif']), 'explicit Gif file_type is detected');
ok88(empty($typedGif['content_type_ambiguous']), 'explicit Gif type is not ambiguous');

$flagGif = UpdateNormalizer::one([
    'type' => 'NewMessage',
    'chat_id' => 'g-test',
    'new_message' => [
        'message_id' => 'm-gif-flag',
        'sender_id' => 'u-test',
        'file' => [
            'file_id' => 'file-gif-2',
            'file_name' => 'animation.mp4',
            'is_gif' => true,
        ],
    ],
]);
ok88(!empty($flagGif['has_gif']), 'nested is_gif flag is detected');

$client = new RubikaClient([
    'bot_token' => 'test-token',
    'media_detection' => [],
]);
$method = new ReflectionMethod(RubikaClient::class, 'scanMp4Tracks');
$method->setAccessible(true);

$videoHandler = "\x00\x00\x00\x20ftypisom" . str_repeat("\x00", 32)
    . 'hdlr' . str_repeat("\x00", 8) . 'vide' . str_repeat("\x00", 32) . 'avc1';
$silent = $method->invoke($client, $videoHandler, 'x.mp4', 'video/mp4');
ok88(!empty($silent['resolved']), 'silent MP4 track layout resolves');
ok88(!empty($silent['is_gif']), 'MP4 video without audio track is classified as Rubika GIF');

$videoAudio = $videoHandler . 'hdlr' . str_repeat("\x00", 8) . 'soun' . str_repeat("\x00", 16) . 'mp4a';
$normalVideo = $method->invoke($client, $videoAudio, 'x.mp4', 'video/mp4');
ok88(!empty($normalVideo['resolved']), 'MP4 with audio track resolves');
ok88(empty($normalVideo['is_gif']), 'MP4 with audio track remains normal video');

echo "ALL V88 GIF PROBE TESTS PASSED\n";
