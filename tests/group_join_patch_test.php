<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Autoload.php';

use RubikaGM\UpdateNormalizer;

function assert_same(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "OK: {$label}\n";
}

$rootJoin = UpdateNormalizer::one([
    'type' => 'NewChatMember',
    'chat_id' => 'g-test',
    'message_id' => 'm-root',
    'sender_id' => 'u-group-admin',
    'member' => ['user_id' => 'b-rubika-bot', 'name' => 'Test Bot'],
]);
assert_same($rootJoin['type'] ?? '', 'MemberJoined', 'root join is not misclassified as leave');
assert_same($rootJoin['join_user_id'] ?? '', 'b-rubika-bot', 'joined bot id is preserved');
assert_same($rootJoin['join_actor_id'] ?? '', 'u-group-admin', 'root actor id is preserved');

$messageJoin = UpdateNormalizer::one([
    'type' => 'NewMessage',
    'chat_id' => 'g-test',
    'new_message' => [
        'message_id' => 'm-wrapped',
        'sender_id' => 'u-second-admin',
        'new_chat_member' => ['user_id' => 'b-rubika-bot', 'name' => 'Test Bot'],
    ],
]);
assert_same($messageJoin['member_joined'] ?? false, true, 'message-wrapped join is detected');
assert_same($messageJoin['member_left'] ?? true, false, 'message-wrapped join is not leave');
assert_same($messageJoin['join_actor_id'] ?? '', 'u-second-admin', 'message actor id is preserved');

$leave = UpdateNormalizer::one([
    'type' => 'MemberLeft',
    'chat_id' => 'g-test',
    'message_id' => 'm-left',
    'sender_id' => 'u-admin',
    'member' => ['user_id' => 'u-left'],
]);
assert_same($leave['type'] ?? '', 'MemberLeft', 'real leave remains detected');
assert_same($leave['left_user_id'] ?? '', 'u-left', 'left user id is preserved');

echo "ALL GROUP JOIN PATCH TESTS PASSED\n";
