<?php
require __DIR__ . '/../src/Autoload.php';

use RubikaGM\Database;
use RubikaGM\Detector;
use RubikaGM\UpdateNormalizer;

function failTest(string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function ok(bool $condition, string $message): void {
    if (!$condition) failTest($message);
    echo "OK: {$message}\n";
}

function normalizeMessage(string $messageId, array $message): array {
    $payload = [
        'type' => 'NewMessage',
        'chat_id' => 'g0_test',
        'new_message' => array_merge([
            'message_id' => $messageId,
            'sender_id' => 'u0_test',
        ], $message),
    ];
    $n = UpdateNormalizer::one($payload);
    if (!$n) failTest("normalizer returned null for {$messageId}");
    return $n;
}

$cases = [
    ['gif_direct', ['gif' => ['file_id' => 'gif-1', 'file_name' => 'a.gif', 'mime_type' => 'image/gif']], 'has_gif', 'gif', 'گیف'],
    ['gif_generic', ['message_type' => 'Gif', 'file_inline' => ['file_id' => 'gif-2', 'type' => 'Animation']], 'has_gif', 'gif', 'گیف'],
    ['photo_generic', ['file' => ['file_id' => 'img-1', 'mime_type' => 'image/jpeg', 'file_name' => 'x.jpg']], 'has_photo', 'photo', 'عکس'],
    ['video_attachment', ['attachment' => ['id' => 'vid-1', 'media_type' => 'Video', 'mime' => 'video/mp4']], 'has_video', 'video', 'ویدیو'],
    ['video_with_thumbnail', ['attachment' => ['id' => 'vid-thumb', 'media_type' => 'Video', 'thumbnail' => ['mime_type' => 'image/jpeg']]], 'has_video', 'video', 'ویدیو'],
    ['voice_generic', ['file_inline' => ['id' => 'voice-1', 'file_type' => 'Voice', 'mime_type' => 'audio/ogg']], 'has_voice', 'voice', 'ویس'],
    ['music_generic', ['audio' => ['id' => 'music-1', 'mime_type' => 'audio/mpeg']], 'has_music', 'music', 'آهنگ'],
    ['sticker_scalar', ['message_type' => 'Sticker', 'sticker_id' => 'st-1'], 'has_sticker', 'sticker', 'استیکر'],
    ['document_pdf', ['document' => ['file_id' => 'doc-1', 'mime_type' => 'application/pdf', 'file_name' => 'a.pdf']], 'has_document', 'file', 'فایل'],
    ['poll_case', ['poll_message' => ['question' => 'test']], 'has_poll', 'poll', 'نظرسنجی'],
    ['contact_case', ['contact_message' => ['phone_number' => '09120000000']], 'has_contact', 'contact', 'مخاطب'],
    ['location_case', ['location' => ['latitude' => 35.7, 'longitude' => 51.4]], 'has_location', 'location', 'لوکیشن'],
];

$db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();

foreach ($cases as [$id, $message, $flag, $type, $reason]) {
    $n = normalizeMessage($id, $message);
    ok(!empty($n[$flag]), "{$id} sets {$flag}");
    ok(($n['content_type'] ?? '') === $type, "{$id} content_type={$type}");
    ok(strlen((string)($n['content_fingerprint'] ?? '')) === 64, "{$id} has fingerprint");

    $lockKey = match ($type) {
        'file' => 'file',
        default => $type,
    };
    $group = [
        'locks' => [$lockKey => true],
        'bad_words' => [],
        'flood' => ['max_messages' => 5, 'seconds' => 8],
        'repeat' => ['max_repeats' => 3, 'seconds' => 60],
    ];
    $reasons = Detector::reasons($n, $group, $db);
    ok(in_array($reason, $reasons, true), "{$id} activates {$reason} lock");

    $contentReasons = Detector::reasons($n, [
        'locks' => ['content' => true],
        'bad_words' => [],
        'flood' => ['max_messages' => 5, 'seconds' => 8],
        'repeat' => ['max_repeats' => 3, 'seconds' => 60],
    ], $db);
    ok(in_array('محتوا', $contentReasons, true), "{$id} activates generic content lock");

    if (in_array($type, ['photo', 'video', 'voice', 'gif', 'music'], true)) {
        $mediaReasons = Detector::reasons($n, [
            'locks' => ['media' => true],
            'bad_words' => [],
            'flood' => ['max_messages' => 5, 'seconds' => 8],
            'repeat' => ['max_repeats' => 3, 'seconds' => 60],
        ], $db);
        ok(in_array('رسانه', $mediaReasons, true), "{$id} activates generic media lock");
    }

    if (in_array($type, ['poll', 'contact', 'location', 'sticker'], true)) {
        $fileReasons = Detector::reasons($n, [
            'locks' => ['file' => true],
            'bad_words' => [],
            'flood' => ['max_messages' => 5, 'seconds' => 8],
            'repeat' => ['max_repeats' => 3, 'seconds' => 60],
        ], $db);
        ok(!in_array('فایل', $fileReasons, true), "{$id} is not misclassified as file");
    }
}

$sticker1 = normalizeMessage('sticker-repeat-1', ['sticker' => ['id' => 'same-sticker']]);
$sticker2 = normalizeMessage('sticker-repeat-2', ['sticker' => ['id' => 'same-sticker']]);
ok($sticker1['content_fingerprint'] === $sticker2['content_fingerprint'], 'same sticker keeps same fingerprint across message ids');

$photo1 = normalizeMessage('photo-1', ['file' => ['file_id' => 'photo-A', 'mime_type' => 'image/jpeg']]);
$photo2 = normalizeMessage('photo-2', ['file' => ['file_id' => 'photo-B', 'mime_type' => 'image/jpeg']]);
ok($photo1['content_fingerprint'] !== $photo2['content_fingerprint'], 'different media ids get different fingerprints');

$text = normalizeMessage('text-1', ['text' => 'سلام دنیا']);
ok($text['content_type'] === 'text', 'text content remains text');
ok(!empty($text['has_text']), 'text flag remains active');

echo "ALL CONTENT LOCK TESTS PASSED\n";
