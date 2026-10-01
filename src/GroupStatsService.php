<?php
namespace RubikaGM;

final class GroupStatsService
{
    public static function record(Database $db, string $chatId, string $userId): void
    {
        if ($chatId === '' || $userId === '') {
            return;
        }
        try {
            $db->recordMessageStat($chatId, $userId);
        } catch (\Throwable $e) {
            // آمار نباید سرعت یا عملکرد اصلی ربات را خراب کند.
        }
    }

    public static function groupStatsText(Database $db, array $group, string $ownerId = ''): string
    {
        return self::groupStatsPayload($db, $group, $ownerId)[0];
    }

    public static function groupStatsPayload(Database $db, array $group, string $ownerId = ''): array
    {
        $chatId = (string)($group['chat_id'] ?? '');
        $stats = $db->messageStats($chatId);
        $gstats = $db->stats($chatId);
        $ownerId = trim($ownerId);
        $adminsRaw = Support::jsonDecode($group['admins_json'] ?? '[]', []);
        $admins = [];
        foreach ($adminsRaw as $adminId) {
            $id = trim((string)$adminId);
            if ($id === '' || ($ownerId !== '' && hash_equals($ownerId, $id))) continue;
            $admins[$id] = true;
        }
        $trustedRaw = Support::jsonDecode($group['trusted_json'] ?? '[]', []);
        $trusted = [];
        foreach ($trustedRaw as $trustedId) {
            $id = trim((string)$trustedId);
            if ($id === '' || ($ownerId !== '' && hash_equals($ownerId, $id))) continue;
            $trusted[$id] = true;
        }
        $adminCount = count($admins);
        $special = count(array_replace($admins, $trusted));
        $title = trim((string)($group['title'] ?? 'گروه'));

        $top = method_exists($db, 'topUsersToday') ? $db->topUsersToday($chatId, 3) : $db->topUsers($chatId, 3);
        $medals = ['🥇','🥈','🥉'];
        $topLines = [];
        $mentions = [];
        foreach ($top as $i => $row) {
            $uid = (string)($row['user_id'] ?? '');
            $count = (int)($row['total'] ?? 0);
            $rank = self::rankInfo($db, $chatId, $uid);
            $nickname = trim((string)($rank['nickname'] ?? ''));
            $label = 'کاربر ' . self::faNum($i + 1);
            $shown = $nickname !== '' ? ($nickname . ' / ' . $label) : $label;
            $topLines[] = ($medals[$i] ?? '•') . ' ' . $shown . ' — ' . self::faNum($count) . ' پیام';
            if ($uid !== '') {
                $mentions[] = ['label' => $label, 'user_id' => $uid];
            }
        }
        if (!$topLines) {
            $topLines[] = 'هنوز آماری ثبت نشده است.';
        }

        $txt = "**╭─ 📊 آمار کلی گروه**\n" .
            "├ نام گروه: «{$title}»\n" .
            "├ کاربران ویژه: " . self::faNum($special) . "\n" .
            "├ ادمین‌های ربات: " . self::faNum($adminCount) . "\n" .
            "╰────────────\n\n" .
            "**📈 آمار فعالیت**\n" .
            "• پیام‌های امروز: " . self::faNum((int)$stats['today_messages']) . "\n" .
            "• تخلف‌ها: " . self::faNum((int)$gstats['violations']) . "\n" .
            "• کاربران اخطاری: " . self::faNum((int)$gstats['warned_users']) . "\n" .
            "• سکوت‌شده: " . self::faNum((int)$gstats['soft_bans']) . "\n\n" .
            "**🏆 سه کاربر برتر امروز**\n" . implode("\n", $topLines);

        return [$txt, MetadataBuilder::merge(
            MetadataBuilder::mentions($txt, $mentions),
            MetadataBuilder::bolds($txt, ['آمار کلی گروه', '📈 آمار فعالیت', '🏆 سه کاربر برتر امروز'])
        )];
    }

    public static function userStatsText(Database $db, string $chatId, string $userId): string
    {
        $s = $db->userStats($chatId, $userId);
        $rank = self::rankInfo($db, $chatId, $userId);
        $nickname = trim((string)($rank['nickname'] ?? ''));
        $origin = trim((string)($rank['origin'] ?? ''));
        $extra = '';
        if ($nickname !== '') $extra .= "\nلقب: {$nickname}";
        if ($origin !== '') $extra .= "\nاصل: {$origin}";
        return "📊 آمار شما\n" .
            "━━━━━━━━━━━━\n" .
            "تعداد اخطار: " . self::faNum((int)$s['warnings']) . "\n" .
            "تخلف‌ها: " . self::faNum((int)$s['violations']) . "\n" .
            "پیام‌های امروز: " . self::faNum((int)$s['today_messages']) . $extra;
    }

    private static function rankInfo(Database $db, string $chatId, string $userId): array
    {
        $data = Support::jsonDecode($db->getState('user_ranks_' . $chatId, '{}') ?: '{}', []);
        return is_array($data[$userId] ?? null) ? $data[$userId] : [];
    }

    private static function userDisplay(Database $db, string $chatId, string $userId): string
    {
        $rank = self::rankInfo($db, $chatId, $userId);
        $nickname = trim((string)($rank['nickname'] ?? ''));
        if ($nickname !== '') return $nickname;
        return 'کاربر';
    }

    private static function faNum(int $n): string
    {
        return strtr((string)$n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }
}
