<?php
namespace RubikaGM;

trait BotAppHelpers
{
    private function btn(string $id, string $text): array
    {
        return ['id' => $this->compactButtonId($id), 'type' => 'Simple', 'button_text' => $text];
    }

    private function debugLog(string $message, array $context = []): void
    {
        if (!empty($this->config['debug'])) {
            Support::log($this->config, $message, $context);
        }
    }

    private function panelPageBtn(string $chatId, string $page, string $text): array
    {
        $token = substr(sha1('panel-group:' . $chatId), 0, 10);
        try {
            $this->db->setState('panel_group:' . $token, $chatId);
        } catch (\Throwable $e) {
            Support::log($this->config, 'panel_group_alias_store_failed', [
                'chat_id' => $chatId,
                'token' => $token,
                'error' => $e->getMessage(),
            ]);
            return $this->btn('panel:' . $chatId . ':page:' . $page, $text);
        }
        return ['id' => 'pg:' . $token . ':' . $this->shortPanelPage($page), 'type' => 'Simple', 'button_text' => $text];
    }

    private function shortPanelPage(string $page): string
    {
        $page = $this->normalizePanelPage($page);
        if (preg_match('/^locks(\d+)$/', $page, $m)) {
            return 'l' . max(1, (int)$m[1]);
        }
        return match ($page) {
            'settings' => 's',
            'talker' => 't',
            'rules' => 'r',
            'tools' => 'o',
            default => 'm',
        };
    }

    private function expandShortPanelPage(string $page): string
    {
        $page = trim($page);
        if (preg_match('/^l(\d+)$/', $page, $m)) {
            return 'locks' . (int)$m[1];
        }
        return match ($page) {
            's' => 'settings',
            't' => 'talker',
            'r' => 'rules',
            'o' => 'tools',
            'm' => 'main',
            default => $this->normalizePanelPage($page),
        };
    }

    private function compactButtonId(string $id): string
    {
        $id = trim($id);

        // روبیکا در بعضی کلاینت‌ها/نسخه‌ها button_idهای بلند را قبول نمی‌کند
        // و کل inline_keypad پنل را حذف می‌کند. پس آی‌دی بلند را به alias کوتاه تبدیل می‌کنیم.
        // متن کامل عملیات داخل states ذخیره می‌شود و هنگام کلیک دوباره resolve می‌گردد.
        if ($id === '' || strlen($id) <= 32) {
            return $id;
        }

        $short = 'cb:' . substr(sha1($id), 0, 12);
        try {
            $this->db->setState('button_alias:' . $short, $id);
        } catch (\Throwable $e) {
            Support::log($this->config, 'button_alias_store_failed', [
                'id' => $id,
                'alias' => $short,
                'error' => $e->getMessage(),
            ]);
            // اگر دیتابیس به هر دلیل خطا داد، همان id اصلی را برمی‌گردانیم تا رفتار قبلی حفظ شود.
            return $id;
        }

        return $short;
    }

    private function resolveButtonAlias(string $buttonId): string
    {
        $buttonId = trim($buttonId);
        if (!str_starts_with($buttonId, 'cb:')) {
            return $buttonId;
        }

        try {
            $real = $this->db->getState('button_alias:' . $buttonId);
            if (is_string($real) && $real !== '') {
                return $real;
            }
        } catch (\Throwable $e) {
            Support::log($this->config, 'button_alias_resolve_failed', [
                'alias' => $buttonId,
                'error' => $e->getMessage(),
            ]);
        }

        return $buttonId;
    }

    private function lockButtonText(string $key, string $fa, array $locks): string
    {
        return (!empty($locks[$key]) ? '🔐 ' : '🔓 ') . $fa;
    }

    private function warningEnabled(array $group): bool
    {
        $locks = $group['locks'] ?? [];
        return array_key_exists('warnings_enabled', $locks) ? (bool)$locks['warnings_enabled'] : true;
    }

    private function punishAction(array $group): string
    {
        $locks = $group['locks'] ?? [];
        $v = (string)($locks['punish_action'] ?? 'ban');
        return in_array($v, ['delete', 'silence', 'kick', 'ban', 'none'], true) ? $v : 'ban';
    }

    private function punishActionLabel(string $action): string
    {
        return [
            'delete' => 'فقط حذف',
            'silence' => 'سکوت',
            'kick' => 'سیک',
            'ban' => 'بن',
            'none' => 'بدون جریمه',
        ][$action] ?? 'بن';
    }

    private function lockPunishActions(array $group): array
    {
        $locks = $group['locks'] ?? [];
        $actions = $locks['punish_actions'] ?? [];
        return is_array($actions) ? $actions : [];
    }

    private function lockPunishAction(array $group, string $lockKey): string
    {
        $actions = $this->lockPunishActions($group);
        $value = (string)($actions[$lockKey] ?? '');
        return in_array($value, ['delete', 'silence', 'kick', 'ban', 'none'], true) ? $value : $this->punishAction($group);
    }

    private function setLockPunishAction(string $chatId, array $group, string $lockKey, string $action): void
    {
        $lockKey = trim($lockKey);
        if ($lockKey === '' || !array_key_exists($lockKey, $this->lockDefinitions())) {
            return;
        }
        if (!in_array($action, ['delete', 'silence', 'kick', 'ban', 'none'], true)) {
            return;
        }
        $locks = $group['locks'] ?? [];
        $actions = $locks['punish_actions'] ?? [];
        if (!is_array($actions)) {
            $actions = [];
        }
        $actions[$lockKey] = $action;
        $locks['punish_actions'] = $actions;
        $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
    }

    private function nextLockPunishAction(string $current): string
    {
        $cycle = ['ban', 'silence', 'kick', 'delete', 'none'];
        $idx = array_search($current, $cycle, true);
        if ($idx === false) {
            return 'ban';
        }
        return $cycle[($idx + 1) % count($cycle)];
    }

    private function lockPunishButtonText(string $lockKey, array $group): string
    {
        return '⚖️ ' . $this->punishActionLabel($this->lockPunishAction($group, $lockKey));
    }

    private function lockWarningLimits(array $group): array
    {
        $locks = $group['locks'] ?? [];
        $limits = $locks['warning_limits'] ?? [];
        return is_array($limits) ? $limits : [];
    }

    private function lockWarningLimit(array $group, string $lockKey): int
    {
        $limits = $this->lockWarningLimits($group);
        $v = (int)($limits[$lockKey] ?? 0);
        return max(0, min(20, $v));
    }

    private function setLockWarningLimit(string $chatId, array $group, string $lockKey, int $limit): void
    {
        $lockKey = trim($lockKey);
        if ($lockKey === '' || !array_key_exists($lockKey, $this->lockDefinitions())) {
            return;
        }
        $locks = $group['locks'] ?? [];
        $limits = $locks['warning_limits'] ?? [];
        if (!is_array($limits)) {
            $limits = [];
        }
        $limit = max(0, min(20, $limit));
        if ($limit <= 0) {
            unset($limits[$lockKey]);
        } else {
            $limits[$lockKey] = $limit;
        }
        $locks['warning_limits'] = $limits;
        $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
    }

    private function nextLockWarningLimit(int $current): int
    {
        $cycle = [0, 3, 5, 10, 1, 2];
        $idx = array_search($current, $cycle, true);
        if ($idx === false) {
            return 0;
        }
        return $cycle[($idx + 1) % count($cycle)];
    }

    private function lockWarningButtonText(string $lockKey, array $group): string
    {
        $limit = $this->lockWarningLimit($group, $lockKey);
        return $limit > 0 ? '⚠️[' . $this->faNum($limit) . ']' : '⚠️ عمومی';
    }

    private function setLockOption(string $chatId, array $group, string $key, mixed $value): void
    {
        $locks = $group['locks'] ?? [];
        $locks[$key] = $value;
        $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
    }

    private function broadcastTargets(): array
    {
        // پیام همگانی به گروه‌های نصب‌شده و پیوی‌های ثبت‌شده صف می‌شود.
        $targets = [];
        foreach ($this->allGroups() as $g) {
            $id = (string)($g['chat_id'] ?? '');
            if ($id !== '' && !empty($g['enabled'])) {
                $targets[$id] = ['chat_id' => $id, 'type' => 'group'];
            }
        }
        foreach ($this->db->privateChats() as $id) {
            $id = (string)$id;
            if ($id !== '') {
                $targets[$id] = ['chat_id' => $id, 'type' => 'private'];
            }
        }
        return array_values($targets);
    }

    private function queueBroadcastToAllTargets(string $message, string $createdBy): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['job_id' => 0, 'total' => 0];
        }
        $targets = $this->broadcastTargets();
        if (!$targets) {
            return ['job_id' => 0, 'total' => 0];
        }
        $jobId = $this->db->createBroadcastJob($message, $createdBy, $targets);
        return ['job_id' => $jobId, 'total' => count($targets)];
    }

    public function processBroadcastQueue(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->pendingBroadcastTargets($limit);
        $processed = 0;
        $sent = 0;
        $failed = 0;
        $retrying = 0;
        $jobs = [];
        $maxAttempts = (int)($this->config['broadcast_max_attempts'] ?? 3);
        $maxAttempts = max(1, min(10, $maxAttempts));

        foreach ($rows as $row) {
            $jobId = (int)($row['job_id'] ?? 0);
            $targetChatId = (string)($row['chat_id'] ?? '');
            $message = (string)($row['message'] ?? '');
            $targetType = (string)($row['target_type'] ?? 'group');
            $attemptsBefore = (int)($row['attempts'] ?? 0);
            if ($jobId <= 0 || $targetChatId === '' || $message === '') {
                continue;
            }

            $this->db->markBroadcastRunning($jobId);
            $res = $this->client->sendMessage($targetChatId, $message, null);
            $ok = (($res['status'] ?? '') === 'OK' || ($res['ok'] ?? false));
            $err = $ok ? '' : (string)($res['dev_message'] ?? $res['error'] ?? $res['status'] ?? 'unknown_error');
            $this->db->markBroadcastTarget($jobId, $targetChatId, $ok, $err, $maxAttempts);

            $this->debugLog('broadcast_queue_result', [
                'job_id' => $jobId,
                'target_chat_id' => $targetChatId,
                'target_type' => $targetType,
                'attempt' => $attemptsBefore + 1,
                'max_attempts' => $maxAttempts,
                'ok' => $ok,
                'error' => $err,
                'response' => $res,
            ]);

            $processed++;
            if ($ok) {
                $sent++;
            } elseif (($attemptsBefore + 1) < $maxAttempts) {
                $retrying++;
            } else {
                $failed++;
            }
            $jobs[$jobId] = true;
        }

        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'retrying' => $retrying,
            'jobs' => array_keys($jobs),
            'summary' => $this->db->broadcastQueueSummary(),
        ];
    }

    private function latestBroadcastStatusText(): string
    {
        $jobs = $this->db->latestBroadcastJobs(5);
        if (!$jobs) {
            return "📣 هنوز هیچ پیام همگانی در صف ثبت نشده است.";
        }
        $lines = ["📣 آخرین پیام‌های همگانی"];
        foreach ($jobs as $j) {
            $status = match ((string)($j['status'] ?? '')) {
                'queued' => 'در صف',
                'running' => 'در حال ارسال',
                'done' => 'کامل شد',
                'partial' => 'ارسال ناقص',
                'failed' => 'ناموفق',
                'canceled' => 'لغو شده',
                default => (string)($j['status'] ?? '-'),
            };
            $lines[] = "• #" . (int)($j['id'] ?? 0) . " | {$status} | کل: " . (int)($j['total'] ?? 0) . " | موفق: " . (int)($j['sent'] ?? 0) . " | ناموفق: " . (int)($j['failed'] ?? 0);
        }
        $lines[] = '';
        $lines[] = 'برای لغو یک صف:';
        $lines[] = 'لغو همگانی ID';
        $lines[] = 'مثال: لغو همگانی 12';
        $lines[] = '';
        $lines[] = 'برای لغو همه صف‌های در انتظار/درحال ارسال:';
        $lines[] = 'لغو همگانی';
        return implode("
", $lines);
    }

    private function resolvePrivateSenderId(array $n): string
    {
        $chatId = trim((string)($n['chat_id'] ?? ''));
        $raw = is_array($n['raw'] ?? null) ? $n['raw'] : [];

        $candidates = [
            (string)($n['sender_id'] ?? ''),
            (string)($raw['sender_id'] ?? ''),
            (string)($raw['user_id'] ?? ''),
            (string)($raw['data']['sender_id'] ?? ''),
            (string)($raw['data']['user_id'] ?? ''),
            (string)($raw['inline_message']['sender_id'] ?? ''),
            (string)($raw['inline_message']['user_id'] ?? ''),
            (string)($raw['data']['inline_message']['sender_id'] ?? ''),
            (string)($raw['data']['inline_message']['user_id'] ?? ''),
            (string)($raw['message']['sender_id'] ?? ''),
            (string)($raw['message']['from_id'] ?? ''),
            (string)($raw['new_message']['sender_id'] ?? ''),
            (string)($raw['new_message']['from_id'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && str_starts_with($candidate, 'u')) {
                return $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $chatId;
    }

    private function groupsForUser(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }
        if ($this->isOwner($userId)) {
            return $this->allGroups();
        }
        $out = [];
        foreach ($this->allGroups() as $g) {
            $admins = Support::jsonDecode($g['admins_json'] ?? '[]', []);
            if (!is_array($admins)) {
                $admins = [];
            }
            $admins = array_values(array_unique(array_map('strval', $admins)));
            $chatId = (string)($g['chat_id'] ?? '');
            if (in_array($userId, $admins, true) || $this->groupCreatorId($chatId) === $userId) {
                $out[] = $g;
            }
        }
        return $out;
    }

    private function allGroups(): array
    {
        try {
            $rows = $this->db->pdo()->query('SELECT * FROM groups ORDER BY updated_at DESC')->fetchAll() ?: [];
            $out = [];
            foreach ($rows as $row) {
                $admins = Support::jsonDecode($row['admins_json'] ?? '[]', []);
                $enabled = !empty($row['enabled']);
                if ($enabled || (is_array($admins) && count($admins) > 0)) {
                    $out[] = $row;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            Support::log($this->config, 'all_groups_error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function ownerStatsText(): string
    {
        $groups = $this->allGroups();
        $total = count($groups);
        $enabled = 0;
        foreach ($groups as $g) {
            if (!empty($g['enabled'])) {
                $enabled++;
            }
        }
        $pdo = $this->db->pdo();
        $violations = (int)$pdo->query('SELECT COUNT(*) FROM violations')->fetchColumn();
        $warned = (int)$pdo->query('SELECT COUNT(*) FROM warnings WHERE count > 0')->fetchColumn();
        $muted = (int)$pdo->query('SELECT COUNT(*) FROM soft_bans')->fetchColumn();
        $privateChats = $this->db->privateChatCount();

        return "👑 پنل اصلی مالک\n\n" .
            "نام ربات: " . $this->botDisplayName() . "\n" .
            "گروه‌های نصب‌شده: {$total}\n" .
            "گروه‌های فعال: {$enabled}\n" .
            "کاربران پیوی: {$privateChats}\n" .
            "کل تخلف‌ها: {$violations}\n" .
            "کاربران اخطاری: {$warned}\n" .
            "سکوت نرم‌ها: {$muted}\n" .
            "پشتیبانی: " . $this->supportText();
    }

    private function groupLabel(array $g): string
    {
        $title = trim((string)($g['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }
        return $this->defaultGroupTitle((string)($g['chat_id'] ?? ''));
    }

    private function isCommand(string $text): bool
    {
        $text = $this->normalizeCommandText($text);
        if ($text === '') return false;
        $first = mb_substr($text, 0, 1, 'UTF-8');
        $hasPrefix = in_array($first, ['/', '.', '!', '#'], true);
        $clean = $this->cleanCommand($text);
        $key = $this->commandKey($clean);
        if (str_starts_with($key, 'راهنما') || str_starts_with($key, 'help')) {
            return true;
        }
        if ($this->lockFromCommandKey($key) !== null || $this->isLockSuffixPhrase($clean)) {
            return true;
        }
        $known = [
            'start','help','id','joined','status','نصبربات','راهاندازی','راهاندازیربات','حذفگروه','حذفربات','پاکسازیگروه','حذفگروه','حذفربات','پاکسازیگروه','deletegroup','removegroup','سخنگوروشن','سخنگوخاموش','talkeron','talkeroff','groupstats','mystats','on','off','panel','install','backup','exportsettings','importsettings','خروجیتنظیمات','ورودتنظیمات','بکاپتنظیمات','ذخیرهتنظیمات','settitle','admin','ownerpanel','groups','broadcast','send','settitle','warnlimit','warningon','warningoff','punishdelete','punishsilence','جریمههشدارسکوت','جریمههشدارحذف','جریمههشدارفقطحذف','جریمههشدارسیک','جریمههشداراخراج','جریمههشداربن','مجازاتهشدارسکوت','مجازاتهشدارحذف','مجازاتهشدارسیک','مجازاتهشداراخراج','مجازاتهشداربن','promote','demote','admins','openlink','filter','filterlist','filter_list','filter','filterlist','filter_list',
            'راهنما','راهنمای','نصبربات','راهاندازی','راهاندازیربات','حذفگروه','حذفربات','پاکسازیگروه','سخنگوروشن','سخنگوخاموش','آمارگروه','امارگروه','آمارم','امارم','آمارمن','امارمن','آیدی','ایدی','ايدي','شناسه','عضوشدم','تاییدعضویت','تاییدجوین','پنل','نصبربات','راهاندازی','راهاندازیربات','حذفگروه','حذفربات','پاکسازیگروه','سخنگوروشن','سخنگوخاموش','آمارگروه','امارگروه','آمارم','امارم','آمارمن','امارمن','نصب','بکاپ','خروجیتنظیمات','ورودتنظیمات','بکاپتنظیمات','ذخیرهتنظیمات','نامگروه','پنلاصلی','مدیریتکل','گروهها','گروههایم','گروههایمن','همگانی','ارسال','نامگروه','حداخطار','سقفاخطار','اخطارروشن','اخطارخاموش','هشدارروشن','هشدارخاموش','فقطحذف','سکوت','سکوتکردن','حذفسکوت','رفعسکوت','آزاد','ازاد','فیلتر','فیلترگروهی','لیستفیلتر','فیلترها','حذفگروه','حذفربات','پاکسازیگروه','تنظیمادمین','ادمینگروه','تنظیمادمینربات','حذفادمین','حذفادمینگروه','قفلهمه','بازهمه','اسپم','لیستسکوت','سکوتها','سکوتیها','ترفیع','ارتقا','ادمین','تنزیل','عزل','حذفادمین','ادمینها','ادمینهایربات','ادمینهایگروه','لیستادمین','لیستادمینها','مدیران',
            'فعال','فعالسازی','فعالسازیربات','روشن','روشنربات','خاموش','خاموشربات','وضعیت','وضعیتربات',
            'locklink','unlocklink','lockforward','unlockforward','lockid','unlockid','lockphone','unlockphone','lockmedia','unlockmedia','lockflood','unlockflood','lockrepeat','unlockrepeat',
            'قفللینک','قفلکردنلینک','بازلینک','بازکردنلینک','بازکردنقفللینک','قفلفوروارد','بازفوروارد','قفلآیدی','قفلایدی','بازآیدی','بازایدی','قفلشماره','بازشماره','قفلرسانه','بازرسانه','تنظیملقب','تنظیماصل','انتقالمالکیت','انتقالمالکیت','حذف','فونت','بیوچک','بیوگرافیچک','بیوچک',
        ];
        $known = array_merge($known, ['آمار','امار','stats','لغوهمگانی','لغوصفهمگانی','لغوصف','cancelbroadcast','پاکسازیسکوت','پاکسازیسکوتها','حذفهمهسکوت','حذفهمهسکوتها','clearmuted','clearmutes','آنبن','انبن','آن‌بن','ان‌بن','unban','آنسیک','انسیک','آن‌سیک','ان‌سیک','unkick','پاکسازیبن','پاکسازیلیستبن','حذفلیستبن','پاککردنلیستبن','clearbans','ریستدیتابیس','پاکسازیدیتابیس','پاککردندیتابیس','resetdb','cleardatabase','گزارشتخلفات','تخلفات','violationreport','punishkick','punishban','جریمههشدارسیک','جریمههشداراخراج','جریمههشداربن','سیک','بن','افزودنپاسخ','حذفپاسخ','لیستپاسخها','لیستپاسخ‌ها']);
        if (in_array($key, $known, true)) return true;
        foreach (['خروجی تنظیمات', 'بکاپ تنظیمات', 'ذخیره تنظیمات', 'ورود تنظیمات', 'ایمپورت تنظیمات', 'لغو همگانی', 'لغو همگانی ', 'لغو صف همگانی', 'لغو صف همگانی ', 'لغو صف', 'لغو صف ', 'cancel broadcast', 'cancel broadcast ', 'همگانی ', 'broadcast ', 'ارسال ', 'send ', 'قفل ', 'قفل کردن ', 'باز ', 'باز کردن ', 'بازکردن ', 'باز کردن قفل ', 'بازکردن قفل ', 'ترفیع ', 'تنزیل ', 'ارتقا ', 'عزل ', 'ادمین ', 'حذف ادمین ', 'سکوت ', 'سکوت کردن ', 'حذف سکوت ', 'رفع سکوت ', 'اخطار ', 'بخشش ', 'اعتماد ', 'حذف اعتماد ', 'حذف دائمی ', 'آنبن ', 'آن بن ', 'ان بن ', 'unban ', 'آنسیک ', 'آن سیک ', 'ان سیک ', 'unkick ', 'پاکسازی لیست بن', 'پاکسازی بن', 'clear bans', 'کلمه ممنوعه ', 'حذف کلمه ', 'فیلتر ', 'حذف فیلتر ', 'اسپم ', 'حد اسپم ', 'تنظیم اسپم ', 'تنظیم اخطار ', 'هشدار ', 'اخطار ', 'سخنگو روشن', 'سخنگو خاموش', 'تنظیم ادمین', 'ادمین گروه', 'تنظیم ادمین ربات', 'حذف ادمین', 'تنظیم لقب ', 'تنظیم اصل ', 'انتقال مالکیت', 'حذف ', 'فونت ', 'افزودن پاسخ ', 'افزودن پاسخ دقیق ', 'افزودن پاسخ شامل ', 'حذف پاسخ ', 'جریمه ', 'جریمه هشدار ', 'تنظیم جریمه ', 'ریست دیتابیس', 'پاکسازی دیتابیس', 'پاک کردن دیتابیس', 'بن ', 'سیک ', 'اخراج ', 'ban ', 'kick ', 'راهنما ', 'راهنمای ', 'help '] as $prefix) {
            if (mb_stripos($clean, $prefix, 0, 'UTF-8') === 0) return true;
        }
        return $hasPrefix;
    }

    private function isKnownAdminCommandKey(string $key, string $clean): bool
    {
        if (str_starts_with($key, 'راهنما') || str_starts_with($key, 'help')) {
            return true;
        }
        if ($this->lockFromCommandKey($key) !== null || $this->isLockSuffixPhrase($clean)) {
            return true;
        }
        $admin = ['status','نصبربات','راهاندازی','راهاندازیربات','حذفگروه','حذفربات','پاکسازیگروه','حذفگروه','حذفربات','پاکسازیگروه','deletegroup','removegroup','سخنگوروشن','سخنگوخاموش','talkeron','talkeroff','groupstats','mystats','on','off','panel','install','backup','exportsettings','importsettings','خروجیتنظیمات','ورودتنظیمات','بکاپتنظیمات','ذخیرهتنظیمات','settitle','warnlimit','warningon','warningoff','punishdelete','punishsilence','جریمههشدارسکوت','جریمههشدارحذف','جریمههشدارفقطحذف','جریمههشدارسیک','جریمههشداراخراج','جریمههشداربن','مجازاتهشدارسکوت','مجازاتهشدارحذف','مجازاتهشدارسیک','مجازاتهشداراخراج','مجازاتهشداربن','promote','demote','admins','پنل','نصبربات','راهاندازی','راهاندازیربات','حذفگروه','حذفربات','پاکسازیگروه','سخنگوروشن','سخنگوخاموش','آمارگروه','امارگروه','آمارم','امارم','آمارمن','امارمن','نصب','بکاپ','خروجیتنظیمات','ورودتنظیمات','بکاپتنظیمات','ذخیرهتنظیمات','نامگروه','فعال','فعالسازی','فعالسازیربات','روشن','روشنربات','خاموش','خاموشربات','وضعیت','وضعیتربات','حداخطار','سقفاخطار','اخطارروشن','اخطارخاموش','سکوت','سکوتکردن','حذفسکوت','رفعسکوت','آزاد','ازاد','فقطحذف','ترفیع','ارتقا','ادمین','تنزیل','عزل','حذفادمین','ادمینها','ادمینهایربات','ادمینهایگروه','لیستادمین','لیستادمینها','مدیران','فیلتر','فیلترگروهی','لیستفیلتر','فیلترها','حذفگروه','حذفربات','پاکسازیگروه','تنظیمادمین','ادمینگروه','تنظیمادمینربات','حذفادمین','حذفادمینگروه','قفلهمه','بازهمه','اسپم','لیستسکوت','سکوتها','سکوتیها','muted','mutedlist','locklink','unlocklink','openlink','filter','filterlist','filter_list','filter','filterlist','filter_list','lockforward','unlockforward','lockid','unlockid','lockphone','unlockphone','lockmedia','unlockmedia','قفللینک','قفلکردنلینک','بازلینک','بازکردنلینک','بازکردنقفللینک','قفلفوروارد','بازفوروارد','قفلآیدی','قفلایدی','بازآیدی','بازایدی','قفلشماره','بازشماره','قفلرسانه','بازرسانه','تنظیملقب','تنظیماصل','انتقالمالکیت','حذف','فونت','بیوچک','بیوگرافیچک'];
        $admin = array_merge($admin, ['آمار','امار','stats','لغوهمگانی','لغوصفهمگانی','لغوصف','cancelbroadcast','پاکسازیسکوت','پاکسازیسکوتها','حذفهمهسکوت','حذفهمهسکوتها','clearmuted','clearmutes','آنبن','انبن','آن‌بن','ان‌بن','unban','آنسیک','انسیک','آن‌سیک','ان‌سیک','unkick','پاکسازیبن','پاکسازیلیستبن','حذفلیستبن','پاککردنلیستبن','clearbans','ریستدیتابیس','پاکسازیدیتابیس','پاککردندیتابیس','resetdb','cleardatabase','گزارشتخلفات','تخلفات','violationreport','punishkick','punishban','جریمههشدارسیک','جریمههشداراخراج','جریمههشداربن','سیک','بن','افزودنپاسخ','حذفپاسخ','لیستپاسخها','لیستپاسخ‌ها']);
        if (in_array($key, $admin, true)) return true;
        foreach (['خروجی تنظیمات', 'بکاپ تنظیمات', 'ذخیره تنظیمات', 'ورود تنظیمات', 'ایمپورت تنظیمات', 'لغو همگانی', 'لغو همگانی ', 'لغو صف همگانی', 'لغو صف همگانی ', 'لغو صف', 'لغو صف ', 'cancel broadcast', 'cancel broadcast ', 'همگانی ', 'broadcast ', 'ارسال ', 'send ', 'قفل ', 'قفل کردن ', 'باز ', 'باز کردن ', 'بازکردن ', 'باز کردن قفل ', 'بازکردن قفل ', 'ترفیع ', 'تنزیل ', 'ارتقا ', 'عزل ', 'ادمین ', 'حذف ادمین ', 'سکوت ', 'سکوت کردن ', 'حذف سکوت ', 'رفع سکوت ', 'اخطار ', 'بخشش ', 'اعتماد ', 'حذف اعتماد ', 'حذف دائمی ', 'آنبن ', 'آن بن ', 'ان بن ', 'unban ', 'آنسیک ', 'آن سیک ', 'ان سیک ', 'unkick ', 'پاکسازی لیست بن', 'پاکسازی بن', 'clear bans', 'کلمه ممنوعه ', 'حذف کلمه ', 'فیلتر ', 'حذف فیلتر ', 'اسپم ', 'حد اسپم ', 'تنظیم اسپم ', 'تنظیم اخطار ', 'هشدار ', 'اخطار ', 'سخنگو روشن', 'سخنگو خاموش', 'تنظیم ادمین', 'ادمین گروه', 'تنظیم ادمین ربات', 'حذف ادمین', 'تنظیم لقب ', 'تنظیم اصل ', 'انتقال مالکیت', 'حذف ', 'فونت ', 'افزودن پاسخ ', 'افزودن پاسخ دقیق ', 'افزودن پاسخ شامل ', 'حذف پاسخ ', 'جریمه ', 'جریمه هشدار ', 'تنظیم جریمه ', 'ریست دیتابیس', 'پاکسازی دیتابیس', 'پاک کردن دیتابیس', 'بن ', 'سیک ', 'اخراج ', 'ban ', 'kick ', 'راهنما ', 'راهنمای ', 'help '] as $prefix) {
            if (mb_stripos($clean, $prefix, 0, 'UTF-8') === 0) return true;
        }
        return false;
    }

    private function cleanCommand(string $text): string
    {
        $clean = $this->normalizeCommandText($text);
        $clean = preg_replace('/^[\/\.\!#]+/u', '', $clean) ?: $clean;
        $clean = preg_replace('/@.+$/u', '', $clean) ?: $clean;
        return $this->normalizeCommandText($clean);
    }

    private function commandKey(string $text): string
    {
        $text = $this->normalizeCommandText($text);
        $text = mb_strtolower($text, 'UTF-8');
        return str_replace([' ', '_', '-'], '', $text);
    }

    private function normalizeCommandText(string $text): string
    {
        $text = Support::normalizeDigits($text);
        $text = str_replace(["\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}", "\xC2\xA0"], ' ', $text);
        $text = strtr($text, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه']);
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        return trim($text);
    }

    private function lockKey(string $fa): ?string
    {
        $key = $this->commandKey($fa);
        foreach ($this->lockAliases() as $alias => $lock) {
            if ($key === $alias) {
                return $lock;
            }
        }
        return null;
    }

    private function lockName(string $key): string
    {
        return $this->lockDefinitions()[$key] ?? $key;
    }

    private function lockDefinitions(): array
    {
        return [
            'link' => 'لینک',
            'text' => 'متن',
            'rubika_id' => 'آیدی',
            'sticker' => 'استیکر',
            'photo' => 'عکس',
            'video' => 'ویدئو',
            'voice' => 'ویس',
            'file' => 'فایل',
            'gif' => 'گیف',
            'music' => 'آهنگ',
            'swear' => 'فحش',
            'bad_words' => 'کلمات غیرمجاز',
            'english_text' => 'متن انگلیسی',
            'reply' => 'ریپلای',
            'forward' => 'فوروارد',
            'edit' => 'ویرایش',
            'emoji' => 'ایموجی',
            'crash_code' => 'کد هنگی',
            'bio_check' => 'بیوچک',
            'poll' => 'نظرسنجی',
            'phone' => 'شماره تلفن',
            'number' => 'عدد',
            'hashtag' => 'هشتگ',
            'metadata' => 'متادیتا',
            'caption' => 'کپشن',
            'content' => 'محتوا',
            'flood' => 'اسپم',
            'repeat' => 'تکرار پیام',
            'email' => 'ایمیل',
            'media' => 'رسانه',
            'contact' => 'مخاطب',
            'location' => 'لوکیشن',
            'large_file' => 'فایل حجیم',
        ];
    }

    private function lockAliases(): array
    {
        return [
            'لینک'=>'link','link'=>'link',
            'متن'=>'text','text'=>'text',
            'آیدی'=>'rubika_id','ایدی'=>'rubika_id','ايدي'=>'rubika_id','id'=>'rubika_id','rubikaid'=>'rubika_id',
            'استیکر'=>'sticker','sticker'=>'sticker',
            'عکس'=>'photo','تصویر'=>'photo','photo'=>'photo','image'=>'photo',
            'ویدیو'=>'video','ویدئو'=>'video','فیلم'=>'video','video'=>'video',
            'ویس'=>'voice','صدا'=>'voice','پیامصوتی'=>'voice','voice'=>'voice',
            'فایل'=>'file','file'=>'file',
            'گیف'=>'gif','gif'=>'gif',
            'آهنگ'=>'music','موزیک'=>'music','music'=>'music','audio'=>'music',
            'فحش'=>'swear','swear'=>'swear','badlanguage'=>'swear',
            'کلمات'=>'bad_words','کلماتممنوعه'=>'bad_words','کلماتغیرمجاز'=>'bad_words','غیرمجاز'=>'bad_words','badwords'=>'bad_words',
            'متنانگلیسی'=>'english_text','انگلیسی'=>'english_text','english'=>'english_text','englishtext'=>'english_text',
            'ریپلای'=>'reply','reply'=>'reply',
            'فوروارد'=>'forward','forward'=>'forward',
            'ویرایش'=>'edit','ادیت'=>'edit','edit'=>'edit',
            'ایموجی'=>'emoji','emoji'=>'emoji',
            'کدهنگی'=>'crash_code','هنگی'=>'crash_code','crash'=>'crash_code','crashcode'=>'crash_code',
            'بیو'=>'bio_check','بیوچک'=>'bio_check','بیوگرافیچک'=>'bio_check','biocheck'=>'bio_check','bio'=>'bio_check',
            'نظرسنجی'=>'poll','poll'=>'poll',
            'شماره'=>'phone','شمارهتلفن'=>'phone','phone'=>'phone',
            'عدد'=>'number','number'=>'number','digits'=>'number',
            'هشتگ'=>'hashtag','hashtag'=>'hashtag',
            'متادیتا'=>'metadata','metadata'=>'metadata',
            'کپشن'=>'caption','caption'=>'caption',
            'محتوا'=>'content','content'=>'content',
            'اسپم'=>'flood','اسپمسریع'=>'flood','flood'=>'flood','spam'=>'flood',
            'تکرار'=>'repeat','تکرارپیام'=>'repeat','repeat'=>'repeat',
            'ایمیل'=>'email','email'=>'email',
            'رسانه'=>'media','media'=>'media',
            'مخاطب'=>'contact','contact'=>'contact',
            'لوکیشن'=>'location','مکان'=>'location','location'=>'location',
            'فایلحجیم'=>'large_file','حجیم'=>'large_file','largefile'=>'large_file',
        ];
    }


    private function lockKeyFromReason(string $reason): ?string
    {
        $reason = trim($reason);
        $map = [
            'لینک' => 'link',
            'آیدی/منشن' => 'rubika_id',
            'ایمیل' => 'email',
            'شماره تماس' => 'phone',
            'عدد' => 'number',
            'هشتگ' => 'hashtag',
            'متن انگلیسی' => 'english_text',
            'ایموجی' => 'emoji',
            'کد هنگی' => 'crash_code',
            'فحش' => 'swear',
            'بیوچک' => 'bio_check',
            'فوروارد' => 'forward',
            'ریپلای' => 'reply',
            'ویرایش' => 'edit',
            'متادیتا' => 'metadata',
            'کپشن' => 'caption',
            'فایل' => 'file',
            'رسانه' => 'media',
            'عکس' => 'photo',
            'ویدیو' => 'video',
            'ویس' => 'voice',
            'گیف' => 'gif',
            'آهنگ' => 'music',
            'استیکر' => 'sticker',
            'مخاطب' => 'contact',
            'لوکیشن' => 'location',
            'نظرسنجی' => 'poll',
            'فایل حجیم' => 'large_file',
            'کلمات غیرمجاز' => 'bad_words',
            'محتوا' => 'content',
            'متن' => 'text',
            'اسپم' => 'flood',
            'تکرار پیام' => 'repeat',
        ];
        return $map[$reason] ?? null;
    }

    private function warningScopeForReasons(array $reasons, array $group): ?string
    {
        foreach ($reasons as $reason) {
            $lockKey = $this->lockKeyFromReason((string)$reason);
            if ($lockKey !== null && $this->lockWarningLimit($group, $lockKey) > 0) {
                return $lockKey;
            }
        }
        return null;
    }

    private function firstLockForReasons(array $reasons): ?string
    {
        foreach ($reasons as $reason) {
            $lockKey = $this->lockKeyFromReason((string)$reason);
            if ($lockKey !== null) {
                return $lockKey;
            }
        }
        return null;
    }

    private function lockHasCustomPunishAction(array $group, string $lockKey): bool
    {
        $actions = $this->lockPunishActions($group);
        return array_key_exists($lockKey, $actions);
    }

    private function lockFromCommandKey(string $key): ?array
    {
        $aliases = $this->lockAliases();
        foreach ($aliases as $alias => $lock) {
            foreach (['lock', 'قفل', 'قفلکردن', 'بستن'] as $prefix) {
                if ($key === $prefix . $alias) {
                    return [$lock, true];
                }
            }
            foreach (['unlock', 'open', 'باز', 'بازکردن', 'بازکردنقفل'] as $prefix) {
                if ($key === $prefix . $alias) {
                    return [$lock, false];
                }
            }
            foreach (['lock', 'قفل', 'قفلکردن', 'بستن'] as $suffix) {
                if ($key === $alias . $suffix) {
                    return [$lock, true];
                }
            }
            foreach (['unlock', 'open', 'باز', 'بازکردن', 'بازکردنقفل'] as $suffix) {
                if ($key === $alias . $suffix) {
                    return [$lock, false];
                }
            }
        }
        return null;
    }

    private function isLockSuffixPhrase(string $clean): bool
    {
        if (!preg_match('/^(.+?)\s+(قفل|بستن|باز|بازکردن|باز کردن)$/u', $clean, $m)) {
            return false;
        }
        return $this->lockKey(trim($m[1])) !== null;
    }

    private function adminListText(string $chatId): string
    {
        $g = $this->db->getGroup($chatId);
        $admins = $this->groupAdminsForDisplay($g);

        $txt = "╭─ 👥 لیست ادمین‌های گروه
";
        $txt .= "├ گروه: " . $this->groupLabel($g) . "

";

        if (!$admins) {
            $txt .= "• ادمین گروهی توسط ربات ثبت نشده است.
";
        } else {
            $i = 1;
            foreach ($admins as $item) {
                $name = trim((string)($item['name'] ?? '')) ?: ('ادمین گروه ' . $i);
                $num = $this->faNum($i);
                $txt .= "• {$num}. {$name}
";
                $i++;
            }
        }

        $txt .= "╰─ برای دیدن آیدی، روی پیام کاربر ریپلای کن و بزن: آیدی";
        return $txt;
    }


    private function adminListPayload(string $chatId): array
    {
        $g = $this->db->getGroup($chatId);
        $admins = $this->groupAdminsForDisplay($g);
        $mentions = [];

        $txt = "╭─ 👥 لیست ادمین‌های گروه
";
        $txt .= "├ گروه: " . $this->groupLabel($g) . "

";

        if (!$admins) {
            $txt .= "• ادمین گروهی توسط ربات ثبت نشده است.
";
        } else {
            $i = 1;
            foreach ($admins as $item) {
                $id = (string)($item['user_id'] ?? '');
                $name = trim((string)($item['name'] ?? '')) ?: ('ادمین گروه ' . $i);
                $num = $this->faNum($i);
                $label = "{$num}. {$name}";
                $txt .= "• {$label}
";
                if ($id !== '') $mentions[] = ['label' => $label, 'user_id' => $id];
                $i++;
            }
        }

        $txt .= "╰─ برای دیدن آیدی، روی پیام کاربر ریپلای کن و بزن: آیدی";
        return [$txt, MetadataBuilder::merge(
            MetadataBuilder::mentions($txt, $mentions),
            MetadataBuilder::bolds($txt, ['لیست ادمین‌های گروه'])
        )];
    }

    private function groupAdminsForDisplay(array $group): array
    {
        $owner = trim((string)($this->config['owner_id'] ?? ''));
        $botNames = $this->botAdminNames($group);
        $out = [];

        foreach ($this->realGroupAdmins($group) as $item) {
            $id = trim((string)($item['user_id'] ?? ''));
            if ($id === '' || ($owner !== '' && hash_equals($owner, $id))) {
                continue;
            }
            $out[$id] = [
                'user_id' => $id,
                'name' => trim((string)($item['name'] ?? '')),
            ];
        }

        foreach (($group['group_admins'] ?? []) as $adminId) {
            $id = trim((string)$adminId);
            if ($id === '' || ($owner !== '' && hash_equals($owner, $id))) {
                continue;
            }
            if (!isset($out[$id])) {
                $out[$id] = [
                    'user_id' => $id,
                    'name' => trim((string)($botNames[$id] ?? '')),
                ];
            }
        }

        return array_values($out);
    }

    private function realGroupAdmins(array $group): array
    {
        $locks = $group['locks'] ?? [];
        $items = $locks['real_group_admins'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string)($item['user_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[$id] = [
                'user_id' => $id,
                'name' => mb_substr(trim((string)($item['name'] ?? '')), 0, 40, 'UTF-8'),
                'time' => (int)($item['time'] ?? time()),
            ];
        }
        return array_values($out);
    }

    private function setRealGroupAdmins(string $chatId, array $group, array $items): void
    {
        $locks = $group['locks'] ?? [];
        $locks['real_group_admins'] = array_values($items);
        $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
    }

    private function rememberRealGroupAdmin(string $chatId, array $group, string $userId, string $name = ''): void
    {
        $items = $this->realGroupAdmins($group);
        $found = false;
        foreach ($items as &$item) {
            if (($item['user_id'] ?? '') === $userId) {
                if ($name !== '') {
                    $item['name'] = mb_substr($name, 0, 40, 'UTF-8');
                }
                $item['time'] = time();
                $found = true;
                break;
            }
        }
        unset($item);
        if (!$found) {
            $items[] = [
                'user_id' => $userId,
                'name' => mb_substr($name, 0, 40, 'UTF-8'),
                'time' => time(),
            ];
        }
        $this->setRealGroupAdmins($chatId, $group, $items);
    }

    private function forgetRealGroupAdmin(string $chatId, array $group, string $userId): void
    {
        $items = array_values(array_filter($this->realGroupAdmins($group), fn($item) => ($item['user_id'] ?? '') !== $userId));
        $this->setRealGroupAdmins($chatId, $group, $items);
    }

    private function botAdminNames(array $group): array
    {
        $locks = $group['locks'] ?? [];
        $names = $locks['bot_admin_names'] ?? [];
        return is_array($names) ? $names : [];
    }

    private function rememberBotAdminName(string $chatId, array $group, string $userId, string $name = ''): void
    {
        if ($name === '') {
            return;
        }
        $locks = $group['locks'] ?? [];
        $names = $locks['bot_admin_names'] ?? [];
        if (!is_array($names)) {
            $names = [];
        }
        $names[$userId] = mb_substr($name, 0, 40, 'UTF-8');
        $locks['bot_admin_names'] = $names;
        $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
    }

    private function forgetBotAdminName(string $chatId, array $group, string $userId): void
    {
        $locks = $group['locks'] ?? [];
        $names = $locks['bot_admin_names'] ?? [];
        if (is_array($names) && array_key_exists($userId, $names)) {
            unset($names[$userId]);
            $locks['bot_admin_names'] = $names;
            $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
        }
    }

    private function isPrivileged(string $senderId, array $group): bool
    {
        $senderId = trim($senderId);
        if ($senderId === '') return false;
        if ($this->isOwner($senderId)) return true;
        if (in_array($senderId, $group['group_admins'] ?? [], true)) return true;
        $chatId = (string)($group['chat_id'] ?? '');
        return $chatId !== '' && $this->groupCreatorId($chatId) === $senderId;
    }

    private function isOwner(string $senderId): bool
    {
        $owner = trim((string)($this->config['owner_id'] ?? ''));
        return $senderId !== '' && $owner !== '' && hash_equals($owner, trim($senderId));
    }

    private function isTrusted(string $senderId, array $group): bool
    {
        return $senderId !== '' && in_array($senderId, $group['trusted_users'] ?? [], true);
    }

    private function isGroupChat(string $chatId): bool
    {
        return str_starts_with($chatId, 'g');
    }

    private function botShareUrl(): string
    {
        $share = (string)($this->config['bot_share_url'] ?? '');
        if ($share !== '') return $share;
        $me = $this->client->getMe();
        $url = $me['data']['bot']['share_url'] ?? '';
        return $url ?: 'https://rubika.ir/Antispombot';
    }


    private function rememberMessageSender(array $n): void
    {
        $chatId = (string)($n['chat_id'] ?? '');
        $messageId = (string)($n['message_id'] ?? '');
        $senderId = (string)($n['sender_id'] ?? '');
        if ($chatId === '' || $messageId === '' || $senderId === '' || !$this->isGroupChat($chatId)) return;
        $this->db->rememberMessageSender($chatId, $messageId, $senderId, $this->senderDisplayNameFromUpdate($n));
    }

    private function senderFromReply(array $n): string
    {
        $direct = trim((string)($n['reply_sender_id'] ?? ''));
        if ($direct !== '') return $direct;

        $message = $n['message'] ?? [];
        if (is_array($message)) {
            foreach (['reply_to_message', 'reply_message', 'replied_message', 'quoted_message'] as $rk) {
                if (isset($message[$rk]) && is_array($message[$rk])) {
                    $reply = $message[$rk];
                    foreach (['sender_id', 'from_id', 'author_object_guid', 'user_id', 'object_guid', 'guid'] as $k) {
                        $v = trim((string)($reply[$k] ?? ''));
                        if ($v !== '') return $v;
                    }
                    foreach (['sender', 'author', 'user', 'from'] as $parent) {
                        if (isset($reply[$parent]) && is_array($reply[$parent])) {
                            foreach (['sender_id', 'user_id', 'object_guid', 'guid', 'id'] as $k) {
                                $v = trim((string)($reply[$parent][$k] ?? ''));
                                if ($v !== '') return $v;
                            }
                        }
                    }
                }
            }
        }

        $chatId = (string)($n['chat_id'] ?? '');
        $replyId = (string)($n['reply_to_message_id'] ?? '');
        if ($chatId === '' || $replyId === '') return '';
        $row = $this->db->messageSender($chatId, $replyId);
        return (string)($row['sender_id'] ?? '');
    }

    private function replySenderLabel(array $n): string
    {
        $directName = trim((string)($n['reply_sender_name'] ?? ''));
        if ($directName !== '') return mb_substr($directName, 0, 40, 'UTF-8');

        $message = $n['message'] ?? [];
        if (is_array($message)) {
            foreach (['reply_to_message', 'reply_message', 'replied_message', 'quoted_message'] as $rk) {
                if (isset($message[$rk]) && is_array($message[$rk])) {
                    $reply = $message[$rk];
                    foreach (['sender_name','author_name','first_name','last_name','name','title','sender_title','username'] as $k) {
                        $v = trim((string)($reply[$k] ?? ''));
                        if ($v !== '') return mb_substr($v, 0, 40, 'UTF-8');
                    }
                    foreach (['sender','author','user','from'] as $parent) {
                        if (isset($reply[$parent]) && is_array($reply[$parent])) {
                            $first = trim((string)($reply[$parent]['first_name'] ?? ''));
                            $last = trim((string)($reply[$parent]['last_name'] ?? ''));
                            $name = trim($first . ' ' . $last);
                            if ($name !== '') return mb_substr($name, 0, 40, 'UTF-8');
                            foreach (['name','title','username'] as $k) {
                                $v = trim((string)($reply[$parent][$k] ?? ''));
                                if ($v !== '') return mb_substr($v, 0, 40, 'UTF-8');
                            }
                        }
                    }
                }
            }
        }

        $chatId = (string)($n['chat_id'] ?? '');
        $replyId = (string)($n['reply_to_message_id'] ?? '');
        if ($chatId === '' || $replyId === '') return '';
        $row = $this->db->messageSender($chatId, $replyId);
        $name = trim((string)($row['sender_name'] ?? ''));
        return $name !== '' ? $name : 'کاربر';
    }

    private function senderNameFromReply(array $n): string
    {
        $name = trim($this->replySenderLabel($n));
        return $name !== 'کاربر' ? $name : '';
    }

    private function senderDisplayNameFromUpdate(array $n): string
    {
        $message = $n['message'] ?? [];
        $raw = $n['raw'] ?? [];
        $candidates = [];
        if (is_array($message)) {
            foreach (['sender_name','author_name','first_name','last_name','name','title','sender_title'] as $k) {
                $v = trim((string)($message[$k] ?? ''));
                if ($v !== '') $candidates[] = $v;
            }
            foreach (['sender','author','user','from'] as $parent) {
                if (isset($message[$parent]) && is_array($message[$parent])) {
                    $first = trim((string)($message[$parent]['first_name'] ?? ''));
                    $last = trim((string)($message[$parent]['last_name'] ?? ''));
                    $name = trim($first . ' ' . $last);
                    if ($name !== '') $candidates[] = $name;
                    foreach (['name','title','username'] as $k) {
                        $v = trim((string)($message[$parent][$k] ?? ''));
                        if ($v !== '') $candidates[] = $v;
                    }
                }
            }
        }
        if (is_array($raw)) {
            foreach (['sender_name','author_name','first_name','name','sender_title'] as $k) {
                $v = trim((string)($raw[$k] ?? ''));
                if ($v !== '') $candidates[] = $v;
            }
        }
        $name = $candidates[0] ?? '';
        return mb_substr($name, 0, 40, 'UTF-8');
    }

    private function extractSilenceMinutes(string $clean): int
    {
        // عددهای فارسی/عربی را هم می‌شناسد: سکوت ۱۰، سکوت ١٠، سکوت10، سکوت ۱۰ دقیقه
        $clean = Support::normalizeDigits($clean);
        if (preg_match('/^(?:سکوت|سکوت کردن|silence|mute)\s*(\d{1,5})(?:\s*(?:دقیقه|min|minute|m))?\s*$/iu', $clean, $m)) {
            return max(1, min(43200, (int)$m[1]));
        }
        if (preg_match('/(?:^|\s)(\d{1,5})(?:\s*(?:دقیقه|min|minute|m))?\s*$/iu', $clean, $m)) {
            return max(1, min(43200, (int)$m[1]));
        }
        return 0;
    }

    private function formatMinuteDuration(int $minutes): string
    {
        $minutes = max(1, $minutes);
        if ($minutes < 60) {
            return $this->faNumber($minutes) . ' دقیقه';
        }
        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;
        if ($rem === 0) {
            return $hours === 1 ? 'یک ساعت' : $this->faNumber($hours) . ' ساعت';
        }
        return ($hours === 1 ? 'یک ساعت' : $this->faNumber($hours) . ' ساعت') . ' و ' . $this->faNumber($rem) . ' دقیقه';
    }

    private function formatRemainingSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = (int)ceil($seconds / 60);
        return $this->formatMinuteDuration(max(1, $minutes));
    }

    private function faNumber(int $number): string
    {
        return strtr((string)$number, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    private function extractUserIdFromCommand(string $clean): string
    {
        $clean = trim($clean);
        if (preg_match('/^(?:تنظیم\s+ادمین|تنظیم\s+ادمین\s+ربات|ادمین\s+گروه|حذف\s+ادمین|حذف\s+ادمین\s+گروه|ترفیع|ارتقا|ادمین|admin|promote|تنزیل|عزل|demote|unadmin|بن|ban|banuser|سیک|اخراج|kick|kickuser|آن\s*بن|آنبن|ان\s*بن|انبن|unban|آن\s*سیک|آنسیک|ان\s*سیک|انسیک|unkick)\s+([a-zA-Z0-9_\-]+)/u', $clean, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function extractSilenceTargetFromCommand(string $clean): string
    {
        $clean = trim(Support::normalizeDigits($clean));
        if (preg_match('/^(?:سکوت|سکوت\s+کردن|silence|mute)\s+([a-zA-Z_][a-zA-Z0-9_\-]{2,})$/iu', $clean, $m)) {
            return trim($m[1]);
        }
        return '';
    }


    private function isGroupAdminPromoteCommand(string $key, string $clean): bool
    {
        if (in_array($key, ['تنظیمادمین', 'ادمینگروه', 'ادمینکردنگروه', 'promoteadmin', 'groupadmin', 'تنظیمادمینربات'], true)) {
            return true;
        }
        return (bool)preg_match('/^(تنظیم ادمین|ادمین گروه|ادمین کردن گروه|promote admin|group admin|تنظیم ادمین ربات)(?:\s+|$)/u', $clean);
    }

    private function isGroupAdminDemoteCommand(string $key, string $clean): bool
    {
        if (in_array($key, ['حذفادمین', 'حذفادمینگروه', 'گرفتنادمین', 'demoteadmin', 'removeadmin'], true)) {
            return true;
        }
        return (bool)preg_match('/^(حذف ادمین|حذف ادمین گروه|گرفتن ادمین|demote admin|remove admin)(?:\s+|$)/u', $clean);
    }

    private function isPromoteCommand(string $key, string $clean): bool
    {
        if (in_array($key, ['ترفیع','ارتقا','ادمین','ادمینکردن','promote','admin'], true)) return true;
        return (bool)preg_match('/^(ترفیع|ارتقا|ادمین|admin|promote)\s+/u', $clean);
    }

    private function isDemoteCommand(string $key, string $clean): bool
    {
        if (in_array($key, ['تنزیل','عزل','گرفتنمقام','demote','unadmin'], true)) return true;
        return (bool)preg_match('/^(تنزیل|عزل|گرفتن مقام|demote|unadmin)\s+/u', $clean);
    }

    private function isSilenceCommand(string $key, string $clean): bool
    {
        $clean = Support::normalizeDigits($clean);
        $key = $this->commandKey($clean);
        if (in_array($key, ['سکوت', 'سکوتکردن', 'silence', 'mute'], true)) {
            return true;
        }
        return (bool)preg_match('/^(سکوت|سکوت کردن|silence|mute)(?:\s+|\d|$)/u', $clean);
    }

    private function isUnSilenceCommand(string $key, string $clean): bool
    {
        if (in_array($key, ['حذفسکوت', 'رفعسکوت', 'آزاد', 'ازاد', 'unsilence', 'unmute'], true)) {
            return true;
        }
        return (bool)preg_match('/^(حذف سکوت|رفع سکوت|آزاد|ازاد|unsilence|unmute)\s+/u', $clean);
    }

    private function isKickCommand(string $key, string $clean): bool
    {
        if (in_array($key, ['سیک', 'اخراج', 'اخراجکاربر', 'kick', 'kickuser'], true)) {
            return true;
        }
        return (bool)preg_match('/^(سیک|اخراج|kick)(?:\s+|$)/iu', $clean);
    }

    private function isBanCommand(string $key, string $clean): bool
    {
        if (in_array($key, ['بن', 'بنکاربر', 'ban', 'banuser'], true)) {
            return true;
        }
        return (bool)preg_match('/^(بن|ban)(?:\s+|$)/iu', $clean);
    }

    private function isUnbanCommand(string $key, string $clean): bool
    {
        if (in_array($key, ['آنبن', 'انبن', 'آنسیک', 'انسیک', 'unban', 'unkick'], true)) {
            return true;
        }
        return (bool)preg_match('/^(?:آن\s*بن|ان\s*بن|unban|آن\s*سیک|ان\s*سیک|unkick)(?:\s+|$)/iu', $clean);
    }

    private function isClearBanListCommand(string $key, string $clean): bool
    {
        if (in_array($key, ['پاکسازیبن', 'پاکسازیلیستبن', 'حذفلیستبن', 'پاککردنلیستبن', 'clearbans'], true)) {
            return true;
        }
        return (bool)preg_match('/^(?:پاکسازی|پاک\s*سازی|حذف|پاک\s*کردن)\s+(?:لیست\s+)?بن(?:\s*ها)?$/u', $clean)
            || (bool)preg_match('/^clear\s+bans$/iu', $clean);
    }

    private function fetchGroupTitle(string $chatId): string
    {
        try {
            $res = $this->client->getChat($chatId);
            $this->debugLog('get_chat_result', ['chat_id' => $chatId, 'response' => $res]);
            $chat = $res['data']['chat'] ?? $res['chat'] ?? [];
            if (is_array($chat)) {
                foreach (['title', 'chat_title', 'group_title', 'name'] as $k) {
                    $v = trim((string)($chat[$k] ?? ''));
                    if ($v !== '') {
                        return mb_substr($v, 0, 80, 'UTF-8');
                    }
                }
            }
        } catch (\Throwable $e) {
            Support::log($this->config, 'get_chat_title_error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }
        return '';
    }

    private function groupTitleFromUpdate(array $n): string
    {
        foreach (['chat_title','group_title','title'] as $k) {
            $v = trim((string)($n[$k] ?? ''));
            if ($v !== '') return mb_substr($v, 0, 80, 'UTF-8');
        }
        $raw = $n['raw'] ?? [];
        if (is_array($raw)) {
            foreach (['chat_title','group_title','title'] as $k) {
                $v = trim((string)($raw[$k] ?? ''));
                if ($v !== '') return mb_substr($v, 0, 80, 'UTF-8');
            }
            foreach (['chat','group','chat_info','group_info'] as $parent) {
                if (isset($raw[$parent]) && is_array($raw[$parent])) {
                    foreach (['title','chat_title','group_title','name'] as $k) {
                        $v = trim((string)($raw[$parent][$k] ?? ''));
                        if ($v !== '') return mb_substr($v, 0, 80, 'UTF-8');
                    }
                }
            }
        }
        return '';
    }

    private function defaultGroupTitle(string $chatId): string
    {
        if ($chatId === '') return 'گروه بدون نام';
        return 'گروه ' . mb_substr($chatId, -7, null, 'UTF-8');
    }



    private function looksLikeBotJoinEvent(array $n): bool
    {
        $uid = trim((string)($n['join_user_id'] ?? ''));
        $ids = $this->knownBotIds();
        if ($uid !== '' && in_array($uid, $ids, true)) {
            return true;
        }

        if (!$ids) {
            $name = $this->commandKey((string)($n['join_user_name'] ?? ''));
            $botName = $this->commandKey($this->botDisplayName());
            if ($name !== '' && $botName !== '' && ($name === $botName || str_contains($name, $botName) || str_contains($botName, $name))) {
                return true;
            }
        }

        return false;
    }

    private function knownBotIds(): array
    {
        $ids = [];
        foreach (['bot_id', 'bot_guid', 'bot_user_id'] as $k) {
            $v = trim((string)($this->config[$k] ?? ''));
            if ($v !== '' && !str_contains($v, 'PUT_')) $ids[] = $v;
            $s = trim((string)$this->db->getState($k, ''));
            if ($s !== '') $ids[] = $s;
        }

        if (!$ids) {
            try {
                $me = $this->client->getMe();
                foreach ($this->extractIdsFromArray($me, ['bot_id','bot_guid','user_id','object_guid','guid']) as $id) {
                    $ids[] = $id;
                }
                $ids = array_values(array_unique(array_filter($ids)));
                if ($ids) {
                    $this->db->setState('bot_id', $ids[0]);
                }
            } catch (\Throwable $e) {
                Support::log($this->config, 'get_me_for_join_error', ['error' => $e->getMessage()]);
            }
        }

        return array_values(array_unique(array_filter(array_map('strval', $ids))));
    }

    private function extractIdsFromArray(array $data, array $keys): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out = array_merge($out, $this->extractIdsFromArray($v, $keys));
                continue;
            }
            if (in_array((string)$k, $keys, true)) {
                $val = trim((string)$v);
                if ($val !== '') $out[] = $val;
            }
        }
        return $out;
    }

    private function autoRegisterGroupOnBotJoin(array $n, array $group): void
    {
        $chatId = (string)($n['chat_id'] ?? '');
        if ($chatId === '' || !$this->isGroupChat($chatId)) return;

        $title = $this->groupTitleFromUpdate($n);
        if ($title === '') $title = $this->fetchGroupTitle($chatId);
        if ($title === '') $title = $this->groupLabel($group);

        $admins = $group['group_admins'] ?? [];
        $actor = trim((string)($n['join_actor_id'] ?? ''));
        $actorName = trim((string)($n['join_actor_name'] ?? ''));
        if ($actor !== '' && !in_array($actor, $this->knownBotIds(), true)) {
            $admins[] = $actor;
            if ($this->groupCreatorId($chatId) === '') {
                $this->setGroupCreatorId($chatId, $actor);
            }
            $this->rememberRealGroupAdmin($chatId, $group, $actor, $actorName);
        }
        // مالک اصلی دسترسی سراسری دارد و نباید در فهرست ادمین‌های داخلی هر گروه ذخیره شود.
        $owner = trim((string)($this->config['owner_id'] ?? ''));
        $admins = array_values(array_unique(array_filter(array_map('strval', $admins), static function ($id) use ($owner) {
            $id = trim((string)$id);
            return $id !== '' && ($owner === '' || !hash_equals($owner, $id));
        })));

        // گروه از همان لحظه ثبت و فعال می‌شود؛ پس از مدیرشدن ربات در روبیکا، قفل‌ها قابل اجرا هستند.
        $this->db->updateGroup($chatId, [
            'title' => $title,
            'enabled' => 1,
            'admins_json' => Support::jsonEncode($admins),
        ]);
        $this->db->setState('bot_join_seen_' . $chatId, (string)time());

        $botName = $this->botDisplayName();
        if ($actor !== '') {
            $this->client->sendMessage($chatId, "**✅ {$botName} برای این گروه فعال شد.**
━━━━━━━━━━━━
مدیری که ربات را اضافه کرده، به‌عنوان مدیر داخلی این گروه ثبت شد.

حالا ربات را در تنظیمات گروه «مدیر» کن و مجوز حذف پیام را بده.
مدیریت: پیوی ربات ← /start ← 📋 گروه‌های من");
        } else {
            $this->client->sendMessage($chatId, "**✅ {$botName} برای این گروه فعال شد.**
━━━━━━━━━━━━
حالا ربات را در تنظیمات گروه «مدیر» کن و مجوز حذف پیام را بده.

برای ثبت مدیر داخلی و بازشدن پنل، یکی از مدیران گروه فقط یک‌بار داخل گروه بزند:
نصب");
        }
    }


    private function globalBadWordsEnabledRaw(): bool
    {
        return $this->db->getState('global_bad_words_enabled', '0') === '1';
    }

    private function globalBadWordsEnabled(): bool
    {
        return $this->globalBadWordsEnabledRaw() && count($this->globalBadWords()) > 0;
    }

    private function setGlobalBadWordsEnabled(bool $enabled): void
    {
        $this->db->setState('global_bad_words_enabled', $enabled ? '1' : '0');
    }

    private function globalBadWords(): array
    {
        $raw = $this->db->getState('global_bad_words', '[]') ?: '[]';
        $items = Support::jsonDecode($raw, []);
        if (!is_array($items)) {
            return [];
        }
        return array_values(array_filter(array_map(fn($x) => trim((string)$x), $items), fn($x) => $x !== ''));
    }

    private function setGlobalBadWords(array $words): void
    {
        $clean = [];
        foreach ($words as $word) {
            $word = trim((string)$word);
            if ($word !== '' && !in_array($word, $clean, true)) {
                $clean[] = mb_substr($word, 0, 80, 'UTF-8');
            }
        }
        $this->db->setState('global_bad_words', Support::jsonEncode(array_values($clean)));
    }

    private function addGlobalBadWords(string $text): int
    {
        $words = $this->globalBadWords();
        $before = count($words);
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $word = trim((string)$line);
            if ($word !== '' && !in_array($word, $words, true)) {
                $words[] = mb_substr($word, 0, 80, 'UTF-8');
            }
        }
        $this->setGlobalBadWords($words);
        return count($words) - $before;
    }

    private function globalBadWordsText(): string
    {
        $words = $this->globalBadWords();
        $txt = "🧹 فیلتر کلمات سراسری ربات\n";
        $txt .= "وضعیت: " . ($this->globalBadWordsEnabledRaw() ? 'روشن ✅' : 'خاموش ⛔') . "\n\n";
        if (!$words) {
            return $txt . "کلمه‌ای ثبت نشده است.";
        }
        $i = 1;
        foreach ($words as $word) {
            $txt .= $i . ") " . $word . "\n";
            $i++;
        }
        return trim($txt);
    }

    private function hasGlobalBadWord(string $text): bool
    {
        $hay = mb_strtolower($text, 'UTF-8');
        foreach ($this->globalBadWords() as $word) {
            $word = trim((string)$word);
            if ($word !== '' && mb_stripos($hay, mb_strtolower($word, 'UTF-8'), 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }

    private function addGroupBadWords(string $chatId, array $group, string $text): int
    {
        $words = $group['bad_words'] ?? [];
        if (!is_array($words)) {
            $words = [];
        }
        $before = count($words);
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $word = trim((string)$line);
            if ($word !== '' && !in_array($word, $words, true)) {
                $words[] = mb_substr($word, 0, 80, 'UTF-8');
            }
        }
        $this->db->updateGroup($chatId, ['bad_words_json' => Support::jsonEncode(array_values($words))]);
        return count($words) - $before;
    }

    private function groupBadWordsText(array $group): string
    {
        $words = $group['bad_words'] ?? [];
        if (!is_array($words)) {
            $words = [];
        }
        $txt = "🧹 فیلتر کلمات گروهی\n\n";
        if (!$words) {
            return $txt . "کلمه‌ای برای این گروه ثبت نشده است.";
        }
        $i = 1;
        foreach ($words as $word) {
            $txt .= $i . ") " . $word . "\n";
            $i++;
        }
        return trim($txt);
    }

    private function canUseBot(string $senderId): bool
    {
        if ($senderId === '') return false;
        if ($this->isOwner($senderId)) return true;
        if (!$this->globalForceJoinEnabled()) return true;
        return $this->isGlobalForceJoinVerified($senderId);
    }

    private function globalForceJoinEnabledRaw(): bool
    {
        return $this->db->getState('global_force_join_enabled', '0') === '1';
    }

    private function globalForceJoinEnabled(): bool
    {
        return $this->globalForceJoinEnabledRaw() && count($this->globalForceJoinChannels()) > 0;
    }

    private function setGlobalForceJoinEnabled(bool $enabled): void
    {
        $this->db->setState('global_force_join_enabled', $enabled ? '1' : '0');
    }

    private function globalForceJoinChannels(): array
    {
        $raw = $this->db->getState('global_force_join_channels', '[]') ?: '[]';
        $items = Support::jsonDecode($raw, []);
        return is_array($items) ? $items : [];
    }

    private function setGlobalForceJoinChannels(array $items): void
    {
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $title = trim((string)($item['title'] ?? 'عضویت اجباری'));
            $url = trim((string)($item['url'] ?? ''));
            if ($url === '') continue;
            $clean[] = [
                'title' => mb_substr($title !== '' ? $title : 'عضویت اجباری', 0, 80, 'UTF-8'),
                'url' => $url,
            ];
        }
        $this->db->setState('global_force_join_channels', Support::jsonEncode($clean));
    }

    private function addGlobalForceJoinChannels(string $text): int
    {
        $items = $this->globalForceJoinChannels();
        $added = 0;

        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $title = 'عضویت اجباری';
            $url = $line;

            if (str_contains($line, '|')) {
                [$title, $url] = array_map('trim', explode('|', $line, 2));
            }

            if ($url === '') continue;
            $items[] = [
                'title' => mb_substr($title !== '' ? $title : 'عضویت اجباری', 0, 80, 'UTF-8'),
                'url' => $url,
            ];
            $added++;
        }

        $this->setGlobalForceJoinChannels($items);
        if ($added > 0 && !$this->globalForceJoinEnabledRaw()) {
            $this->setGlobalForceJoinEnabled(true);
        }
        return $added;
    }

    private function globalForceJoinListText(): string
    {
        $items = $this->globalForceJoinChannels();
        $txt = "🔐 جوین اجباری ربات\n";
        $txt .= "وضعیت: " . Support::faBool($this->globalForceJoinEnabledRaw()) . "\n\n";

        if (!$items) {
            return $txt . "هنوز لینکی ثبت نشده است.\n\nبرای افزودن، از پنل اصلی دکمه «➕ افزودن جوین اجباری» را بزن.";
        }

        $txt .= "لیست لینک‌های اجباری:\n\n";
        $i = 1;
        foreach ($items as $item) {
            $title = trim((string)($item['title'] ?? 'لینک'));
            $url = trim((string)($item['url'] ?? ''));
            $txt .= $i . ") {$title}\n{$url}\n\n";
            $i++;
        }
        return trim($txt);
    }

    private function globalForceJoinStateKey(string $senderId): string
    {
        return 'global_force_join_verified:' . $senderId;
    }

    private function isGlobalForceJoinVerified(string $senderId): bool
    {
        if ($senderId === '') return false;
        return $this->db->getState($this->globalForceJoinStateKey($senderId), '') !== '';
    }

    private function markGlobalForceJoinVerified(string $senderId): void
    {
        if ($senderId !== '') {
            $this->db->setState($this->globalForceJoinStateKey($senderId), (string)time());
        }
    }

    private function sendGlobalForceJoinNotice(string $chatId, ?string $replyToMessageId = null): void
    {
        $items = $this->globalForceJoinChannels();
        $txt = "❤️ به ربات خوش آمدید.\n";
        $txt .= "برای استفاده از ربات و افزودن آن به گروه، ابتدا باید عضو موارد زیر شوید:\n\n";

        if (!$items) {
            $txt .= "فعلاً لینک جوین اجباری ثبت نشده است؛ لطفاً بعداً تلاش کنید.";
        } else {
            $i = 1;
            foreach ($items as $item) {
                $title = trim((string)($item['title'] ?? 'لینک'));
                $url = trim((string)($item['url'] ?? ''));
                $txt .= $i . ") {$title}\n{$url}\n\n";
                $i++;
            }
            $txt .= "بعد از عضویت، دکمه «✅ عضو شدم» را بزن یا بنویس: عضو شدم";
        }

        $chatKeypad = [
            'rows' => [
                ['buttons' => [$this->btn('kb:global_joined', '✅ عضو شدم')]],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];

        $this->client->sendMessage($chatId, $txt, $replyToMessageId, null, $chatKeypad);
    }

    private function forceJoinEnabled(array $group): bool
    {
        $locks = $group['locks'] ?? [];
        return !empty($locks['force_join_enabled']) && !empty($locks['force_join_channels']) && is_array($locks['force_join_channels']);
    }

    private function forceJoinChannels(array $group): array
    {
        $locks = $group['locks'] ?? [];
        $items = $locks['force_join_channels'] ?? [];
        return is_array($items) ? $items : [];
    }

    private function forceJoinListText(array $group): string
    {
        $items = $this->forceJoinChannels($group);
        if (!$items) return "📋 لیست جوین اجباری خالی است.";
        $txt = "📋 لیست جوین اجباری:\n\n";
        $i = 1;
        foreach ($items as $item) {
            $title = trim((string)($item['title'] ?? 'لینک'));
            $url = trim((string)($item['url'] ?? ''));
            $txt .= $i . ") {$title}\n{$url}\n\n";
            $i++;
        }
        return trim($txt);
    }

    private function addForceJoinChannels(string $chatId, array $group, string $text): int
    {
        $locks = $group['locks'] ?? [];
        $items = $locks['force_join_channels'] ?? [];
        if (!is_array($items)) $items = [];
        $added = 0;
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $title = 'عضویت اجباری';
            $url = $line;
            if (str_contains($line, '|')) {
                [$title, $url] = array_map('trim', explode('|', $line, 2));
            }
            if ($url === '') continue;
            $items[] = ['title' => mb_substr($title !== '' ? $title : 'عضویت اجباری', 0, 60, 'UTF-8'), 'url' => $url];
            $added++;
        }
        $locks['force_join_channels'] = array_values($items);
        $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
        return $added;
    }

    private function forceJoinStateKey(string $chatId, string $senderId): string
    {
        return 'force_join_verified:' . $chatId . ':' . $senderId;
    }

    private function isForceJoinVerified(string $chatId, string $senderId): bool
    {
        if ($senderId === '') return false;
        return $this->db->getState($this->forceJoinStateKey($chatId, $senderId), '') !== '';
    }

    private function markForceJoinVerified(string $chatId, string $senderId): void
    {
        if ($chatId !== '' && $senderId !== '') $this->db->setState($this->forceJoinStateKey($chatId, $senderId), (string)time());
    }

    private function pendingForceJoinKey(string $senderId): string
    {
        return 'pending_force_join_groups:' . $senderId;
    }

    private function addPendingForceJoinGroup(string $senderId, string $chatId): void
    {
        if ($senderId === '' || $chatId === '') return;
        $raw = $this->db->getState($this->pendingForceJoinKey($senderId), '[]') ?: '[]';
        $items = Support::jsonDecode($raw, []);
        if (!is_array($items)) $items = [];
        $items[] = $chatId;
        $items = array_values(array_unique(array_filter($items, fn($x) => is_string($x) && $x !== '')));
        $this->db->setState($this->pendingForceJoinKey($senderId), Support::jsonEncode($items));
    }

    private function markPendingForceJoinGroups(string $senderId): int
    {
        if ($senderId === '') return 0;
        $key = $this->pendingForceJoinKey($senderId);
        $raw = $this->db->getState($key, '[]') ?: '[]';
        $items = Support::jsonDecode($raw, []);
        if (!is_array($items)) $items = [];
        $count = 0;
        foreach (array_values(array_unique($items)) as $chatId) {
            if (is_string($chatId) && $chatId !== '') {
                $this->markForceJoinVerified($chatId, $senderId);
                $count++;
            }
        }
        $this->db->setState($key, '[]');
        return $count;
    }

    private function sendForceJoinNotice(string $chatId, array $group, string $senderId): void
    {
        $items = $this->forceJoinChannels($group);
        if (!$items || $senderId === '') return;

        // پیام جوین اجباری گروه داخل خود گروه ارسال نمی‌شود؛ فقط در پیوی کاربر فرستاده می‌شود.
        $this->addPendingForceJoinGroup($senderId, $chatId);
        $groupTitle = $this->groupLabel($group);
        $txt = "🔐 برای فعالیت در گروه «{$groupTitle}» ابتدا عضو موارد زیر شو:

";
        $i = 1;
        foreach ($items as $item) {
            $title = trim((string)($item['title'] ?? 'لینک'));
            $url = trim((string)($item['url'] ?? ''));
            $txt .= $i . ") {$title}
{$url}

";
            $i++;
        }
        $txt .= "بعد از عضویت، داخل پیوی ربات بزن:
" . $this->codeCmd('عضو شدم');

        $res = $this->client->sendMessage($senderId, $txt, null);
        Support::log($this->config, 'force_join_private_notice', [
            'group_chat_id' => $chatId,
            'target_user_id' => $senderId,
            'response' => $res,
        ]);
    }

    private function sendHelp(string $chatId, ?string $replyToMessageId = null, string $topic = ''): void
    {
        $isGroup = $this->isGroupChat($chatId);
        $topic = $this->normalizeHelpTopic($topic);

        if ($topic !== '') {
            $this->client->sendMessage($chatId, $this->topicHelpText($topic, $isGroup), $replyToMessageId);
            return;
        }

        // دکمه شیشه‌ای داخل گروه توسط API روبیکا پذیرفته نمی‌شود؛ داخل گروه فقط متن مرتب راهنما ارسال می‌شود.
        if ($isGroup) {
            $this->client->sendMessage($chatId, $this->groupHelpText(), $replyToMessageId);
            return;
        }

        $this->client->sendMessage($chatId, $this->privateHelpText(), $replyToMessageId, $this->helpKeypad());
    }

    private function helpTopicFromCommand(string $clean, string $key = ''): string
    {
        $clean = $this->normalizeCommandText($clean);
        $topic = preg_replace('/^(راهنمای|راهنما|help)\s*/iu', '', $clean) ?: '';
        $topic = trim($topic);
        if ($topic === '' && $key !== '') {
            $topic = preg_replace('/^(راهنمای|راهنما|help)/iu', '', $key) ?: '';
        }
        return $this->normalizeHelpTopic($topic);
    }

    private function normalizeHelpTopic(string $topic): string
    {
        $topic = $this->normalizeCommandText($topic);
        $raw = mb_strtolower(trim($topic), 'UTF-8');
        $canonical = [
            'locks' => 'locks',
            'locks_basic' => 'locks_basic',
            'locks_media' => 'locks_media',
            'locks_content' => 'locks_content',
            'locks_security' => 'locks_security',
            'mute' => 'mute',
            'filter' => 'filter',
            'admin' => 'admin',
            'panel' => 'panel',
            'talker' => 'talker',
            'stats' => 'stats',
            'warn' => 'warn',
            'full' => 'full',
        ];
        if (isset($canonical[$raw])) {
            return $canonical[$raw];
        }

        $key = $this->commandKey($topic);
        if ($key === '') return '';

        $map = [
            'lock' => 'locks', 'locks' => 'locks', 'قفل' => 'locks', 'قفلها' => 'locks', 'قفل‌ها' => 'locks', 'قفلهای' => 'locks', 'قفلهایگروه' => 'locks',
            'locksbasic' => 'locks_basic', 'basiclocks' => 'locks_basic', 'قفلپایه' => 'locks_basic', 'قفلهایپایه' => 'locks_basic', 'قفلهاپایه' => 'locks_basic', 'قفلهایاصلی' => 'locks_basic', 'قفلهایپایهای' => 'locks_basic', 'پایه' => 'locks_basic', 'پایهای' => 'locks_basic', 'اصلی' => 'locks_basic', 'base' => 'locks_basic', 'basic' => 'locks_basic',
            'locksmedia' => 'locks_media', 'medialocks' => 'locks_media', 'قفلرسانه' => 'locks_media', 'قفلهایرسانه' => 'locks_media', 'قفلرسانهها' => 'locks_media', 'قفلرسانه‌ها' => 'locks_media', 'قفلهایرسانهای' => 'locks_media', 'قفلرسانهای' => 'locks_media', 'رسانه' => 'locks_media', 'رسانهای' => 'locks_media', 'مدیا' => 'locks_media', 'media' => 'locks_media',
            'lockscontent' => 'locks_content', 'contentlocks' => 'locks_content', 'قفلمحتوا' => 'locks_content', 'قفلهایمحتوا' => 'locks_content', 'قفلپیام' => 'locks_content', 'قفلپیامها' => 'locks_content', 'قفلهایپیام' => 'locks_content', 'محتوا' => 'locks_content', 'پیام' => 'locks_content', 'content' => 'locks_content',
            'lockssecurity' => 'locks_security', 'securitylocks' => 'locks_security', 'قفلامنیت' => 'locks_security', 'قفلهایامنیت' => 'locks_security', 'قفلهایامنیتی' => 'locks_security', 'قفلامنیتی' => 'locks_security', 'قفلضدتخلف' => 'locks_security', 'قفلضداسپم' => 'locks_security', 'امنیت' => 'locks_security', 'امنیتی' => 'locks_security', 'security' => 'locks_security',
            'mute' => 'mute', 'mutes' => 'mute', 'silence' => 'mute', 'سکوت' => 'mute', 'سکوتها' => 'mute', 'سکوتیها' => 'mute', 'سکوت‌ها' => 'mute',
            'filter' => 'filter', 'filters' => 'filter', 'badwords' => 'filter', 'فیلتر' => 'filter', 'فیلترها' => 'filter', 'کلمات' => 'filter', 'کلماتممنوع' => 'filter', 'کلمهممنوع' => 'filter',
            'admin' => 'admin', 'admins' => 'admin', 'manager' => 'admin', 'ادمین' => 'admin', 'ادمینها' => 'admin', 'مدیر' => 'admin', 'مدیران' => 'admin',
            'panel' => 'panel', 'install' => 'panel', 'start' => 'panel', 'پنل' => 'panel', 'نصب' => 'panel', 'شروع' => 'panel', 'گروهها' => 'panel', 'گروههایم' => 'panel',
            'talker' => 'talker', 'speaker' => 'talker', 'game' => 'talker', 'games' => 'talker', 'سخنگو' => 'talker', 'بازی' => 'talker', 'بازیها' => 'talker', 'بازی‌ها' => 'talker',
            'stats' => 'stats', 'status' => 'stats', 'آمار' => 'stats', 'امار' => 'stats', 'وضعیت' => 'stats', 'گزارش' => 'stats',
            'warn' => 'warn', 'warning' => 'warn', 'punish' => 'warn', 'violations' => 'warn', 'اخطار' => 'warn', 'هشدار' => 'warn', 'جریمه' => 'warn', 'تخلفات' => 'warn',
            'all' => 'full', 'full' => 'full', 'کامل' => 'full', 'همه' => 'full',
        ];

        return $map[$key] ?? '';
    }

    private function helpKeypad(): array
    {
        // روبیکا دکمه Copy ندارد. دکمه Textbox نزدیک‌ترین حالت است:
        // با زدن دکمه، دستور آماده داخل ورودی متن قرار می‌گیرد.
        return [
            'rows' => [
                ['buttons' => [
                    $this->textboxBtn('help:cmd:help_locks', '🔐 راهنما قفل', 'راهنما قفل'),
                    $this->textboxBtn('help:cmd:help_mute', '🔇 راهنما سکوت', 'راهنما سکوت'),
                ]],
                ['buttons' => [
                    $this->textboxBtn('help:cmd:help_admin', '👥 راهنما ادمین', 'راهنما ادمین'),
                    $this->textboxBtn('help:cmd:help_talker', '🧠 راهنما سخنگو', 'راهنما سخنگو'),
                ]],
                ['buttons' => [
                    $this->textboxBtn('help:cmd:help_stats', '📊 راهنما آمار', 'راهنما آمار'),
                    $this->textboxBtn('help:cmd:groups', '📋 گروه‌های من', '/groups'),
                ]],
            ],
        ];
    }

    private function helpCommandFromButton(string $buttonId): string
    {
        return match ($buttonId) {
            'help:cmd:help_locks' => 'راهنما قفل',
            'help:cmd:help_mute' => 'راهنما سکوت',
            'help:cmd:help_admin' => 'راهنما ادمین',
            'help:cmd:help_talker' => 'راهنما سخنگو',
            'help:cmd:help_stats' => 'راهنما آمار',
            'help:cmd:lock_link' => 'قفل لینک',
            'help:cmd:unlock_link' => 'باز لینک',
            'help:cmd:filter' => 'فیلتر کلمه',
            'help:cmd:unfilter' => 'حذف فیلتر کلمه',
            'help:cmd:promote' => 'ترفیع',
            'help:cmd:demote' => 'تنزیل',
            'help:cmd:id' => 'آیدی',
            'help:cmd:groups' => '/groups',
            default => '',
        };
    }

    private function copyCommandText(string $cmd): string
    {
        // بک‌تیک در روبیکا کپی یک‌ضربه‌ای نمی‌سازد؛ پیام جداگانه و کوتاه برای کپی راحت‌تر است.
        return $cmd;
    }


    private function textboxBtn(string $id, string $text, string $defaultValue): array
    {
        return [
            'id' => $id,
            'type' => 'Textbox',
            'button_text' => $text,
            'button_textbox' => [
                'type_line' => 'SingleLine',
                'type_keypad' => 'String',
                'place_holder' => $defaultValue,
                'title' => $text,
                'default_value' => $defaultValue,
            ],
        ];
    }

    private function lockStatusText(string $lock, bool $enable): string
    {
        $name = $this->lockName($lock);
        if ($enable) {
            return "• **قفل {$name} فعال شد.** ↺";
        }
        return "• **{$name} باز شد.** ↻";
    }

    private function codeCmd(string $cmd): string
    {
        // دستورها با مارکر `...` ساخته می‌شوند و قبل از ارسال به روبیکا به metadata کپی‌پذیر تبدیل می‌شوند.
        // داخل متن راهنما دیگر بک‌تیک تکی و بدون جفت نمی‌گذاریم، چون parser را به‌هم می‌ریزد.
        return '`' . $cmd . '`';
    }


    private function lockHelpLines(): string
    {
        $groups = [
            ['link', 'text', 'rubika_id', 'forward', 'reply', 'edit'],
            ['photo', 'video', 'voice', 'file', 'gif', 'music', 'large_file'],
            ['sticker', 'poll', 'emoji', 'caption', 'metadata', 'content'],
            ['phone', 'number', 'hashtag', 'english_text', 'swear', 'bad_words', 'crash_code', 'bio_check', 'flood'],
        ];

        $out = '';
        foreach ($groups as $locks) {
            foreach ($locks as $lock) {
                $name = $this->lockName($lock);
                $out .= '• ' . $name . ': ' . $this->codeCmd('قفل ' . $name) . ' / ' . $this->codeCmd('باز ' . $name) . "\n";
            }
            $out .= "\n";
        }

        return trim($out);
    }

    private function privateHelpText(): string
    {
        return "**╭─ 🤖 راهنمای ربات**\n" .
            "╰─ برای هر بخش، دستور همان بخش را ارسال کن**\n" .
            "📌 روی خود دستورها بزن تا کپی شوند.\n\n" .
            "**📌 راهنماهای بخش‌بندی‌شده**\n" .
            "• قفل‌ها: " . $this->codeCmd('راهنما قفل') . "\n" .
            "• سکوتی‌ها: " . $this->codeCmd('راهنما سکوت') . "\n" .
            "• فیلتر و کلمات ممنوع: " . $this->codeCmd('راهنما فیلتر') . "\n" .
            "• مدیران: " . $this->codeCmd('راهنما ادمین') . "\n" .
            "• آمار و وضعیت: " . $this->codeCmd('راهنما آمار') . "\n" .
            "• سخنگو و بازی‌ها: " . $this->codeCmd('راهنما سخنگو') . "\n" .
            "• نصب و پنل: " . $this->codeCmd('راهنما پنل') . "\n" .
            "• اخطار و جریمه: " . $this->codeCmd('راهنما اخطار') . "\n\n" .
            "**🚀 شروع سریع**\n" .
            "• پنل گروه‌ها: " . $this->codeCmd('/start') . " ← 📋 گروه‌های من\n" .
            "• راهنمای کامل: " . $this->codeCmd('راهنما کامل');
    }

    private function groupHelpText(): string
    {
        return "**╭─ 🤖 راهنمای گروه**\n" .
            "╰─ برای دیدن راهنمای هر بخش، دستور همان بخش را بفرست**\n" .
            "📌 روی خود دستورها بزن تا کپی شوند.\n\n" .
            "**🔐 قفل‌ها**\n" .
            "جهت راهنمای قفل‌ها ارسال کن:
" . $this->codeCmd('راهنما قفل') . "\n\n" .
            "**🔇 سکوت و مدیریت پیام**\n" .
            "جهت راهنمای سکوتی‌ها ارسال کن:
" . $this->codeCmd('راهنما سکوت') . "\n\n" .
            "**🚫 فیلتر و کلمات ممنوع**\n" .
            "جهت راهنمای فیلتر ارسال کن:
" . $this->codeCmd('راهنما فیلتر') . "\n\n" .
            "**👥 مدیران**\n" .
            "جهت راهنمای ادمین‌ها ارسال کن:
" . $this->codeCmd('راهنما ادمین') . "\n\n" .
            "**📊 آمار و وضعیت**\n" .
            "جهت راهنمای آمار ارسال کن:
" . $this->codeCmd('راهنما آمار') . "\n\n" .
            "**🧠 سخنگو و بازی‌ها**\n" .
            "جهت راهنمای سخنگو ارسال کن:
" . $this->codeCmd('راهنما سخنگو') . "\n\n" .
            "**⚙️ نصب و پنل**\n" .
            "جهت راهنمای نصب و پنل ارسال کن:
" . $this->codeCmd('راهنما پنل') . "\n\n" .
            "**🚨 اخطار و جریمه**\n" .
            "جهت راهنمای اخطار ارسال کن:
" . $this->codeCmd('راهنما اخطار');
    }

    private function topicHelpText(string $topic, bool $isGroup): string
    {
        return match ($topic) {
            'locks' => $this->locksHelpText(),
            'locks_basic' => $this->lockCategoryHelpText('🔐 قفل‌های پایه', ['link', 'text', 'rubika_id', 'forward', 'reply', 'edit']),
            'locks_media' => $this->lockCategoryHelpText('🖼 قفل‌های رسانه', ['photo', 'video', 'voice', 'file', 'gif', 'music', 'large_file']),
            'locks_content' => $this->lockCategoryHelpText('🧩 قفل‌های محتوا', ['sticker', 'poll', 'emoji', 'caption', 'metadata', 'content']),
            'locks_security' => $this->lockCategoryHelpText('🚨 قفل‌های امنیت', ['phone', 'number', 'hashtag', 'english_text', 'swear', 'bad_words', 'crash_code', 'bio_check', 'flood']),
            'mute' => $this->muteHelpText(),
            'filter' => $this->filterHelpText(),
            'admin' => $this->adminHelpText(),
            'panel' => $this->panelHelpText($isGroup),
            'talker' => $this->talkerHelpText(),
            'stats' => $this->statsHelpText(),
            'warn' => $this->warnHelpText(),
            'full' => $this->fullHelpText($isGroup),
            default => $isGroup ? $this->groupHelpText() : $this->privateHelpText(),
        };
    }

    private function locksHelpText(): string
    {
        // دستورها در راهنما با فاصله نمایش داده می‌شوند تا از نظر املایی درست و خوانا باشند.
        // خود ربات همچنان حالت‌های فشرده مثل «قفللینک» را نیز برای سازگاری نسخه‌های قبلی می‌فهمد.
        return "╭─ 🔐 راهنمای قفل‌ها
" .
            "╰─ روی دستور بزن تا کپی شود

" .
            "نمونه‌های اصلی:

" .
            $this->lockCommandBlock('لینک') . "

" .
            $this->lockCommandBlock('فوروارد') . "

" .
            $this->lockCommandBlock('عکس') . "

" .
            $this->lockCommandBlock('فیلم') . "

" .
            $this->lockCommandBlock('بیوچک') . "

" .
            $this->lockCommandBlock('همه') . "

" .
            "راهنمای دسته‌ای قفل‌ها:

" .
            "قفل‌های پایه:
" . $this->codeCmd('راهنمای قفل پایه') . "

" .
            "قفل‌های رسانه:
" . $this->codeCmd('راهنمای قفل رسانه') . "

" .
            "قفل‌های محتوا:
" . $this->codeCmd('راهنمای قفل محتوا') . "

" .
            "قفل‌های امنیت:
" . $this->codeCmd('راهنمای قفل امنیت');
    }

    private function compactCopyCommand(string $prefix, string $name): string
    {
        return trim($prefix . ' ' . $name);
    }

    private function lockCommandBlock(string $name): string
    {
        if ($name === 'همه') {
            return "• {$name}
" .
                $this->codeCmd('قفل همه') . "
" .
                $this->codeCmd('باز همه');
        }

        return "• {$name}
" .
            $this->codeCmd($this->compactCopyCommand('قفل', $name)) . "
" .
            $this->codeCmd($this->compactCopyCommand('باز', $name));
    }

    private function lockCategoryHelpText(string $title, array $lockKeys): string
    {
        $lines = [
            "╭─ {$title}",
            "╰─ دستورها با فاصله نوشته شده‌اند و با لمس، کپی می‌شوند",
            '',
        ];

        foreach ($lockKeys as $lockKey) {
            $name = $this->lockName((string)$lockKey);
            $lines[] = "• {$name}";
            $lines[] = $this->codeCmd($this->compactCopyCommand('قفل', $name));
            $lines[] = $this->codeCmd($this->compactCopyCommand('باز', $name));
            $lines[] = '';
        }

        $lines[] = "برگشت به فهرست قفل‌ها:";
        $lines[] = $this->codeCmd('راهنما قفل');

        return rtrim(implode("
", $lines));
    }

    private function muteHelpText(): string
    {
        return "**╭─ 🔇 راهنمای سکوت**\n" .
            "╰─ برای سکوت کردن، روی پیام کاربر ریپلای کن**\n\n" .
            "• سکوت نامحدود: " . $this->codeCmd('سکوت') . "\n" .
            "• سکوت زمان‌دار: " . $this->codeCmd('سکوت 10') . "\n" .
            "• حذف سکوت: " . $this->codeCmd('حذف سکوت') . "\n" .
            "• فهرست سکوت‌شده‌ها: " . $this->codeCmd('لیست سکوت') . "\n" .
            "• پاک‌سازی کامل سکوت‌شده‌ها: " . $this->codeCmd('پاکسازی سکوت') . "\n\n" .
            "**نکته:** عدد سکوت بر اساس دقیقه است.";
    }

    private function filterHelpText(): string
    {
        return "**╭─ 🚫 راهنمای فیلتر**\n" .
            "╰─ مدیریت کلمات ممنوع در گروه**\n\n" .
            "• افزودن فیلتر: " . $this->codeCmd('فیلتر کلمه') . "\n" .
            "• حذف فیلتر: " . $this->codeCmd('حذف فیلتر کلمه') . "\n" .
            "• فهرست فیلترها: " . $this->codeCmd('لیست فیلتر') . "\n" .
            "• قفل کلمات غیرمجاز: " . $this->codeCmd('قفل کلمات غیرمجاز') . "\n" .
            "• باز کردن قفل: " . $this->codeCmd('باز کلمات غیرمجاز');
    }

    private function adminHelpText(): string
    {
        return "**╭─ 👥 راهنمای مدیران**\n" .
            "╰─ ترفیع و تنزیل مدیر داخلی ربات**\n\n" .
            "• ترفیع با ریپلای: " . $this->codeCmd('ترفیع') . "\n" .
            "• ترفیع با شناسه: " . $this->codeCmd('ترفیع USER_ID') . "\n" .
            "• تنظیم مدیر: " . $this->codeCmd('تنظیم ادمین') . "\n" .
            "• تنزیل با ریپلای: " . $this->codeCmd('تنزیل') . "\n" .
            "• حذف مدیر با شناسه: " . $this->codeCmd('حذف ادمین USER_ID') . "\n" .
            "• فهرست مدیران: " . $this->codeCmd('لیست ادمین') . "\n" .
            "• آیدی کاربر: ریپلای + " . $this->codeCmd('آیدی');
    }

    private function panelHelpText(bool $isGroup): string
    {
        return "**╭─ ⚙️ راهنمای نصب و پنل**\n" .
            "╰─ فعال‌سازی و مدیریت پنل گروه**\n\n" .
            "• نصب در گروه: " . $this->codeCmd('نصب') . "\n" .
            "• وضعیت ربات: " . $this->codeCmd('وضعیت') . "\n" .
            "• آمار گروه: " . $this->codeCmd('آمار') . "\n" .
            "• پنل کامل: پیام خصوصی ربات ← " . $this->codeCmd('/start') . " ← 📋 گروه‌های من";
    }

    private function talkerHelpText(): string
    {
        return "**╭─ 🧠 راهنمای سخنگو و بازی‌ها**\n" .
            "╰─ پاسخ خودکار و سرگرمی گروه**\n\n" .
            "• روشن کردن سخنگو: " . $this->codeCmd('سخنگو روشن') . "\n" .
            "• خاموش کردن سخنگو: " . $this->codeCmd('سخنگو خاموش') . "\n" .
            "• افزودن پاسخ: " . $this->codeCmd('افزودن پاسخ سلام | سلام عزیزم') . " — فقط مالک اصلی ربات\n" .
            "• حذف پاسخ: " . $this->codeCmd('حذف پاسخ سلام') . " — فقط مالک اصلی ربات\n" .
            "• فهرست پاسخ‌ها: " . $this->codeCmd('لیست پاسخ‌ها') . " — فقط مالک اصلی ربات\n" .
            "• بازی‌ها: " . $this->codeCmd('شانس') . "، " . $this->codeCmd('تاس') . "، " . $this->codeCmd('پرتاب سکه') . "\n" .
            "• فونت متن: " . $this->codeCmd('فونت amir');
    }

private function statsHelpText(): string
    {
        return "**╭─ 📊 راهنمای آمار و وضعیت**\n" .
            "╰─ گزارش مدیریتی گروه**\n\n" .
            "• وضعیت کامل قفل‌ها و تنظیمات: " . $this->codeCmd('وضعیت') . "\n" .
            "• آمار کلی گروه: " . $this->codeCmd('آمار') . "\n" .
            "• آمار گروه: " . $this->codeCmd('آمار گروه') . "\n" .
            "• آمار خودت: " . $this->codeCmd('آمارم') . "\n" .
            "• گزارش تخلفات: " . $this->codeCmd('تخلفات') . "\n\n" .
            "در آمار گروه، تعداد پیام‌ها، کاربران فعال، اخطارها، کاربران سکوت‌شده و سه کاربر برتر نمایش داده می‌شود.";
    }

    private function warnHelpText(): string
    {
        return "**╭─ 🚨 راهنمای اخطار و جریمه**\n" .
            "╰─ مدیریت رفتار بعد از تخلف**\n\n" .
            "• اخطار دستی: ریپلای + " . $this->codeCmd('اخطار') . "\n" .
            "• بخشش اخطار: ریپلای + " . $this->codeCmd('بخشش') . "\n" .
            "• حد اخطار: " . $this->codeCmd('حد اخطار 3') . "\n" .
            "• فقط حذف بعد از سقف: " . $this->codeCmd('جریمه هشدار فقط حذف') . "\n" .
            "• جریمه سکوت بعد از سقف: " . $this->codeCmd('جریمه هشدار سکوت') . "\n" .
            "• جریمه اخراج بعد از سقف: " . $this->codeCmd('جریمه هشدار اخراج') . "\n" .
            "• جریمه بن بعد از سقف: " . $this->codeCmd('جریمه هشدار بن') . "\n" .
            "• خارج کردن از بن/سیک: ریپلای + " . $this->codeCmd('آن بن') . " یا " . $this->codeCmd('آن سیک') . "\n" .
            "• پاک‌سازی لیست بن: " . $this->codeCmd('پاکسازی لیست بن') . "\n" .
            "• گزارش تخلفات: " . $this->codeCmd('تخلفات');
    }

    private function fullHelpText(bool $isGroup): string
    {
        return $this->groupHelpText() . "\n\n" .
            $this->locksHelpText() . "\n\n" .
            $this->muteHelpText() . "\n\n" .
            $this->filterHelpText() . "\n\n" .
            $this->adminHelpText() . "\n\n" .
            $this->statsHelpText() . "\n\n" .
            $this->talkerHelpText() . "\n\n" .
            $this->warnHelpText();
    }

    private function helpText(): string
    {
        return $this->groupHelpText();
    }


    private function groupLockStatusText(string $chatId): string
    {
        $g = $this->db->getGroup($chatId);
        $locks = $g['locks'] ?? [];
        $defs = $this->lockDefinitions();
        $title = $this->groupLabel($g);
        $stats = $this->db->stats($chatId);
        $msgStats = $this->db->messageStats($chatId);
        $top = method_exists($this->db, 'topUsersToday') ? $this->db->topUsersToday($chatId, 3) : $this->db->topUsers($chatId, 3);

        $locked = [];
        $open = [];
        foreach ($defs as $key => $label) {
            if (!empty($locks[$key])) {
                $locked[] = $label;
            } else {
                $open[] = $label;
            }
        }

        $topLines = [];
        $medals = ['🥇','🥈','🥉'];
        foreach ($top as $i => $row) {
            $uid = (string)($row['user_id'] ?? '');
            $cnt = (int)($row['total'] ?? 0);
            $rank = $this->userRankInfo($chatId, $uid);
            $name = trim((string)($rank['nickname'] ?? '')) ?: ('کاربر ' . $this->faNum($i + 1));
            $topLines[] = ($medals[$i] ?? '•') . ' ' . $name . ' — ' . $this->faNum($cnt) . ' پیام';
        }
        if (!$topLines) {
            $topLines[] = 'هنوز آماری ثبت نشده است.';
        }

        $punishName = ['delete'=>'فقط حذف','silence'=>'سکوت نرم','kick'=>'سیک/اخراج','ban'=>'بن','none'=>'بدون جریمه'][$this->punishAction($g)] ?? 'نامشخص';
        $flood = $g['flood'] ?? ['max_messages' => 5, 'seconds' => 8];

        $txt = "**╭─ 📊 وضعیت پیشرفته گروه**\n";
        $txt .= "├ گروه: «{$title}»\n";
        $txt .= "├ شناسه: {$chatId}\n";
        $txt .= "╰────────────\n\n";

        $txt .= "**⚙️ حالت‌ها**\n";
        $txt .= "• ربات: " . (!empty($g['enabled']) ? '✅ روشن' : '⛔ خاموش') . "\n";
        $txt .= "• اخطار: " . ($this->warningEnabled($g) ? '✅ روشن' : '⛔ خاموش') . ' | حد: ' . $this->faNum((int)($g['max_warnings'] ?? 3)) . "\n";
        $txt .= "• جریمه بعد از سقف: {$punishName}\n";
        $txt .= "• سخنگو: " . ($this->talkerEnabled($g) ? '✅ روشن' : '⛔ خاموش') . "\n";
        $txt .= "• ضداسپم: " . (!empty($locks['flood']) ? '✅ روشن' : '⛔ خاموش') . ' | ' . $this->faNum((int)($flood['max_messages'] ?? 5)) . ' پیام در ' . $this->faNum((int)($flood['seconds'] ?? 8)) . " ثانیه\n\n";

        $txt .= "**📈 آمار کلی گروه**\n";
        $txt .= "• پیام‌های امروز: " . $this->faNum((int)($msgStats['today_messages'] ?? 0)) . "\n";
        $txt .= "• تخلف‌ها: " . $this->faNum((int)($stats['violations'] ?? 0)) . "\n";
        $txt .= "• کاربران اخطاری: " . $this->faNum((int)($stats['warned_users'] ?? 0)) . "\n";
        $txt .= "• کاربران سکوت: " . $this->faNum((int)($stats['soft_bans'] ?? 0)) . "\n\n";

        $txt .= "**🏆 سه کاربر برتر امروز**\n" . implode("\n", $topLines) . "\n\n";

        $txt .= "**🔐 قفل‌های فعال**\n";
        if ($locked) {
            foreach ($locked as $name) $txt .= "• {$name}\n";
        } else {
            $txt .= "• موردی فعال نیست\n";
        }

        $txt .= "\n**🔓 قفل‌های باز**\n";
        $shown = 0;
        foreach ($open as $name) {
            $txt .= "• {$name}\n";
            $shown++;
            if ($shown >= 12 && count($open) > 12) {
                $txt .= "• ...\n";
                break;
            }
        }

        $txt .= "\nمدیریت: پیوی ربات ← /start ← 📋 گروه‌های من";
        return $txt;
    }


    private function groupLockStatusPayload(string $chatId): array
    {
        $txt = $this->groupLockStatusText($chatId);
        $top = $this->db->topUsers($chatId, 3);
        $mentions = [];
        foreach ($top as $i => $row) {
            $uid = (string)($row['user_id'] ?? '');
            if ($uid === '') continue;
            $rank = $this->userRankInfo($chatId, $uid);
            $label = trim((string)($rank['nickname'] ?? '')) ?: ('کاربر ' . $this->faNum($i + 1));
            $mentions[] = ['label' => $label, 'user_id' => $uid];
        }
        return [$txt, MetadataBuilder::merge(
            MetadataBuilder::mentions($txt, $mentions),
            MetadataBuilder::bolds($txt, ['وضعیت پیشرفته گروه', '⚙️ حالت‌ها', '📈 آمار کلی گروه', '🏆 سه کاربر برتر امروز', '🔐 قفل‌های فعال', '🔓 قفل‌های باز'])
        )];
    }

    private function statusText(string $chatId): string
    {
        $g = $this->db->getGroup($chatId);
        $stats = $this->db->stats($chatId);
        $locks = $g['locks'];
        $punishName = ['delete'=>'فقط حذف','silence'=>'سکوت نرم','kick'=>'سیک/اخراج','ban'=>'بن','none'=>'بدون جریمه'][$this->punishAction($g)] ?? 'نامشخص';
        $lines = ['📊 وضعیت ربات','فعال: ' . Support::faBool($g['enabled']),'سیستم اخطار: ' . Support::faBool($this->warningEnabled($g)),'حداکثر اخطار: ' . $g['max_warnings'],'جریمه بعد از سقف: ' . $punishName,'تخلف‌ها: ' . $stats['violations'],'کاربران اخطاری: ' . $stats['warned_users'],'سکوت نرم‌ها: ' . $stats['soft_bans'],'','قفل‌ها:'];
        foreach ($this->lockDefinitions() as $k => $fa) {
            $lines[] = $fa . ': ' . (!empty($locks[$k]) ? '🔐' : '🔓');
        }
        return implode("\n", $lines);
    }

    private function groupCreatorId(string $chatId): string
    {
        if ($chatId === '') return '';
        return trim((string)$this->db->getState('group_creator_' . $chatId, ''));
    }

    private function setGroupCreatorId(string $chatId, string $userId): void
    {
        if ($chatId === '' || $userId === '') return;
        $this->db->setState('group_creator_' . $chatId, $userId);
    }

    private function userRanks(string $chatId): array
    {
        $data = Support::jsonDecode($this->db->getState('user_ranks_' . $chatId, '{}') ?: '{}', []);
        return is_array($data) ? $data : [];
    }

    private function saveUserRanks(string $chatId, array $data): void
    {
        $this->db->setState('user_ranks_' . $chatId, Support::jsonEncode($data));
    }

    private function setUserNickname(string $chatId, string $userId, string $nickname): void
    {
        if ($chatId === '' || $userId === '') return;
        $data = $this->userRanks($chatId);
        $data[$userId] = array_merge($data[$userId] ?? [], ['nickname' => mb_substr(trim($nickname), 0, 60, 'UTF-8')]);
        $this->saveUserRanks($chatId, $data);
    }

    private function setUserOrigin(string $chatId, string $userId, string $origin): void
    {
        if ($chatId === '' || $userId === '') return;
        $data = $this->userRanks($chatId);
        $data[$userId] = array_merge($data[$userId] ?? [], ['origin' => mb_substr(trim($origin), 0, 60, 'UTF-8')]);
        $this->saveUserRanks($chatId, $data);
    }

    private function userRankInfo(string $chatId, string $userId): array
    {
        $data = $this->userRanks($chatId);
        return is_array($data[$userId] ?? null) ? $data[$userId] : [];
    }

    private function targetFromReplyOrCommand(array $n, string $clean): string
    {
        $target = $this->extractUserIdFromCommand($clean);
        if ($target === '') {
            $target = $this->senderFromReply($n);
        }
        return trim($target);
    }

    private function faNum(int $n): string
    {
        return strtr((string)$n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    private function responseMessageId(array $res): ?string
    {
        foreach ([['data','message_id'], ['data','message','message_id'], ['message_id']] as $path) {
            $cur = $res;
            $ok = true;
            foreach ($path as $k) {
                if (!is_array($cur) || !array_key_exists($k, $cur)) { $ok = false; break; }
                $cur = $cur[$k];
            }
            if ($ok && trim((string)$cur) !== '') return (string)$cur;
        }
        return null;
    }

    private function recentMessageIds(string $chatId, int $limit): array
    {
        return $this->db->recentMessageIds($chatId, $limit);
    }

    private function deleteRecentMessagesWithProgress(string $chatId, int $count, ?string $replyToMessageId = null): int
    {
        $count = max(1, min(200, $count));
        $status = $this->client->sendMessage($chatId, "⏳ حذف {$this->faNum($count)} پیام شروع شد...", $replyToMessageId);
        $statusId = $this->responseMessageId($status);
        $ids = $this->recentMessageIds($chatId, $count);
        $deleted = 0;
        $i = 0;
        foreach ($ids as $mid) {
            $i++;
            $res = $this->client->deleteMessage($chatId, $mid);
            if (($res['status'] ?? '') === 'OK' || ($res['ok'] ?? false)) $deleted++;
            if ($statusId && ($i % 20 === 0 || $i === count($ids))) {
                $this->client->editMessageText($chatId, $statusId, "⏳ در حال حذف... {$this->faNum($i)}/{$this->faNum(count($ids))}");
            }
            usleep(5000);
        }
        if ($statusId) {
            $this->client->editMessageText($chatId, $statusId, "✅ {$this->faNum($deleted)} پیام حذف شد.");
        } else {
            $this->client->sendMessage($chatId, "✅ {$this->faNum($deleted)} پیام حذف شد.", $replyToMessageId);
        }
        return $deleted;
    }

    private function fontVariantsText(string $word): string
    {
        $word = trim($word);
        if ($word === '') return 'کلمه‌ای برای فونت وارد نشده است.';
        $lower = mb_strtolower($word, 'UTF-8');
        $maps = [
            'کاپیتال' => ['abcdefghijklmnopqrstuvwxyz', 'ᴀʙᴄᴅᴇғɢʜɪᴊᴋʟᴍɴᴏᴘǫʀsᴛᴜᴠᴡxʏᴢ'],
            'دایره' => ['abcdefghijklmnopqrstuvwxyz', 'ⓐⓑⓒⓓⓔⓕⓖⓗⓘⓙⓚⓛⓜⓝⓞⓟⓠⓡⓢⓣⓤⓥⓦⓧⓨⓩ'],
            'بولت' => ['abcdefghijklmnopqrstuvwxyz', '𝗮𝗯𝗰𝗱𝗲𝗳𝗴𝗵𝗶𝗷𝗸𝗹𝗺𝗻𝗼𝗽𝗾𝗿𝘀𝘁𝘂𝘃𝘄𝘅𝘆𝘇'],
            'ماتیس' => ['abcdefghijklmnopqrstuvwxyz', '𝐚𝐛𝐜𝐝𝐞𝐟𝐠𝐡𝐢𝐣𝐤𝐥𝐦𝐧𝐨𝐩𝐪𝐫𝐬𝐭𝐮𝐯𝐰𝐱𝐲𝐳'],
        ];
        $txt = "✨ فونت‌های «{$word}»\n━━━━━━━━━━━━\n";
        foreach ($maps as $name => [$from, $to]) {
            $txt .= "• {$name}: " . strtr($lower, array_combine(mb_str_split($from), mb_str_split($to))) . "\n";
        }
        return trim($txt);
    }

    private function sendMentionNotice(string $chatId, string $label, string $userId, string $after, ?string $replyToMessageId = null): void
    {
        $label = $label !== '' ? $label : 'کاربر';
        $text = $label . $after;
        $metadata = MetadataBuilder::mention($text, $label, $userId);
        $this->client->sendMessage($chatId, $text, $replyToMessageId, null, null, $metadata ?: null);
    }

}
