<?php
namespace RubikaGM;

final class BotApp
{
    public function __construct(
        private array $config,
        private Database $db,
        private RubikaClient $client
    ) {}

    public function processUpdate(array $n): void
    {
        $chatId = (string)$n['chat_id'];
        $senderId = (string)$n['sender_id'];
        $messageId = (string)$n['message_id'];
        $text = $this->normalizeCommandText((string)$n['text']);
        $buttonId = (string)($n['button_id'] ?? '');
        $isGroupChat = $this->isGroupChat($chatId);

        if ((string)($n['type'] ?? '') === 'StoppedBot') {
            $this->db->unregisterPrivateChat($chatId, $senderId);
            return;
        }

        // در پیوی روبیکا گاهی chat_id با user_id فرق دارد. اگر sender_id/user_id واقعی موجود باشد، نباید با chat_id جایگزین شود.
        // فقط وقتی sender_id خالی است، از raw.user_id یا در نهایت chat_id کمک می‌گیریم.
        // نکته مهم: پیوی نباید داخل جدول groups رکورد بسازد؛ فقط در private_chats ثبت می‌شود.
        if ($chatId !== '' && !$isGroupChat) {
            $senderId = $this->resolvePrivateSenderId($n);
            $n['sender_id'] = $senderId;
            $this->db->registerPrivateChat($chatId, $senderId);
        }

        // برای دستورات ریپلای مثل ترفیع/تنزیل، فرستنده پیام‌ها ذخیره می‌شود.
        $this->rememberMessageSender($n);

        if ($buttonId !== '') {
            $this->handleButton($n, $buttonId);
            return;
        }

        if ($this->handlePendingMessage($n)) {
            return;
        }

        if ($this->handlePrivateKeyboardText($n)) {
            return;
        }

        $groupTitle = $this->groupTitleFromUpdate($n);
        if ($isGroupChat) {
            $group = $this->db->getGroup($chatId, $groupTitle !== '' ? $groupTitle : null);
            if ($groupTitle !== '' && (string)($group['title'] ?? '') !== $groupTitle) {
                $this->db->updateGroup($chatId, ['title' => $groupTitle]);
                $group = $this->db->getGroup($chatId);
            }
        } else {
            // زمینه مجازی برای پردازش دستورهای پیوی؛ بدون درج در جدول groups.
            $group = $this->db->virtualGroup($chatId, 'Private');
        }

        if ($isGroupChat && $messageId !== '' && $senderId !== '') {
            GroupStatsService::record($this->db, $chatId, $senderId);
        }

        if ($isGroupChat && $this->handleWelcomeEvent($n, $group)) {
            return;
        }

        if ($isGroupChat && $this->handleFarewellEvent($n, $group)) {
            return;
        }

        if ($this->isCommand($text)) {
            $handled = $this->handleCommand($n, $group, $text);
            if ($handled) {
                return;
            }
        }

        if (!$isGroupChat) {
            $this->handleTalker($n, $group);
            return;
        }

        if (!$group['enabled']) {
            return;
        }

        $isPrivilegedSender = $this->isPrivileged($senderId, $group);
        $isTrustedSender = $this->isTrusted($senderId, $group);
        $moderation = (array)($this->config['moderation'] ?? []);
        // مالک و ادمین‌های داخلی گروه همیشه از حذف، اخطار، ضداسپم و تمام قفل‌ها معاف‌اند.
        // این معافیت قطعی است و حتی تنظیم قدیمی apply_locks_to_privileged=true نیز آن را تغییر نمی‌دهد.
        // کاربران مورداعتماد طبق تنظیم apply_locks_to_trusted قابل کنترل‌اند.
        if ($this->shouldBypassLocks($isPrivilegedSender, $isTrustedSender, $moderation)) {
            if ($this->handleTalker($n, $group)) {
                return;
            }
            return;
        }

        // سکوت نرم فقط برای کاربران عادی اجرا می‌شود؛ مدیر حتی با رکورد قدیمی soft-ban حذف نمی‌شود.
        if ($senderId !== '' && $this->db->isSoftBanned($chatId, $senderId)) {
            $this->client->deleteMessage($chatId, $messageId);
            $this->db->addViolation($chatId, $senderId, $messageId, 'کاربر در سکوت نرم است', $text, 'delete_soft_mute');
            return;
        }

        // جوین اجباری فقط برای استفاده از ربات در پیوی/نصب/مدیریت است.
        // داخل گروه روی پیام کاربران عادی اعمال نمی‌شود تا گروه درگیر پیام جوین نشود.

        $n = $this->resolveAmbiguousMediaForLocks($n, $group);
        $reasons = Detector::reasons($n, $group, $this->db);

        if ($this->globalBadWordsEnabled() && $this->hasGlobalBadWord($text)) {
            $reasons[] = 'فیلتر کلمات سراسری';
        }

        if (!$reasons) {
            if ($this->handleTalker($n, $group)) {
                return;
            }
            return;
        }

        $reasonText = implode('، ', $reasons);
        $delete = $this->client->deleteMessage($chatId, $messageId);

        $this->debugLog('delete_message_result', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'sender_id' => $senderId,
            'reason' => $reasonText,
            'response' => $delete,
        ]);

        $deleteOk = (($delete['status'] ?? '') === 'OK' || ($delete['ok'] ?? false));
        if (!$deleteOk) {
            $this->db->addViolation($chatId, $senderId ?: null, $messageId, $reasonText, $text, 'delete_failed');
            $this->client->sendMessage($chatId, "⚠️ قفل فعال است، اما حذف انجام نشد.
ربات را ادمین کن و مجوز حذف پیام بده.", null);
            return;
        }

        $locks = $group['locks'] ?? [];
        $warningEnabled = $this->warningEnabled($group);
        $lockScope = $this->firstLockForReasons($reasons);
        $customWarningScope = $this->warningScopeForReasons($reasons, $group);
        $customPunishScope = ($lockScope && $this->lockHasCustomPunishAction($group, $lockScope)) ? $lockScope : null;
        $warningScope = $customWarningScope ?: $customPunishScope;
        $punishAction = $warningScope ? $this->lockPunishAction($group, $warningScope) : $this->punishAction($group);
        $max = max(1, (int)($group['max_warnings'] ?? 2));
        $warningScopeName = $warningScope ? $this->lockName($warningScope) : '';
        if ($warningScope) {
            $specificLimit = $this->lockWarningLimit($group, $warningScope);
            if ($specificLimit > 0) {
                $max = max(1, $specificLimit);
            }
        }

        if (!$warningEnabled || $senderId === '') {
            $this->db->addViolation($chatId, $senderId ?: null, $messageId, $reasonText, $text, 'delete_no_warning');
            return;
        }

        if ($warningScope) {
            $warnings = $this->db->addScopedWarning($chatId, $senderId, $warningScope);
            $action = 'delete_warn_' . $warningScope;
        } else {
            $warnings = $this->db->addWarning($chatId, $senderId);
            $action = 'delete_warn';
        }
        $suffix = '';

        $limitReached = false;
        if ($warnings >= $max) {
            $limitReached = true;
            if ($punishAction === 'silence') {
                $this->db->softBan($chatId, $senderId, 'سکوت خودکار پس از رسیدن به سقف اخطار: ' . $reasonText);
                $action = 'soft_mute';
                $suffix = "\n⛔ سکوت نرم فعال شد؛ پیام‌های بعدی شما خودکار حذف می‌شود.";
            } elseif ($punishAction === 'kick') {
                // اگر API روبیکا اخراج واقعی را قبول نکند، soft-ban داخلی همچنان جلوی پیام‌های بعدی را می‌گیرد.
                $this->db->softBan($chatId, $senderId, 'سیک/اخراج خودکار پس از رسیدن به سقف اخطار: ' . $reasonText);
                $res = $this->client->experimentalKick($chatId, $senderId);
                $action = 'kick_limit_reached_soft_ban';
                $apiOk = (($res['status'] ?? '') === 'OK' || ($res['ok'] ?? false));
                $suffix = $apiOk ? "\n🚪 سقف اخطار تکمیل شد؛ کاربر اخراج شد." : "\n⚠️ اخراج واقعی API انجام نشد؛ محدودیت داخلی فعال شد.";
                Support::log($this->config, 'punish_kick_result', ['chat_id'=>$chatId, 'user_id'=>$senderId, 'response'=>$res]);
            } elseif ($punishAction === 'ban') {
                // بن خودکار همیشه در لیست داخلی هم ثبت می‌شود تا اگر متد رسمی روبیکا خطا داد، کاربر بی‌اثر نشود.
                $this->db->softBan($chatId, $senderId, 'بن خودکار پس از رسیدن به سقف اخطار: ' . $reasonText);
                $res = $this->client->experimentalHardBan($chatId, $senderId);
                $action = 'ban_limit_reached_soft_ban';
                $apiOk = (($res['status'] ?? '') === 'OK' || ($res['ok'] ?? false));
                $suffix = $apiOk ? "\n⛔ سقف اخطار تکمیل شد؛ کاربر بن شد." : "\n⚠️ بن واقعی API انجام نشد؛ محدودیت داخلی فعال شد.";
                Support::log($this->config, 'punish_ban_result', ['chat_id'=>$chatId, 'user_id'=>$senderId, 'response'=>$res]);
            } elseif ($punishAction === 'delete') {
                $action = 'delete_limit_reached';
                $suffix = "\nℹ️ سقف اخطار تکمیل شد؛ فعلاً فقط پیام‌های خلاف قوانین حذف می‌شود.";
            } else {
                $action = 'delete_warning_limit_no_punish';
            }
        }

        $this->db->addViolation($chatId, $senderId, $messageId, $reasonText, $text, $action);
        $noticeReason = $warningScopeName !== '' ? ($reasonText . ' / اخطار ' . $warningScopeName) : $reasonText;
        [$warningText, $warningMetadata] = MessageFormatter::warningNotice($noticeReason, $warnings, $max, $senderId, $suffix);
        $this->client->sendMessage($chatId, $warningText, null, null, null, $warningMetadata ?: null);

        if ($limitReached) {
            if ($warningScope) {
                $this->db->resetScopedWarning($chatId, $senderId, $warningScope);
            } else {
                $this->db->resetWarning($chatId, $senderId);
            }
        }
    }

    public function runOnce(): array
    {
        $this->db->maintenanceCleanup(false);

        static $lastBroadcastAt = 0;
        $broadcastQueue = ['processed' => 0, 'skipped' => true];
        $broadcastInterval = max(1, (int)($this->config['broadcast_worker_interval'] ?? 15));
        if (time() - $lastBroadcastAt >= $broadcastInterval) {
            $broadcastQueue = $this->processBroadcastQueue((int)($this->config['broadcast_queue_limit'] ?? 20));
            $lastBroadcastAt = time();
        }

        $offset = $this->db->getState('offset_id');
        $limit = (int)($this->config['polling']['limit'] ?? 50);
        $res = $this->client->getUpdates($offset, $limit);

        if (empty($res) || (!isset($res['updates']) && !isset($res['data']['updates']))) {
            return ['processed' => 0, 'broadcast_queue' => $broadcastQueue, 'ok' => false, 'response' => $res];
        }

        $count = 0;

        foreach (UpdateNormalizer::many($res) as $n) {
            try {
                $this->processUpdate($n);
                $count++;
            } catch (\Throwable $e) {
                Support::log($this->config, 'process_update_error', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'update' => $n,
                ]);
            }
        }

        $next = UpdateNormalizer::nextOffset($res);

        if ($next) {
            $this->db->setState('offset_id', $next);
        }

        return ['processed' => $count, 'broadcast_queue' => $broadcastQueue, 'ok' => true, 'next_offset_id' => $next];
    }

    public function runForever(): void
    {
        $sleepMs = (int)($this->config['polling']['sleep_ms'] ?? 650);

        while (true) {
            try {
                $this->runOnce();
            } catch (\Throwable $e) {
                Support::log($this->config, 'worker_error', ['error' => $e->getMessage()]);
                usleep(1500000);
            }

            usleep(max(100, $sleepMs) * 1000);
        }
    }


    private function shouldBypassLocks(bool $isPrivilegedSender, bool $isTrustedSender, array $moderation): bool
    {
        if ($isPrivilegedSender) return true;
        $applyLocksToTrusted = !empty($moderation['apply_locks_to_trusted']);
        return $isTrustedSender && !$applyLocksToTrusted;
    }

    private function resolveAmbiguousMediaForLocks(array $n, array $group): array
    {
        $locks = (array)($group['locks'] ?? []);
        // برای جداسازی صحیح GIF و ویدیو، بررسی ثانویه باید با فعال بودن هر کدام
        // از قفل‌های gif/video/media انجام شود؛ نه فقط قفل GIF.
        $needsExactVideoType = !empty($locks['gif']) || !empty($locks['video']) || !empty($locks['media']);
        if (!$needsExactVideoType || !empty($n['has_gif']) || empty($n['has_file'])) {
            return $n;
        }

        $settings = (array)($this->config['media_detection'] ?? []);
        if (array_key_exists('probe_ambiguous_mp4_for_gif', $settings)
            && empty($settings['probe_ambiguous_mp4_for_gif'])) {
            return $n;
        }

        $extension = mb_strtolower((string)($n['file_extension'] ?? ''), 'UTF-8');
        $contentType = (string)($n['content_type'] ?? '');
        $ambiguous = !empty($n['content_type_ambiguous'])
            || ($extension === 'mp4' && in_array($contentType, ['video', 'file', 'unknown'], true));
        if (!$ambiguous) return $n;

        $fileId = trim((string)($n['file_id'] ?? ''));
        $fileName = trim((string)($n['file_name'] ?? ''));
        $probe = $this->client->probeGifFile($fileId, $fileName);

        Support::log($this->config, 'gif_media_probe', [
            'chat_id' => (string)($n['chat_id'] ?? ''),
            'message_id' => (string)($n['message_id'] ?? ''),
            'file_id' => $fileId,
            'file_name' => $fileName,
            'before_type' => $contentType,
            'result' => $probe,
        ]);

        if (empty($probe['resolved'])) return $n;

        $n['content_type_ambiguous'] = false;
        $n['content_type_reliable'] = true;
        if (!empty($probe['is_gif'])) {
            $n['has_gif'] = true;
            $n['has_video'] = false;
            $n['has_photo'] = false;
            $n['has_voice'] = false;
            $n['has_music'] = false;
            $n['has_document'] = false;
            $n['has_file'] = true;
            $n['content_type'] = 'gif';
            $n['content_fingerprint'] = hash('sha256', 'gif|' . $fileId . '|' . $fileName);
        } else {
            $n['has_gif'] = false;
            $n['has_video'] = true;
            $n['has_photo'] = false;
            $n['has_voice'] = false;
            $n['has_music'] = false;
            // فایل و ویدیو نباید هم‌زمان فعال بمانند؛ در غیر این صورت قفل فایل نیز اشتباهی اجرا می‌شود.
            $n['has_document'] = false;
            $n['has_file'] = true;
            $n['content_type'] = 'video';
            $n['content_fingerprint'] = hash('sha256', 'video|' . $fileId . '|' . $fileName);
        }
        return $n;
    }

    private function handleWelcomeEvent(array $n, array $group): bool
    {
        if (empty($n['member_joined'])) {
            return false;
        }

        // پیام ورود/خوش‌آمد در v67 حذف شده است. فقط اگر خود ربات اضافه شد، گروه ثبت می‌شود.
        if ($this->looksLikeBotJoinEvent($n)) {
            $this->autoRegisterGroupOnBotJoin($n, $group);
        }
        return true;
    }


    private function handleFarewellEvent(array $n, array $group): bool
    {
        if (empty($n['member_left'])) {
            return false;
        }

        // پیام خروج/خداحافظی در v67 کامل حذف شده است.
        return true;
    }

    private function handleTalker(array $n, array $group): bool
    {
        $chatId = (string)($n['chat_id'] ?? '');
        $messageId = (string)($n['message_id'] ?? '');
        if ($chatId === '' || !$this->isGroupChat($chatId)) {
            // v71: سخنگو فقط داخل گروه فعال است و در پیوی ربات هیچ پاسخی نمی‌دهد.
            return false;
        }

        // v70: سخنگو به پیام عادی جواب می‌دهد. اگر پیام ریپلای باشد:
        // - ریپلای روی کاربر/شخص دیگر بی‌پاسخ می‌ماند.
        // - ریپلای روی خود بات یا ریپلای‌هایی که روبیکا فرستنده‌شان را خالی برمی‌گرداند پاسخ می‌گیرند.
        $hasReply = trim((string)($n['reply_to_message_id'] ?? '')) !== '';
        if ($hasReply && !$this->replyCanTriggerTalker($n)) {
            return false;
        }

        $reply = Talker::reply($n, $group);
        if ($reply === null || $reply === '') {
            return false;
        }

        $this->client->sendMessage($chatId, $reply, $messageId ?: null);
        return true;
    }

    private function replyCanTriggerTalker(array $n): bool
    {
        // اگر payload خود پیام ریپلای‌شده نشان بدهد بات است، قطعاً اجازه بده.
        if ($this->replyPayloadLooksLikeBot($n) || $this->replyLabelLooksLikeCurrentBot($n)) {
            return true;
        }

        $replySenderId = $this->senderFromReply($n);
        $botIds = $this->knownBotIds();

        if ($replySenderId !== '') {
            // اگر شناسه ریپلای دقیقاً شناسه خود بات بود، اجازه پاسخ بده.
            if ($botIds && in_array($replySenderId, $botIds, true)) {
                return true;
            }

            // اگر شناسه فرستنده ریپلای مشخص است و بات نیست، یعنی روی یک کاربر ریپلای شده است.
            return false;
        }

        $label = trim($this->replySenderLabel($n));
        if ($label !== '' && $label !== 'کاربر') {
            // اگر نام مشخصی داریم ولی شبیه نام بات نیست، احتمالاً ریپلای روی شخص دیگری است.
            return false;
        }

        // روبیکا برای بعضی پیام‌های خود بات فقط reply_to_message_id می‌دهد و sender/name را خالی می‌گذارد.
        // در این حالت اجازه می‌دهیم سخنگو جواب بدهد تا ریپلای روی خود ربات از کار نیفتد.
        return true;
    }

    private function replyLabelLooksLikeCurrentBot(array $n): bool
    {
        $label = $this->commandKey($this->replySenderLabel($n));
        if ($label === '') {
            return false;
        }

        $names = [
            $this->botDisplayName(),
            (string)($this->config['bot_display_name'] ?? ''),
            (string)($this->config['bot_name'] ?? ''),
            (string)($this->config['bot_username'] ?? ''),
        ];

        foreach ($names as $name) {
            $key = $this->commandKey((string)$name);
            if ($key !== '' && ($label === $key || str_contains($label, $key) || str_contains($key, $label))) {
                return true;
            }
        }

        return false;
    }

    private function replyPayloadLooksLikeBot(array $n): bool
    {
        $message = $n['message'] ?? [];
        if (!is_array($message)) {
            return false;
        }

        foreach (['reply_to_message', 'reply_message', 'replied_message', 'quoted_message'] as $rk) {
            if (!isset($message[$rk]) || !is_array($message[$rk])) {
                continue;
            }
            $reply = $message[$rk];
            if (!empty($reply['is_bot']) || !empty($reply['bot'])) {
                return true;
            }
            foreach (['type', 'object_type', 'author_type', 'sender_type'] as $k) {
                $v = strtolower(trim((string)($reply[$k] ?? '')));
                if ($v !== '' && str_contains($v, 'bot')) {
                    return true;
                }
            }
            foreach (['sender', 'author', 'user', 'from'] as $parent) {
                if (!isset($reply[$parent]) || !is_array($reply[$parent])) {
                    continue;
                }
                if (!empty($reply[$parent]['is_bot']) || !empty($reply[$parent]['bot'])) {
                    return true;
                }
                foreach (['type', 'object_type', 'author_type', 'sender_type'] as $k) {
                    $v = strtolower(trim((string)($reply[$parent][$k] ?? '')));
                    if ($v !== '' && str_contains($v, 'bot')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function talkerEnabled(array $group): bool
    {
        $locks = $group['locks'] ?? [];
        // پیش‌فرض روشن؛ فقط اگر مقدار صریح false/0 ذخیره شده باشد خاموش است.
        return !(array_key_exists('talker_enabled', $locks) && empty($locks['talker_enabled']));
    }

    private function welcomeEnabled(array $group): bool
    {
        $locks = $group['locks'] ?? [];
        return false;
    }

    private function welcomeText(array $group): string
    {
        $locks = $group['locks'] ?? [];
        $txt = trim((string)($locks['welcome_text'] ?? ''));
        return $txt !== '' ? $txt : 'سلام {name}، به «{group}» خوش اومدی 🌹';
    }

    private function farewellEnabled(array $group): bool
    {
        $locks = $group['locks'] ?? [];
        return false;
    }

    private function farewellText(array $group): string
    {
        $locks = $group['locks'] ?? [];
        $txt = trim((string)($locks['farewell_text'] ?? ''));
        return $txt !== '' ? $txt : 'خداحافظ {name}، امیدواریم دوباره در «{group}» ببینیمت 🌙';
    }

    private function setFarewellEnabled(string $chatId, array $group, bool $enabled): void
    {
        $locks = $group['locks'] ?? [];
        $locks['farewell_enabled'] = $enabled;
        $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
    }

    private function setFarewellText(string $chatId, array $group, string $text): void
    {
        $locks = $group['locks'] ?? [];
        $text = trim($text);
        if ($text === '') {
            $text = 'خداحافظ {name}، امیدواریم دوباره در «{group}» ببینیمت 🌙';
        }
        $locks['farewell_text'] = mb_substr($text, 0, 500, 'UTF-8');
        $locks['farewell_enabled'] = true;
        $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
    }

    private function setWelcomeEnabled(string $chatId, array $group, bool $enabled): void
    {
        $locks = $group['locks'] ?? [];
        $locks['welcome_enabled'] = $enabled;
        $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
    }

    private function setWelcomeText(string $chatId, array $group, string $text): void
    {
        $locks = $group['locks'] ?? [];
        $text = trim($text);
        if ($text === '') {
            $text = 'سلام {name}، به «{group}» خوش اومدی 🌹';
        }
        $locks['welcome_text'] = mb_substr($text, 0, 500, 'UTF-8');
        $locks['welcome_enabled'] = true;
        $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
    }

    private function renderWelcomeText(string $template, string $name, string $groupName): string
    {
        return strtr($template, [
            '{name}' => $name,
            '{user}' => $name,
            '{group}' => $groupName,
            '{bot}' => $this->botDisplayName(),
        ]);
    }

    private function handleCommand(array $n, array $group, string $text): bool
    {
        $chatId = (string)$n['chat_id'];
        $senderId = (string)$n['sender_id'];
        $messageId = (string)$n['message_id'];

        $clean = $this->cleanCommand($text);
        $lower = mb_strtolower($clean, 'UTF-8');
        $key = $this->commandKey($lower);

        $this->debugLog('command_seen', [
            'chat_id' => $chatId,
            'sender_id' => $senderId,
            'owner_id' => $this->config['owner_id'] ?? '',
            'clean' => $clean,
            'key' => $key,
        ]);

        if ($key === 'start' || $key === 'help' || $key === 'راهنما' || str_starts_with($key, 'راهنما') || str_starts_with($key, 'help')) {
            if (!$this->isGroupChat($chatId) && $key === 'start') {
                $this->sendPrivateStart($chatId, $senderId, $messageId);
            } else {
                $topic = ($key === 'help' || $key === 'راهنما') ? '' : $this->helpTopicFromCommand($clean, $key);
                $this->sendHelp($chatId, $messageId ?: null, $topic);
            }
            return true;
        }

        if (in_array($key, ['id', 'آیدی', 'ایدی', 'ايدي', 'شناسه'], true)) {
            $replySenderId = $this->senderFromReply($n);
            if ($replySenderId !== '') {
                $this->client->sendMessage($chatId, "🆔 آیدی کاربر:
{$replySenderId}", $messageId);
            } else {
                $this->client->sendMessage($chatId, "🆔 شناسه‌ها:
Chat ID: {$chatId}
User ID: " . ($senderId ?: 'نامشخص') . "
Message ID: {$messageId}", $messageId);
            }
            return true;
        }

        if (in_array($key, ['joined', 'عضوشدم', 'تاییدعضویت', 'تاییدجوین'], true)) {
            if (!$this->isGroupChat($chatId)) {
                $this->markGlobalForceJoinVerified($senderId);
                $verifiedGroups = $this->markPendingForceJoinGroups($senderId);
                $txt = "✅ عضویت شما در جوین اجباری ربات ثبت شد.
اکنون می‌توانید از ربات استفاده کنید.";
                if ($verifiedGroups > 0) {
                    $txt .= "

🔐 دسترسی شما برای {$verifiedGroups} گروه هم تایید شد.";
                }
                $this->client->sendMessage($chatId, $txt, $messageId);
                $this->sendPrivateStart($chatId, $senderId, null);
                return true;
            }

            // تایید جوین فقط از پیوی انجام می‌شود؛ داخل گروه با کاربران کاری نداریم.
            return true;
        }

        if (in_array($key, ['groups', 'گروهها', 'گروههایم', 'گروههایمن'], true)) {
            if ($this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'لیست گروه‌ها فقط در پیوی ربات نمایش داده می‌شود. به پیوی ربات برو و /groups را بزن.', $messageId);
            } else {
                if ($senderId === '') {
                    $senderId = $chatId;
                }
                if (!$this->canUseBot($senderId)) {
                    $this->sendGlobalForceJoinNotice($chatId, $messageId);
                    return true;
                }
                $this->sendGroupsList($chatId, $senderId, $messageId);
            }
            return true;
        }

        if (in_array($key, ['admin', 'ownerpanel', 'پنلاصلی', 'مدیریتکل'], true)) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات به این بخش دسترسی دارد.', $messageId);
                return true;
            }
            $this->sendOwnerPanel($chatId, $messageId);
            return true;
        }

        if (in_array($key, ['install', 'نصب', 'نصبربات', 'راهاندازی', 'راهاندازیربات', 'راهاندازیر بات'], true)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'این دستور باید داخل گروه اجرا شود.', $messageId);
                return true;
            }

            if (!$this->canUseBot($senderId)) {
                $this->client->sendMessage(
                    $chatId,
                    "🔐 برای نصب ربات، اول باید جوین اجباری ربات را در پیوی تکمیل کنی.

لطفاً پیوی ربات را باز کن، /start را بزن و بعد از عضویت، گزینه «✅ عضو شدم» را بزن.",
                    $messageId
                );
                return true;
            }

            $groupTitle = $this->groupTitleFromUpdate($n);
            if ($groupTitle === '') {
                $groupTitle = $this->fetchGroupTitle($chatId);
            }
            $admins = $group['group_admins'] ?? [];
            $alreadyInstalled = !empty($admins);

            if (!$alreadyInstalled) {
                $security = $this->config['install_security'] ?? [];
                // v86: هر مدیری که ربات را به گروه اضافه کرده است باید بتواند اولین نصب را انجام دهد.
                // Bot API رسمی روبیکا نقش واقعی فرستنده را همیشه اعلام نمی‌کند؛ بنابراین این گزینه
                // به‌صورت پیش‌فرض فعال است و اولین نصب‌کننده را مدیر داخلی همان گروه ثبت می‌کند.
                // برای بازگرداندن محدودیت قدیمی، allow_group_manager_install را در config.php برابر false بگذارید.
                $allowGroupManagerInstall = !array_key_exists('allow_group_manager_install', $security)
                    || !empty($security['allow_group_manager_install']);
                $ownerOnly = !array_key_exists('owner_only_first_install', $security) || !empty($security['owner_only_first_install']);
                $allowedInstallers = array_values(array_filter(array_map('strval', (array)($security['allowed_installer_ids'] ?? []))));
                if (!$allowGroupManagerInstall && $ownerOnly && !$this->isOwner($senderId) && !in_array($senderId, $allowedInstallers, true)) {
                    $this->client->sendMessage($chatId, "⛔ اولین نصب این گروه فقط توسط مالک اصلی ربات یا شناسه‌های مجاز داخل config.php انجام می‌شود.", $messageId);
                    return true;
                }
            }

            if ($alreadyInstalled) {
                if ($this->isPrivileged($senderId, $group)) {
                    if ($groupTitle !== '') {
                        $this->db->updateGroup($chatId, ['title' => $groupTitle]);
                    }
                    $titleText = $this->groupLabel($this->db->getGroup($chatId));
                    $botName = $this->botDisplayName();
                    $this->client->sendMessage(
                        $chatId,
                        "ℹ️ {$botName} از قبل در «{$titleText}» نصب است.
مدیریت: پیوی ربات ← /start ← 📋 گروه‌های من",
                        $messageId
                    );
                    return true;
                }

                $this->client->sendMessage($chatId, '⛔ این گروه از قبل نصب شده است و شما مدیر داخلی ربات در این گروه نیستی.', $messageId);
                return true;
            }

            $admins = [];
            if ($senderId !== '') {
                $admins[] = $senderId;
                if ($this->groupCreatorId($chatId) === '') {
                    $this->setGroupCreatorId($chatId, $senderId);
                }
            }
            // مالک اصلی ربات دسترسی سراسری دارد، اما نباید داخل لیست ادمین‌های هر گروه ذخیره/نمایش داده شود.
            $admins = array_values(array_unique(array_filter(array_map('strval', $admins))));

            $titleToSave = $groupTitle !== '' ? $groupTitle : $this->defaultGroupTitle($chatId);

            $this->db->updateGroup($chatId, [
                'title' => $titleToSave,
                'enabled' => 1,
                'max_warnings' => max(2, (int)($group['max_warnings'] ?? 2)),
                'admins_json' => Support::jsonEncode(array_values($admins)),
            ]);

            $botName = $this->botDisplayName();
            $this->client->sendMessage(
                $chatId,
                "✅ {$botName} در «{$titleToSave}» نصب شد.
مدیریت: پیوی ربات ← /start ← 📋 گروه‌های من",
                $messageId
            );
            return true;
        }

        if (in_array($key, ['panel', 'پنل'], true) || preg_match('/^(panel|پنل)\s+([gb][a-zA-Z0-9_\-]+)/u', $clean)) {
            $targetChatId = $chatId;

            if (preg_match('/^(panel|پنل)\s+([gb][a-zA-Z0-9_\-]+)/u', $clean, $pm)) {
                $targetChatId = $pm[2];
            }

            if (!$this->isGroupChat($targetChatId)) {
                $this->client->sendMessage($chatId, "برای باز کردن پنل گروه، دستور را با شناسه گروه بفرست:\n/panel GROUP_ID", $messageId);
                return true;
            }

            if (!$this->isGroupChat($chatId) && !$this->canUseBot($senderId)) {
                $this->sendGlobalForceJoinNotice($chatId, $messageId);
                return true;
            }

            $targetGroup = $this->db->getGroup($targetChatId);

            if (!$this->isPrivileged($senderId, $targetGroup)) {
                $this->client->sendMessage($chatId, "⛔ دسترسی مدیریت این گروه را نداری.\nاگر مدیر گروه هستی و این اولین نصب است، داخل همان گروه «نصب» یا /install را بزن.", $messageId);
                return true;
            }

            if ($this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, "⚙️ پنل فقط در پیوی باز می‌شود.
مسیر: /start ← 📋 گروه‌های من ← نام گروه", $messageId);
                return true;
            }

            $this->sendPanelToChat($chatId, $targetChatId, $messageId, $senderId);
            return true;
        }

        if (preg_match('/^(?:لغو\s+همگانی|لغو\s+صف\s+همگانی|لغو\s+صف|cancel\s+broadcast)(?:\s*#?(\d+))?$/iu', $clean, $cm)) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی می‌تواند صف همگانی را لغو کند.', $messageId);
                return true;
            }

            if (!empty($cm[1])) {
                $result = $this->db->cancelBroadcastJob((int)$cm[1]);
                $this->client->sendMessage($chatId, !empty($result['ok']) ? '✅ صف همگانی لغو شد.' : '❌ این صف قابل لغو نیست یا پیدا نشد.', $messageId);
                return true;
            }

            $result = $this->db->cancelAllBroadcastJobs();
            $jobs = (int)($result['jobs'] ?? 0);
            $targets = (int)($result['canceled_targets'] ?? 0);
            $this->client->sendMessage($chatId, $jobs > 0 ? "✅ همه صف‌های همگانی لغو شد.\nصف‌ها: {$jobs}\nمقصدهای باقی‌مانده: {$targets}" : '✅ صف فعالی برای لغو وجود ندارد.', $messageId);
            return true;
        }

        if (preg_match('/^(broadcast|همگانی)\s+(.+)$/u', $clean, $bm)) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی می‌تواند پیام همگانی بفرستد.', $messageId);
                return true;
            }
            $job = $this->queueBroadcastToAllTargets($bm[2], $senderId);
            if (($job['total'] ?? 0) <= 0) {
                $this->client->sendMessage($chatId, '❌ مقصدی برای پیام همگانی پیدا نشد.', $messageId);
                return true;
            }
            $this->client->sendMessage($chatId, "✅ پیام همگانی در صف کرون ثبت شد.
شناسه صف: #{$job['job_id']}
تعداد مقصد: {$job['total']}

ارسال مرحله‌ای با cron_broadcast.php انجام می‌شود.", $messageId);
            return true;
        }

        if (preg_match('/^(send|ارسال)\s+([g][a-zA-Z0-9_\-]+)\s+(.+)$/u', $clean, $sm)) {
            $this->client->sendMessage($chatId, "⛔ ارسال پیام مستقیم به یک گروه برای ادمین‌های گروه حذف شد.
فقط مالک اصلی می‌تواند از «پیام همگانی» استفاده کند.", $messageId);
            return true;
        }

        if (in_array($key, ['status', 'وضعیت', 'وضعیتربات', 'وضعیتقفل', 'وضعیتقفلها', 'قفلها'], true)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, "وضعیت قفل‌ها داخل گروه نمایش داده می‌شود.
داخل گروه بزن: وضعیت", $messageId);
                return true;
            }
            [$txt, $metadata] = $this->groupLockStatusPayload($chatId);
            $this->client->sendMessage($chatId, $txt, $messageId, null, null, $metadata ?: null);
            return true;
        }

        if (in_array($key, ['آمار', 'امار', 'stats', 'آمارگروه', 'امارگروه', 'groupstats'], true)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'آمار گروه داخل همان گروه نمایش داده می‌شود.', $messageId);
                return true;
            }
            [$txt, $metadata] = GroupStatsService::groupStatsPayload($this->db, $group, (string)($this->config['owner_id'] ?? ''));
            $this->client->sendMessage($chatId, $txt, $messageId, null, null, $metadata ?: null);
            return true;
        }

        if (in_array($key, ['آمارم', 'امارم', 'آمارمن', 'امارمن', 'mystats'], true)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'آمار کاربر داخل گروه نمایش داده می‌شود.', $messageId);
                return true;
            }
            $this->client->sendMessage($chatId, GroupStatsService::userStatsText($this->db, $chatId, $senderId), $messageId);
            return true;
        }

        if (preg_match('/^(فونت|font)\s+(.+)$/u', $clean, $fm)) {
            $this->client->sendMessage($chatId, $this->fontVariantsText(trim($fm[2])), $messageId);
            return true;
        }

        if (!$this->isPrivileged($senderId, $group)) {
            if ($this->isKnownAdminCommandKey($key, $clean)) {
                $this->client->sendMessage($chatId, "⛔ دسترسی مدیریت نداری.\nاگر مدیر گروه هستی و این اولین نصب است، دستور «نصب» یا /install را بزن.", $messageId);
                return true;
            }
            return false;
        }

        if (preg_match('/^(حذف|پاکسازی)\s+(\d{1,3})$/u', $clean, $dm)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'حذف پیام فقط داخل گروه انجام می‌شود.', $messageId);
                return true;
            }
            $count = max(1, min(200, (int)$dm[2]));
            $this->deleteRecentMessagesWithProgress($chatId, $count, $messageId);
            return true;
        }

        if (preg_match('/^(تنظیم لقب|لقب)\s+(.+)$/u', $clean, $lm)) {
            $target = $this->senderFromReply($n);
            if ($target === '') {
                $this->client->sendMessage($chatId, "↩️ روی پیام کاربر ریپلای کن و بزن:\nتنظیم لقب متن", $messageId);
                return true;
            }
            $nickname = trim($lm[2]);
            $this->setUserNickname($chatId, $target, $nickname);
            $this->sendMentionNotice($chatId, 'کاربر', $target, "\nلقب «{$nickname}» ثبت شد.", $messageId);
            return true;
        }

        if (preg_match('/^(تنظیم اصل|اصل)\s+(.+)$/u', $clean, $om)) {
            $target = $this->senderFromReply($n);
            if ($target === '') {
                $this->client->sendMessage($chatId, "↩️ روی پیام کاربر ریپلای کن و بزن:\nتنظیم اصل متن", $messageId);
                return true;
            }
            $origin = trim($om[2]);
            $this->setUserOrigin($chatId, $target, $origin);
            $this->sendMentionNotice($chatId, 'کاربر', $target, "\nاصل «{$origin}» ثبت شد.", $messageId);
            return true;
        }

        if (in_array($key, ['انتقالمالکیت', 'انتقالمالکیتگروه'], true) || preg_match('/^انتقال مالکیت/u', $clean)) {
            $target = $this->targetFromReplyOrCommand($n, $clean);
            if ($target === '') {
                $this->client->sendMessage($chatId, "↩️ روی پیام مالک جدید ریپلای کن و بزن:\nانتقال مالکیت", $messageId);
                return true;
            }
            $admins = array_values(array_unique(array_merge($group['group_admins'] ?? [], [$target])));
            $this->db->updateGroup($chatId, ['admins_json' => Support::jsonEncode($admins)]);
            $this->setGroupCreatorId($chatId, $target);
            $this->sendMentionNotice($chatId, 'کاربر', $target, "\nمالکیت داخلی گروه منتقل شد.", $messageId);
            return true;
        }

        if (preg_match('/^(?:تنظیم\s+)?(?:اخطار|هشدار)\s+(.+?)\s+(خاموش|غیرفعال|غيرفعال|0|\d{1,2})$/u', $clean, $swm)) {
            $lockNameRaw = trim(preg_replace('/^(?:قفل\s+)?/u', '', trim($swm[1])) ?: $swm[1]);
            $lockKey = $this->lockKey($lockNameRaw);
            if ($lockKey === null) {
                $this->client->sendMessage($chatId, '❌ نام قفل را نشناختم. مثال: تنظیم اخطار لینک 3', $messageId);
                return true;
            }
            $rawLimit = trim($swm[2]);
            $limit = preg_match('/^\d+$/u', $rawLimit) ? (int)$rawLimit : 0;
            $this->setLockWarningLimit($chatId, $group, $lockKey, $limit);
            $lockFa = $this->lockName($lockKey);
            if ($limit <= 0) {
                $this->client->sendMessage($chatId, "✅ اخطار مخصوص قفل {$lockFa} خاموش شد؛ از سقف عمومی اخطار استفاده می‌شود.", $messageId);
            } else {
                $this->client->sendMessage($chatId, "✅ اخطار مخصوص قفل {$lockFa} روی {$limit} تنظیم شد. بعد از رسیدن به این سقف، جریمه فعلی اجرا می‌شود.", $messageId);
            }
            return true;
        }

        if (preg_match('/^(?:تنظیم\s+)?(?:جریمه|مجازات)\s+(.+?)\s+(فقط\s*حذف|حذف|سکوت|سکوت\s*نرم|اخراج|سیک|بن|هیچ|بدون\s*جریمه|ندارد)$/u', $clean, $lpm)) {
            $lockNameRaw = trim(preg_replace('/^(?:قفل\s+)?/u', '', trim($lpm[1])) ?: $lpm[1]);
            if ($this->commandKey($lockNameRaw) === 'هشدار' || preg_match('/^هشدار\s+/u', $lockNameRaw)) {
                // دستور «جریمه هشدار ...» مخصوص جریمه عمومی است و پایین‌تر پردازش می‌شود.
            } else {
                $lockKey = $this->lockKey($lockNameRaw);
                if ($lockKey === null) {
                    $this->client->sendMessage($chatId, '❌ نام قفل را نشناختم. مثال: تنظیم جریمه لینک سکوت', $messageId);
                    return true;
                }
                $wanted = preg_replace('/\s+/u', '', $lpm[2]) ?: $lpm[2];
                $map = [
                    'فقطحذف' => 'delete',
                    'حذف' => 'delete',
                    'سکوت' => 'silence',
                    'سکوتنرم' => 'silence',
                    'اخراج' => 'kick',
                    'سیک' => 'kick',
                    'بن' => 'ban',
                    'هیچ' => 'none',
                    'بدونجریمه' => 'none',
                    'ندارد' => 'none',
                ];
                $action = $map[$wanted] ?? 'ban';
                $this->setLockPunishAction($chatId, $group, $lockKey, $action);
                $lockFa = $this->lockName($lockKey);
                $name = $this->punishActionLabel($action);
                $this->client->sendMessage($chatId, "✅ جریمه مخصوص قفل {$lockFa} روی {$name} تنظیم شد.", $messageId);
                return true;
            }
        }

        if (in_array($key, ['اخطار'], true) || preg_match('/^اخطار(?:\s+([a-zA-Z0-9_\-]+))?$/u', $clean)) {
            $target = $this->targetFromReplyOrCommand($n, $clean);
            if ($target === '') {
                $this->client->sendMessage($chatId, "↩️ روی پیام کاربر ریپلای کن و بزن:\nاخطار", $messageId);
                return true;
            }
            $this->addManualWarningAndApplyPenalty($chatId, $messageId, $group, $target, 'اخطار دستی');
            return true;
        }

        if ($this->isClearBanListCommand($key, $clean)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'پاک‌سازی لیست بن فقط داخل گروه اجرا می‌شود.', $messageId);
                return true;
            }
            $bannedUsers = $this->db->listBanRecords($chatId, 1000);
            $count = $this->db->clearBanRecords($chatId);
            $unbanOk = 0;
            foreach ($bannedUsers as $row) {
                $uid = trim((string)($row['user_id'] ?? ''));
                if ($uid === '') {
                    continue;
                }
                $res = $this->client->experimentalUnban($chatId, $uid);
                if (($res['status'] ?? '') === 'OK' || ($res['ok'] ?? false)) {
                    $unbanOk++;
                }
            }
            Support::log($this->config, 'manual_clear_ban_list', ['chat_id'=>$chatId, 'count'=>$count, 'hard_unban_ok'=>$unbanOk]);
            $failedUnban = max(0, count($bannedUsers) - $unbanOk);
            $this->client->sendMessage($chatId, $failedUnban === 0 ? '✅ لیست بن داخلی و بن‌های واقعی پاک‌سازی شد.' : "⚠️ لیست داخلی پاک شد؛ رفع بن واقعی {$failedUnban} کاربر در API ناموفق بود.", $messageId);
            return true;
        }

        if ($this->isUnbanCommand($key, $clean)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'دستور آن‌بن/آن‌سیک فقط داخل گروه اجرا می‌شود.', $messageId);
                return true;
            }
            $target = $this->targetFromReplyOrCommand($n, $clean);
            if ($target === '') {
                $this->client->sendMessage($chatId, "↩️ روی پیام کاربر ریپلای کن و بنویس:\nآن بن\n\nیا شناسه کاربر را مستقیم بنویس:\nآن بن USER_ID\nآن سیک USER_ID", $messageId);
                return true;
            }
            $removedBanRecords = $this->db->unBanRecord($chatId, $target);
            $this->db->resetWarning($chatId, $target);
            $this->db->resetScopedWarnings($chatId, $target);
            $res = $this->client->experimentalUnban($chatId, $target);
            $ok = (($res['status'] ?? '') === 'OK' || ($res['ok'] ?? false));
            Support::log($this->config, 'manual_unban_result', ['chat_id'=>$chatId, 'user_id'=>$target, 'response'=>$res]);
            $this->sendMentionNotice($chatId, 'کاربر', $target, $ok ? ' آن‌بن شد' : ' از محدودیت داخلی خارج شد؛ رفع بن واقعی API ناموفق بود', $messageId);
            return true;
        }

        if ($this->isBanCommand($key, $clean)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'دستور بن فقط داخل گروه اجرا می‌شود.', $messageId);
                return true;
            }
            $target = $this->targetFromReplyOrCommand($n, $clean);
            if ($target === '') {
                $this->client->sendMessage($chatId, "↩️ روی پیام کاربر ریپلای کن و بنویس:\nبن\n\nیا شناسه کاربر را مستقیم بنویس:\nبن USER_ID", $messageId);
                return true;
            }
            if ($this->isOwner($target) || $this->isPrivileged($target, $group)) {
                $this->client->sendMessage($chatId, '⛔ کاربر مالک/ادمین داخلی ربات است و با این دستور بن نمی‌شود.', $messageId);
                return true;
            }
            $this->db->softBan($chatId, $target, 'بن دستی توسط ادمین');
            $banRes = $this->client->experimentalHardBan($chatId, $target);
            $banOk = (($banRes['status'] ?? '') === 'OK' || ($banRes['ok'] ?? false));
            $this->db->resetWarning($chatId, $target);
            $this->db->resetScopedWarnings($chatId, $target);
            Support::log($this->config, 'manual_ban_result', ['chat_id'=>$chatId, 'user_id'=>$target, 'ban_response'=>$banRes]);
            $this->sendMentionNotice($chatId, 'کاربر', $target, $banOk ? ' بن شد' : ' در محدودیت داخلی قرار گرفت؛ بن واقعی API انجام نشد', $messageId);
            return true;
        }

        if ($this->isKickCommand($key, $clean)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'دستور سیک/اخراج فقط داخل گروه اجرا می‌شود.', $messageId);
                return true;
            }
            $target = $this->targetFromReplyOrCommand($n, $clean);
            if ($target === '') {
                $this->client->sendMessage($chatId, "↩️ روی پیام کاربر ریپلای کن و بنویس:\nسیک\n\nیا شناسه کاربر را مستقیم بنویس:\nسیک USER_ID", $messageId);
                return true;
            }
            if ($this->isOwner($target) || $this->isPrivileged($target, $group)) {
                $this->client->sendMessage($chatId, '⛔ کاربر مالک/ادمین داخلی ربات است و با این دستور اخراج نمی‌شود.', $messageId);
                return true;
            }
            $this->db->softBan($chatId, $target, 'سیک دستی توسط ادمین');
            $kickRes = $this->client->experimentalKick($chatId, $target);
            $kickOk = (($kickRes['status'] ?? '') === 'OK' || ($kickRes['ok'] ?? false));
            $this->db->resetWarning($chatId, $target);
            $this->db->resetScopedWarnings($chatId, $target);
            Support::log($this->config, 'manual_kick_result', ['chat_id'=>$chatId, 'user_id'=>$target, 'kick_response'=>$kickRes]);
            $this->sendMentionNotice($chatId, 'کاربر', $target, $kickOk ? ' از گروه اخراج شد' : ' در محدودیت داخلی قرار گرفت؛ اخراج واقعی API انجام نشد', $messageId);
            return true;
        }

        if (in_array($key, ['on', 'فعال', 'فعالسازی', 'فعالسازیربات', 'روشن', 'روشنربات'], true)) {
            $this->db->updateGroup($chatId, ['enabled' => 1]);
            $this->client->sendMessage($chatId, '✅ ربات برای این گروه فعال شد.', $messageId);
            return true;
        }

        if (in_array($key, ['off', 'خاموش', 'خاموشربات'], true)) {
            $this->db->updateGroup($chatId, ['enabled' => 0]);
            $this->client->sendMessage($chatId, '⛔ ربات برای این گروه خاموش شد.', $messageId);
            return true;
        }

        if (in_array($key, ['حذفگروه', 'حذفربات', 'پاکسازیرگروه', 'پاکسازیگروه', 'deletegroup', 'removegroup'], true)) {
            $this->client->sendMessage($chatId, '⛔ حذف گروه از داخل ربات غیرفعال شده است.', $messageId);
            return true;
        }

        if (in_array($key, ['سخنگوروشن', 'talkeron'], true)) {
            $locks = $group['locks'] ?? [];
            $locks['talker_enabled'] = true;
            $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
            $this->client->sendMessage($chatId, '✅ سخنگو روشن شد.', $messageId);
            return true;
        }

        if (in_array($key, ['سخنگوخاموش', 'talkeroff'], true)) {
            $locks = $group['locks'] ?? [];
            $locks['talker_enabled'] = false;
            $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
            $this->client->sendMessage($chatId, '⛔ سخنگو خاموش شد.', $messageId);
            return true;
        }

        if (in_array($key, ['status', 'وضعیت', 'وضعیتربات'], true)) {
            [$txt, $metadata] = $this->groupLockStatusPayload($chatId);
            $this->client->sendMessage($chatId, $txt, $messageId, null, null, $metadata ?: null);
            return true;
        }

        if (preg_match('/^(set_title|نام گروه|نامگروه)\s+(.+)$/u', $clean, $tm)) {
            $title = trim($tm[2]);
            if ($title === '') {
                $this->client->sendMessage($chatId, 'نام گروه خالی است.', $messageId);
                return true;
            }
            $this->db->updateGroup($chatId, ['title' => mb_substr($title, 0, 80, 'UTF-8')]);
            $this->client->sendMessage($chatId, "✅ نام نمایشی گروه ذخیره شد:\n{$title}", $messageId);
            return true;
        }

        if ($this->isGroupAdminPromoteCommand($key, $clean)) {
            // طبق درخواست کاربر: «تنظیم ادمین» هم مثل «ترفیع» فقط ادمین داخلی ربات می‌کند،
            // نه ادمین واقعی گروه روبیکا. چون متدهای promote در Bot API روی همه گروه‌ها پایدار نیستند.
            $targetUserId = $this->extractUserIdFromCommand($clean);
            if ($targetUserId === '') {
                $targetUserId = $this->senderFromReply($n);
            }

            if ($targetUserId === '') {
                $this->client->sendMessage($chatId, "↩️ روی پیام کاربر ریپلای کن و بنویس:
تنظیم ادمین

یا شناسه کاربر را مستقیم بنویس:
تنظیم ادمین USER_ID", $messageId);
                return true;
            }

            $admins = array_values(array_unique(array_merge($group['group_admins'] ?? [], [$targetUserId])));
            $this->db->updateGroup($chatId, ['admins_json' => Support::jsonEncode($admins)]);
            $this->rememberBotAdminName($chatId, $group, $targetUserId, $this->senderNameFromReply($n));
            $this->sendMentionNotice($chatId, 'کاربر', $targetUserId, "
ادمین داخلی ربات شد.", $messageId);
            return true;
        }

        if ($this->isGroupAdminDemoteCommand($key, $clean)) {
            // هماهنگ با «تنظیم ادمین»: حذف ادمین هم ادمین داخلی ربات را حذف می‌کند.
            $targetUserId = $this->extractUserIdFromCommand($clean);
            if ($targetUserId === '') {
                $targetUserId = $this->senderFromReply($n);
            }

            if ($targetUserId === '') {
                $this->client->sendMessage($chatId, "↩️ روی پیام کاربر ریپلای کن و بنویس:
حذف ادمین

یا شناسه کاربر را مستقیم بنویس:
حذف ادمین USER_ID", $messageId);
                return true;
            }

            if ($this->isOwner($targetUserId)) {
                $this->client->sendMessage($chatId, '⛔ مالک اصلی ربات قابل حذف از ادمینی نیست.', $messageId);
                return true;
            }
            $admins = array_values(array_filter($group['group_admins'] ?? [], fn($x) => $x !== $targetUserId));
            $this->db->updateGroup($chatId, ['admins_json' => Support::jsonEncode($admins)]);
            $this->forgetBotAdminName($chatId, $group, $targetUserId);
            $this->sendMentionNotice($chatId, 'کاربر', $targetUserId, "
از ادمینی داخلی ربات خارج شد.", $messageId);
            return true;
        }

        if ($this->isPromoteCommand($key, $clean)) {
            $targetUserId = $this->extractUserIdFromCommand($clean);
            if ($targetUserId === '') {
                $targetUserId = $this->senderFromReply($n);
            }

            if ($targetUserId === '') {
                $this->client->sendMessage($chatId, "↩️ روی پیام کاربر ریپلای کن و بنویس:
ترفیع", $messageId);
                return true;
            }

            $admins = array_values(array_unique(array_merge($group['group_admins'] ?? [], [$targetUserId])));
            $this->db->updateGroup($chatId, ['admins_json' => Support::jsonEncode($admins)]);
            $this->rememberBotAdminName($chatId, $group, $targetUserId, $this->senderNameFromReply($n));
            $this->sendMentionNotice($chatId, 'کاربر', $targetUserId, "
ادمین داخلی ربات شد.", $messageId);
            return true;
        }

        if ($this->isDemoteCommand($key, $clean)) {
            $targetUserId = $this->extractUserIdFromCommand($clean);
            if ($targetUserId === '') {
                $targetUserId = $this->senderFromReply($n);
            }

            if ($targetUserId === '') {
                $this->client->sendMessage($chatId, "↩️ روی پیام کاربر ریپلای کن و بنویس:
تنزیل", $messageId);
                return true;
            }

            if ($this->isOwner($targetUserId)) {
                $this->client->sendMessage($chatId, '⛔ مالک اصلی ربات قابل تنزیل نیست.', $messageId);
                return true;
            }
            $admins = array_values(array_filter($group['group_admins'] ?? [], fn($x) => $x !== $targetUserId));
            $this->db->updateGroup($chatId, ['admins_json' => Support::jsonEncode($admins)]);
            $this->forgetBotAdminName($chatId, $group, $targetUserId);
            $this->sendMentionNotice($chatId, 'کاربر', $targetUserId, "
از ادمینی داخلی ربات خارج شد.", $messageId);
            return true;
        }

        if (in_array($key, ['admins', 'ادمینها', 'ادمینهایربات', 'مدیران', 'لیستادمین', 'لیستادمینها', 'ادمینهایگروه'], true)) {
            [$txt, $metadata] = $this->adminListPayload($chatId);
            $this->client->sendMessage($chatId, $txt, $messageId, null, null, $metadata ?: null);
            return true;
        }

        if (in_array($key, ['گزارشتخلفات', 'تخلفات', 'violationreport'], true)) {
            [$txt, $metadata] = $this->violationReportPayload($chatId);
            $this->client->sendMessage($chatId, $txt, $messageId, null, null, $metadata ?: null);
            return true;
        }

        if (preg_match('/^(warn_limit|حداخطار|سقفاخطار)\s+(\d+)$/u', $clean, $wm)) {
            $limit = max(1, min(20, (int)$wm[2]));
            $this->db->updateGroup($chatId, ['max_warnings' => $limit]);
            $this->client->sendMessage($chatId, "✅ سقف اخطار روی {$limit} تنظیم شد.", $messageId);
            return true;
        }

        if (in_array($key, ['warningon', 'اخطارروشن', 'هشدارروشن'], true)) {
            $this->setLockOption($chatId, $group, 'warnings_enabled', true);
            $this->client->sendMessage($chatId, '✅ سیستم اخطار روشن شد.', $messageId);
            return true;
        }

        if (in_array($key, ['warningoff', 'اخطارخاموش', 'هشدارخاموش'], true)) {
            $this->setLockOption($chatId, $group, 'warnings_enabled', false);
            $this->client->sendMessage($chatId, '✅ سیستم اخطار خاموش شد. پیام خلاف قوانین حذف می‌شود ولی هشدار ثبت/ارسال نمی‌شود.', $messageId);
            return true;
        }

        if (preg_match('/^(اسپم|حد اسپم|تنظیم اسپم|spam)\s*(\d{1,2})$/u', $clean, $fm)) {
            $limit = max(2, min(30, (int)$fm[2]));
            $flood = $group['flood'] ?? ['max_messages' => 5, 'seconds' => 8];
            $flood['max_messages'] = $limit;
            $flood['seconds'] = max(3, (int)($flood['seconds'] ?? 8));
            $this->db->updateGroup($chatId, ['flood_json' => Support::jsonEncode($flood)]);
            $this->client->sendMessage($chatId, "✅ حد اسپم روی {$limit} پیام تنظیم شد.", $messageId);
            return true;
        }

        if (in_array($key, ['لیستپاسخها', 'لیستپاسخ‌ها'], true)) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ مشاهده لیست پاسخ‌های سخنگو فقط برای مالک اصلی ربات مجاز است.', $messageId);
                return true;
            }
            $this->client->sendMessage($chatId, Talker::listText(), $messageId);
            return true;
        }

        if (preg_match('/^(افزودن پاسخ|افزودن پاسخ دقیق)\s+(.+)$/u', $clean, $tm)) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ افزودن پاسخ سخنگو فقط برای مالک اصلی ربات مجاز است.', $messageId);
                return true;
            }
            [$trigger, $response] = Talker::parsePair($tm[2]);
            if ($trigger === '' || $response === '' || !Talker::addReply('exact', $trigger, $response)) {
                $this->client->sendMessage($chatId, "❌ فرمت درست:
افزودن پاسخ سلام | سلام عزیزم 🌹", $messageId);
                return true;
            }
            $this->client->sendMessage($chatId, "✅ پاسخ دقیق سخنگو ذخیره شد.
کلمه: {$trigger}", $messageId);
            return true;
        }

        if (preg_match('/^(افزودن پاسخ شامل)\s+(.+)$/u', $clean, $tm)) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ افزودن پاسخ سخنگو فقط برای مالک اصلی ربات مجاز است.', $messageId);
                return true;
            }
            [$trigger, $response] = Talker::parsePair($tm[2]);
            if ($trigger === '' || $response === '' || !Talker::addReply('contains', $trigger, $response)) {
                $this->client->sendMessage($chatId, "❌ فرمت درست:
افزودن پاسخ شامل ربات | جانم؟ 🤖", $messageId);
                return true;
            }
            $this->client->sendMessage($chatId, "✅ پاسخ شامل سخنگو ذخیره شد.
کلمه: {$trigger}", $messageId);
            return true;
        }

        if (preg_match('/^(حذف پاسخ)\s+(.+)$/u', $clean, $tm)) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ حذف پاسخ سخنگو فقط برای مالک اصلی ربات مجاز است.', $messageId);
                return true;
            }
            $trigger = trim($tm[2]);
            $ok = Talker::deleteReply('exact', $trigger) || Talker::deleteReply('contains', $trigger) || Talker::deleteReply('random', $trigger);
            $this->client->sendMessage($chatId, $ok ? "✅ پاسخ «{$trigger}» حذف شد." : "❌ پاسخی با کلید «{$trigger}» پیدا نشد.", $messageId);
            return true;
        }

        if (preg_match('/^(?:تنظیم\s+)?(?:جریمه\s+هشدار|مجازات\s+هشدار)\s+(فقط\s*حذف|حذف|سکوت|سکوت\s*نرم|اخراج|سیک|بن|هیچ|بدون\s*جریمه|ندارد)$/u', $clean, $pm)) {
            $wanted = preg_replace('/\s+/u', '', $pm[1]) ?: $pm[1];
            $map = [
                'فقطحذف' => 'delete',
                'حذف' => 'delete',
                'سکوت' => 'silence',
                'سکوتنرم' => 'silence',
                'اخراج' => 'kick',
                'سیک' => 'kick',
                'بن' => 'ban',
                'هیچ' => 'none',
                'بدونجریمه' => 'none',
                'ندارد' => 'none',
            ];
            $action = $map[$wanted] ?? 'silence';
            $this->setLockOption($chatId, $group, 'punish_action', $action);
            $name = ['delete'=>'فقط حذف پیام','silence'=>'سکوت نرم','kick'=>'سیک/اخراج کاربر','ban'=>'بن','none'=>'بدون جریمه'][$action];
            $this->client->sendMessage($chatId, "✅ جریمه بعد از رسیدن به سقف اخطار تنظیم شد: {$name}", $messageId);
            return true;
        }

        if (in_array($key, ['punishdelete', 'جریمههشدارحذف', 'جریمههشدارفقطحذف', 'مجازاتهشدارحذف', 'فقطحذف'], true)) {
            $this->setLockOption($chatId, $group, 'punish_action', 'delete');
            $this->client->sendMessage($chatId, '✅ جریمه بعد از سقف اخطار: فقط حذف پیام.', $messageId);
            return true;
        }

        if (in_array($key, ['punishsilence', 'جریمههشدارسکوت', 'مجازاتهشدارسکوت'], true)) {
            $this->setLockOption($chatId, $group, 'punish_action', 'silence');
            $this->client->sendMessage($chatId, '✅ جریمه بعد از سقف اخطار: سکوت نرم.', $messageId);
            return true;
        }

        if (in_array($key, ['punishkick', 'جریمههشدارسیک', 'جریمههشداراخراج', 'مجازاتهشدارسیک', 'مجازاتهشداراخراج'], true)) {
            $this->setLockOption($chatId, $group, 'punish_action', 'kick');
            $this->client->sendMessage($chatId, '✅ جریمه بعد از سقف اخطار: سیک/اخراج از گروه.', $messageId);
            return true;
        }

        if (in_array($key, ['punishban', 'جریمههشداربن', 'مجازاتهشداربن'], true)) {
            $this->setLockOption($chatId, $group, 'punish_action', 'ban');
            $this->client->sendMessage($chatId, '✅ جریمه بعد از سقف اخطار: بن.', $messageId);
            return true;
        }

        if (in_array($key, ['فیلتر', 'filter', 'فیلترگروهی', 'groupfilter'], true)) {
            $this->client->sendMessage($chatId, "دستور اصلی فیلتر همین است:
فیلتر کلمه

مثال:
فیلتر خیار

بعد از این دستور، «خیار» جزو فیلتر همین گروه حساب می‌شود و اگر کاربر عادی آن را بفرستد، پیامش حذف می‌شود.

برای دیدن لیست:
لیست فیلتر
/filter_list

برای حذف:
حذف فیلتر خیار", $messageId);
            return true;
        }

        if (preg_match('/^(فیلتر|filter)\s+(.+)$/u', $clean, $fm)) {
            $wordText = trim($fm[2]);
            $added = $this->addGroupBadWords($chatId, $group, $wordText);
            $this->setLockOption($chatId, $this->db->getGroup($chatId), 'bad_words', true);
            $shownWord = trim($wordText);
            $this->client->sendMessage($chatId, "╭─ 🧹 فیلتر ثبت شد
│ «{$shownWord}» ممنوع شد.
╰─ 🔐 قفل کلمات غیرمجاز روشن شد.", $messageId);
            return true;
        }

        if (preg_match('/^(حذف فیلتر|حذففیلتر|unfilter|remove_filter)\s+(.+)$/u', $clean, $fm)) {
            $word = trim($fm[2]);
            $words = array_values(array_filter($group['bad_words'] ?? [], fn($x) => mb_strtolower(trim((string)$x), 'UTF-8') !== mb_strtolower($word, 'UTF-8')));
            $this->db->updateGroup($chatId, ['bad_words_json' => Support::jsonEncode($words)]);
            $this->client->sendMessage($chatId, "╭─ 🧹 فیلتر حذف شد
╰─ «{$word}» آزاد شد.", $messageId);
            return true;
        }

        if (in_array($key, ['لیستفیلتر', 'فیلترها', 'لیستکلمات', 'filterlist', 'filter_list'], true)) {
            $this->client->sendMessage($chatId, $this->groupBadWordsText($group), $messageId);
            return true;
        }

        if (in_array($key, ['پاکسازیسکوت', 'پاکسازیسکوتها', 'حذفهمهسکوت', 'حذفهمهسکوتها', 'clearmuted', 'clearmutes'], true)) {
            $deleted = $this->db->clearSoftBans($chatId);
            $this->client->sendMessage($chatId, "**✅ لیست سکوت پاک شد.**
━━━━━━━━━━━━
کاربران آزادشده: " . $this->faNum((int)$deleted), $messageId);
            return true;
        }

        if ($this->isSilenceCommand($key, $clean)) {
            $target = $this->extractSilenceTargetFromCommand($clean);
            if ($target === '') {
                $target = $this->senderFromReply($n);
            }

            if ($target === '') {
                $this->client->sendMessage($chatId, "برای سکوت، روی پیام کاربر ریپلای کن و بزن:\nسکوت", $messageId);
                return true;
            }

            if ($this->isOwner($target)) {
                $this->client->sendMessage($chatId, '⛔ مالک اصلی قابل سکوت نیست.', $messageId);
                return true;
            }

            $minutes = $this->extractSilenceMinutes($clean);
            $expiresAt = $minutes > 0 ? time() + ($minutes * 60) : null;
            $this->db->softBan($chatId, $target, 'دستور ادمین', $expiresAt);
            $label = $this->replySenderLabel($n);
            $targetName = $label !== '' && $label !== 'کاربر' ? 'کاربر «' . $label . '»' : 'کاربر موردنظر';
            if ($minutes > 0) {
                $duration = $this->formatMinuteDuration($minutes);
                $this->sendMentionNotice($chatId, 'کاربر', $target, ' ' . $duration . ' سکوت شد', $messageId);
            } else {
                $this->sendMentionNotice($chatId, 'کاربر', $target, ' سکوت شد', $messageId);
            }
            return true;
        }

        if ($this->isUnSilenceCommand($key, $clean)) {
            $target = $this->extractUserIdFromCommand($clean);
            if ($target === '') {
                $target = $this->senderFromReply($n);
            }

            if ($target === '') {
                $this->client->sendMessage($chatId, "برای خروج از سکوت، روی پیام کاربر ریپلای کن و بزن:\nحذف سکوت", $messageId);
                return true;
            }

            $this->db->unSoftBan($chatId, $target);
            $this->db->resetWarning($chatId, $target);
            $this->db->resetScopedWarnings($chatId, $target);
            $this->sendMentionNotice($chatId, 'کاربر', $target, "
از سکوت خارج شد و اخطارها ریست شد.", $messageId);
            return true;
        }

        if ($this->handleLockCommand($chatId, $messageId, $group, $key, $clean)) {
            return true;
        }

        if (preg_match('/^اخطار\s+([a-zA-Z0-9_\-]+)$/u', $clean, $m)) {
            $target = $m[1];
            $this->addManualWarningAndApplyPenalty($chatId, $messageId, $group, $target, 'اخطار دستی');
            return true;
        }

        if (preg_match('/^بخشش\s+([a-zA-Z0-9_\-]+)$/u', $clean, $m)) {
            $this->db->resetWarning($chatId, $m[1]);
            $this->db->resetScopedWarnings($chatId, $m[1]);
            $this->db->unSoftBan($chatId, $m[1]);
            $this->sendMentionNotice($chatId, 'کاربر', $m[1], "
اخطارها و سکوت نرم پاک شد.", $messageId);
            return true;
        }

        if (preg_match('/^حذف دائمی\s+([a-zA-Z0-9_\-]+)$/u', $clean, $m)) {
            $this->db->softBan($chatId, $m[1], 'دستور ادمین');
            $this->sendMentionNotice($chatId, 'کاربر', $m[1], "
در سکوت نرم قرار گرفت.", $messageId);
            return true;
        }

        if (preg_match('/^آنبن\s+([a-zA-Z0-9_\-]+)$/u', $clean, $m)) {
            $this->db->unSoftBan($chatId, $m[1]);
            $this->sendMentionNotice($chatId, 'کاربر', $m[1], "
از سکوت نرم خارج شد.", $messageId);
            return true;
        }

        if (preg_match('/^اعتماد\s+([a-zA-Z0-9_\-]+)$/u', $clean, $m)) {
            $list = $group['trusted_users'];
            if (!in_array($m[1], $list, true)) {
                $list[] = $m[1];
            }
            $this->db->updateGroup($chatId, ['trusted_json' => Support::jsonEncode(array_values($list))]);
            $this->sendMentionNotice($chatId, 'کاربر', $m[1], "
به لیست اعتماد اضافه شد.", $messageId);
            return true;
        }

        if (preg_match('/^حذف اعتماد\s+([a-zA-Z0-9_\-]+)$/u', $clean, $m)) {
            $list = array_values(array_filter($group['trusted_users'], fn($x) => $x !== $m[1]));
            $this->db->updateGroup($chatId, ['trusted_json' => Support::jsonEncode($list)]);
            $this->sendMentionNotice($chatId, 'کاربر', $m[1], "
از لیست اعتماد حذف شد.", $messageId);
            return true;
        }

        if (preg_match('/^کلمه ممنوعه\s+(.+)$/u', $clean, $m)) {
            $words = $group['bad_words'];
            $word = trim($m[1]);
            if ($word !== '' && !in_array($word, $words, true)) {
                $words[] = $word;
            }
            $this->db->updateGroup($chatId, ['bad_words_json' => Support::jsonEncode(array_values($words))]);
            $this->client->sendMessage($chatId, "✅ کلمه ممنوعه اضافه شد:\n{$word}", $messageId);
            return true;
        }

        if (preg_match('/^حذف کلمه\s+(.+)$/u', $clean, $m)) {
            $word = trim($m[1]);
            $words = array_values(array_filter($group['bad_words'], fn($x) => $x !== $word));
            $this->db->updateGroup($chatId, ['bad_words_json' => Support::jsonEncode($words)]);
            $this->client->sendMessage($chatId, "✅ کلمه حذف شد:\n{$word}", $messageId);
            return true;
        }

        if (in_array($key, ['لیستسکوت', 'سکوتها', 'سکوتیها', 'muted', 'mutedlist'], true)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'لیست سکوت هر گروه از پنل همان گروه در «📋 گروه‌های من» قابل مشاهده است.', $messageId);
                return true;
            }
            $rows = [];
            $mentions = [];
            $users = $this->softMutedUsers($chatId, 15);
            $i = 1;
            foreach ($users as $u) {
                $labelUser = 'کاربر ' . $i;
                $rows[] = $labelUser;
                $uid = (string)($u['user_id'] ?? '');
                if ($uid !== '') $mentions[] = ['label' => $labelUser, 'user_id' => $uid];
                $i++;
            }
            $txt = $users ? ("🔇 کاربران سکوت‌شده:
" . implode("
", $rows) . "

برای خروج از سکوت، در پیوی از پنل گروه استفاده کن.") : '✅ کاربری در سکوت نرم نیست.';
            $this->client->sendMessage($chatId, $txt, $messageId, null, null, MetadataBuilder::mentions($txt, $mentions) ?: null);
            return true;
        }

        if (in_array($key, ['resetdb', 'databaseclear', 'cleardatabase', 'ریستدیتابیس', 'ریستدیتابیسربات', 'پاکسازیدیتابیس', 'پاککردندیتابیس'], true)
            || preg_match('/^(ریست|پاکسازی|پاک کردن)\s+(?:کامل\s+)?دیتابیس/u', $clean)) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات می‌تواند دیتابیس را ریست کند.', $messageId);
                return true;
            }
            if ($this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, '⚠️ برای امنیت، ریست دیتابیس فقط از پیوی ربات و پنل اصلی مالک انجام می‌شود.', $messageId);
                return true;
            }
            $this->askDatabaseResetConfirmation($senderId, $chatId, $messageId);
            return true;
        }


        if (in_array($key, ['exportsettings', 'exportgroupsettings', 'خروجیتنظیمات', 'بکاپتنظیمات', 'ذخیرهتنظیمات'], true)
            || preg_match('/^(?:خروجی|بکاپ|ذخیره)\s+تنظیمات/u', $clean)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'این دستور را داخل گروه اجرا کن تا تنظیمات همان گروه خروجی گرفته شود.', $messageId);
                return true;
            }
            if (!$this->isPrivileged($senderId, $group)) {
                $this->client->sendMessage($chatId, '⛔ دسترسی خروجی تنظیمات این گروه را نداری.', $messageId);
                return true;
            }
            $path = $this->db->saveGroupSettingsExport($chatId);
            $data = $this->db->exportGroupSettings($chatId);
            $json = Support::jsonEncode($data);
            $textOut = "✅ خروجی تنظیمات ساخته شد.
مسیر روی هاست:
{$path}

برای ورود روی گروه دیگر، دستور «ورود تنظیمات» را بزن و این JSON را در پیوی ربات بفرست:

" . $json;
            if (mb_strlen($textOut, 'UTF-8') > 3500) {
                $textOut = "✅ خروجی تنظیمات ساخته شد.
مسیر روی هاست:
{$path}

JSON طولانی است؛ فایل ساخته‌شده را از پوشه backups بردار و برای ورود تنظیمات استفاده کن.";
            }
            $this->client->sendMessage($chatId, $textOut, $messageId);
            return true;
        }

        if (in_array($key, ['importsettings', 'importgroupsettings', 'ورودتنظیمات', 'ایمپورتتنظیمات', 'ورودبکاپتنظیمات'], true)
            || preg_match('/^(?:ورود|ایمپورت)\s+تنظیمات(?:\s+(.+))?$/us', $clean, $im)) {
            if (!$this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, 'این دستور را داخل گروه مقصد اجرا کن.', $messageId);
                return true;
            }
            if (!$this->isPrivileged($senderId, $group)) {
                $this->client->sendMessage($chatId, '⛔ دسترسی ورود تنظیمات این گروه را نداری.', $messageId);
                return true;
            }
            $inlineJson = trim((string)($im[1] ?? ''));
            if ($inlineJson !== '') {
                $data = Support::jsonDecode($inlineJson, []);
                if (!is_array($data) || empty($data['locks'])) {
                    $this->client->sendMessage($chatId, '❌ JSON تنظیمات معتبر نیست.', $messageId);
                    return true;
                }
                $this->db->importGroupSettings($chatId, $data, true);
                $this->client->sendMessage($chatId, '✅ تنظیمات روی همین گروه اعمال شد.', $messageId);
                return true;
            }
            $this->askNextMessage($senderId, $chatId, 'group_settings_import', $chatId, $messageId);
            $this->client->sendMessage($chatId, "✅ حالا JSON خروجی تنظیمات را در پیوی ربات بفرست.
اگر منصرف شدی، در پیوی بنویس: لغو", $messageId);
            return true;
        }

        if (in_array($key, ['backup', 'بکاپ'], true)) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ بکاپ کامل دیتابیس فقط برای مالک اصلی ربات مجاز است.', $messageId);
                return true;
            }
            if ($this->isGroupChat($chatId)) {
                $this->client->sendMessage($chatId, '⚠️ برای جلوگیری از نمایش مسیر بکاپ، این دستور را در پیوی ربات اجرا کن.', $messageId);
                return true;
            }
            $path = $this->db->backup();
            $this->client->sendMessage($chatId, "✅ بکاپ ساخته شد:\n{$path}", $messageId);
            return true;
        }

        return false;
    }


    private function addManualWarningAndApplyPenalty(string $chatId, string $messageId, array $group, string $target, string $reasonText): void
    {
        $warnings = $this->db->addWarning($chatId, $target);
        $max = max(1, (int)($group['max_warnings'] ?? 3));
        $punishAction = $this->punishAction($group);
        $suffix = '';

        $limitReached = false;
        if ($warnings >= $max) {
            $limitReached = true;
            if ($punishAction === 'silence') {
                $this->db->softBan($chatId, $target, 'سکوت خودکار پس از رسیدن به سقف اخطار دستی');
                $suffix = "
⛔ سقف اخطار تکمیل شد؛ کاربر در سکوت نرم قرار گرفت.";
            } elseif ($punishAction === 'kick') {
                $this->db->softBan($chatId, $target, 'سیک/اخراج خودکار پس از رسیدن به سقف اخطار دستی');
                $res = $this->client->experimentalKick($chatId, $target);
                Support::log($this->config, 'manual_warning_kick_result', ['chat_id'=>$chatId, 'user_id'=>$target, 'response'=>$res]);
                $apiOk = (($res['status'] ?? '') === 'OK' || ($res['ok'] ?? false));
                $suffix = $apiOk ? "\n🚪 سقف اخطار تکمیل شد؛ کاربر اخراج شد." : "\n⚠️ اخراج واقعی API انجام نشد؛ محدودیت داخلی فعال شد.";
            } elseif ($punishAction === 'ban') {
                $this->db->softBan($chatId, $target, 'بن خودکار پس از رسیدن به سقف اخطار دستی');
                $res = $this->client->experimentalHardBan($chatId, $target);
                Support::log($this->config, 'manual_warning_ban_result', ['chat_id'=>$chatId, 'user_id'=>$target, 'response'=>$res]);
                $apiOk = (($res['status'] ?? '') === 'OK' || ($res['ok'] ?? false));
                $suffix = $apiOk ? "\n⛔ سقف اخطار تکمیل شد؛ کاربر بن شد." : "\n⚠️ بن واقعی API انجام نشد؛ محدودیت داخلی فعال شد.";
            } elseif ($punishAction === 'delete') {
                $suffix = "
ℹ️ سقف اخطار تکمیل شد؛ تنظیم فعلی فقط حذف پیام‌های خلاف قوانین است.";
            }
        }

        [$warningText, $warningMetadata] = MessageFormatter::warningNotice($reasonText, $warnings, $max, $target, $suffix);
        $this->client->sendMessage($chatId, $warningText, null, null, null, $warningMetadata ?: null);

        if ($limitReached) {
            $this->db->resetWarning($chatId, $target);
        }
    }

    private function handleButton(array $n, string $buttonId): void
    {
        $buttonId = $this->resolveButtonAlias($buttonId);

        $senderId = (string)$n['sender_id'];
        $chatId = (string)$n['chat_id'];
        $messageId = (string)$n['message_id'];

        // بعضی کلیک‌های شیشه‌ای روبیکا sender_id را خالی می‌فرستند؛ اگر user_id واقعی در raw بود همان را نگه می‌داریم.
        if ($chatId !== '' && !$this->isGroupChat($chatId)) {
            $senderId = $this->resolvePrivateSenderId($n);
            $n['sender_id'] = $senderId;
            $this->db->registerPrivateChat($chatId, $senderId);
        }

        if (preg_match('/^pg:([a-f0-9]{10}):([a-z0-9]+)$/i', $buttonId, $pm)) {
            $targetChatId = (string)$this->db->getState('panel_group:' . strtolower($pm[1]), '');
            if ($targetChatId === '') {
                $this->client->sendMessage($chatId, '⚠️ این دکمه منقضی شده است. لطفاً پنل را دوباره باز کن.', $messageId ?: null);
                return;
            }
            $targetGroup = $this->db->getGroup($targetChatId);
            if (!$this->isPrivileged($senderId, $targetGroup)) {
                $this->client->sendMessage($chatId, '⛔ دسترسی مدیریت پنل را نداری.', $messageId ?: null);
                return;
            }
            $this->editPanelPageToChat($chatId, $targetChatId, $this->expandShortPanelPage($pm[2]), $messageId ?: null, $senderId);
            return;
        }

        if (str_starts_with($buttonId, 'help:cmd:')) {
            $cmd = $this->helpCommandFromButton($buttonId);
            if ($cmd !== '') {
                $this->client->sendMessage($chatId, $this->copyCommandText($cmd), $messageId ?: null);
            }
            return;
        }

        if ($buttonId === 'ui:groups' || $buttonId === 'kb:groups') {
            $this->sendGroupsList($chatId, $senderId, $messageId ?: null);
            return;
        }

        if ($buttonId === 'kb:add_group') {
            $this->sendAddGroupHelp($chatId, $messageId ?: null);
            return;
        }

        if ($buttonId === 'kb:install_stats') {
            $this->sendInstallStatsAd($chatId, $messageId ?: null);
            return;
        }

        if ($buttonId === 'ui:owner' || $buttonId === 'kb:owner') {
            if ($this->isOwner($senderId)) {
                $this->editOwnerPanelToChat($chatId, $messageId ?: null);
            } else {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات به این بخش دسترسی دارد.', $messageId ?: null);
            }
            return;
        }

        if ($buttonId === 'kb:global_joined' || $buttonId === 'ui:global_joined') {
            $this->markGlobalForceJoinVerified($senderId);
            $this->client->sendMessage($chatId, "✅ عضویت شما در جوین اجباری ربات ثبت شد.
اکنون می‌توانید از ربات استفاده کنید.", $messageId ?: null);
            $this->sendPrivateStart($chatId, $senderId, null);
            return;
        }

        if (str_starts_with($buttonId, 'ui:owner:forcejoin:')) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات به جوین اجباری ربات دسترسی دارد.', $messageId ?: null);
                return;
            }

            $action = substr($buttonId, strlen('ui:owner:forcejoin:'));

            if ($action === 'toggle') {
                $this->setGlobalForceJoinEnabled(!$this->globalForceJoinEnabledRaw());
                $this->editOwnerPanelToChat($chatId, $messageId ?: null);
                return;
            }

            if ($action === 'add') {
                $this->askNextMessage($senderId, $chatId, 'global_forcejoin_add', null, $messageId ?: null);
                return;
            }

            if ($action === 'list') {
                $this->client->sendMessage($chatId, $this->globalForceJoinListText(), $messageId ?: null);
                return;
            }

            if ($action === 'clear') {
                $this->setGlobalForceJoinChannels([]);
                $this->editOwnerPanelToChat($chatId, $messageId ?: null);
                return;
            }
        }

        if (str_starts_with($buttonId, 'ui:owner:badwords:')) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات به فیلتر کلمات سراسری دسترسی دارد.', $messageId ?: null);
                return;
            }

            $action = substr($buttonId, strlen('ui:owner:badwords:'));

            if ($action === 'toggle') {
                $this->setGlobalBadWordsEnabled(!$this->globalBadWordsEnabledRaw());
                $this->editOwnerPanelToChat($chatId, $messageId ?: null);
                return;
            }

            if ($action === 'add') {
                $this->askNextMessage($senderId, $chatId, 'global_badword_add', null, $messageId ?: null);
                return;
            }

            if ($action === 'list') {
                $this->client->sendMessage($chatId, $this->globalBadWordsText(), $messageId ?: null);
                return;
            }

            if ($action === 'clear') {
                $this->setGlobalBadWords([]);
                $this->editOwnerPanelToChat($chatId, $messageId ?: null);
                return;
            }
        }

        if (str_starts_with($buttonId, 'ui:owner:botname:')) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات می‌تواند نام نمایشی را تنظیم کند.', $messageId ?: null);
                return;
            }

            $action = substr($buttonId, strlen('ui:owner:botname:'));

            if ($action === 'set') {
                $this->askNextMessage($senderId, $chatId, 'bot_name_set', null, $messageId ?: null);
                return;
            }

            if ($action === 'show') {
                $this->client->sendMessage($chatId, "🏷 نام نمایشی فعلی:
" . $this->botDisplayName(), $messageId ?: null);
                return;
            }
        }

        if (str_starts_with($buttonId, 'ui:owner:support:')) {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات می‌تواند پشتیبانی را تنظیم کند.', $messageId ?: null);
                return;
            }

            $action = substr($buttonId, strlen('ui:owner:support:'));

            if ($action === 'set') {
                $this->askNextMessage($senderId, $chatId, 'support_set', null, $messageId ?: null);
                return;
            }

            if ($action === 'show') {
                $this->client->sendMessage($chatId, "🛟 متن فعلی پشتیبانی:\n\n" . $this->supportText(), $messageId ?: null);
                return;
            }
        }

        if ($buttonId === 'ui:owner:stats') {
            if ($this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, $this->ownerStatsText(), $messageId ?: null);
            }
            return;
        }

        if ($buttonId === 'ui:owner:broadcast') {
            if ($this->isOwner($senderId)) {
                $this->askNextMessage($senderId, $chatId, 'all', null, $messageId ?: null);
            }
            return;
        }

        if ($buttonId === 'ui:owner:broadcast_status') {
            if ($this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, $this->latestBroadcastStatusText(), $messageId ?: null);
            }
            return;
        }

        if ($buttonId === 'ui:owner:backup') {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات می‌تواند بکاپ بگیرد.', $messageId ?: null);
                return;
            }
            $path = $this->db->backup();
            $this->client->sendMessage($chatId, "✅ بکاپ دیتابیس ساخته شد.
مسیر فایل روی هاست:
{$path}", $messageId ?: null);
            return;
        }

        if ($buttonId === 'ui:owner:resetdb') {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات می‌تواند دیتابیس را ریست کند.', $messageId ?: null);
                return;
            }
            $this->askDatabaseResetConfirmation($senderId, $chatId, $messageId ?: null);
            return;
        }

        if (preg_match('/^ui:(?:group|g):([a-zA-Z0-9_\-]+)(?::([a-zA-Z0-9_\-]+))?$/', $buttonId, $m)) {
            if ($senderId === '' && !empty($m[2])) {
                $senderId = $m[2];
            }
            if (!$this->canUseBot($senderId)) {
                $this->sendGlobalForceJoinNotice($chatId, $messageId ?: null);
                return;
            }
            $target = $m[1];
            $g = $this->db->getGroup($target);
            Support::log($this->config, 'open_group_panel_from_private', [
                'private_chat_id' => $chatId,
                'target_chat_id' => $target,
                'sender_id' => $senderId,
                'button_id' => $buttonId,
            ]);
            if ($this->isPrivileged($senderId, $g)) {
                // روی نام گروه که زده شد، پنل باید پیام جدید بدهد؛ از این به بعد همان پنل ویرایش می‌شود.
                $this->sendPanelToChat($chatId, $target, null, $senderId);
            } else {
                $this->client->sendMessage($chatId, '⛔ دسترسی مدیریت این گروه را نداری.', $messageId ?: null);
            }
            return;
        }

        if (!str_starts_with($buttonId, 'panel:')) {
            Support::log($this->config, 'unhandled_button', [
                'chat_id' => $chatId,
                'sender_id' => $senderId,
                'button_id' => $buttonId,
                'message_id' => $messageId,
            ]);
            return;
        }

        $parts = explode(':', $buttonId);
        if (count($parts) < 4) {
            return;
        }

        $targetChatId = $parts[1];
        $action = $parts[2];
        $key = $parts[3];
        $page = $this->normalizePanelPage((string)($parts[4] ?? 'main'));
        $group = $this->db->getGroup($targetChatId);

        if (!$this->isPrivileged($senderId, $group)) {
            $this->client->sendMessage($chatId, '⛔ دسترسی مدیریت پنل را نداری.', $messageId ?: null);
            return;
        }

        if ($action === 'muted' && $key === 'list') {
            $this->editMutedUsersToChat($chatId, $targetChatId, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'muted' && $key === 'clear') {
            $this->db->clearSoftBans($targetChatId);
            $this->editMutedUsersToChat($chatId, $targetChatId, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'unmute') {
            $targetUser = trim($key);
            if ($targetUser !== '') {
                $this->db->unSoftBan($targetChatId, $targetUser);
                $this->db->resetWarning($targetChatId, $targetUser);
                $this->db->resetScopedWarnings($targetChatId, $targetUser);
            }
            $this->editMutedUsersToChat($chatId, $targetChatId, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'admins' && $key === 'list') {
            $this->editAdminListToChat($chatId, $targetChatId, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'report' && $key === 'violations') {
            [$txt, $metadata] = $this->violationReportPayload($targetChatId);
            $this->client->sendMessage($chatId, $txt, $messageId ?: null, null, null, $metadata ?: null);
            return;
        }

        if ($action === 'back' && $key === 'panel') {
            $this->editPanelToChat($chatId, $targetChatId, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'page') {
            $this->editPanelPageToChat($chatId, $targetChatId, $key, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'noop') {
            $this->editPanelPageToChat($chatId, $targetChatId, $page, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'toggleall') {
            $locks = $group['locks'] ?? [];
            $enableAll = $key === 'on';
            foreach (array_keys($this->lockDefinitions()) as $lk) {
                $locks[$lk] = $enableAll;
            }
            $this->db->updateGroup($targetChatId, ['locks_json' => Support::jsonEncode($locks)]);
            $this->editPanelPageToChat($chatId, $targetChatId, $page, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'talker') {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ افزودن، حذف یا مشاهده کلیدهای سخنگو فقط برای مالک اصلی ربات مجاز است. ادمین‌های گروه فقط می‌توانند سخنگو را روشن/خاموش کنند.', $messageId ?: null);
                return;
            }
            if ($key === 'list') {
                $this->client->sendMessage($chatId, Talker::listText(), $messageId ?: null);
                return;
            }
            if ($key === 'add_exact') {
                $this->askNextMessage($senderId, $chatId, 'talker_add_exact', $targetChatId, $messageId ?: null);
                return;
            }
            if ($key === 'add_contains') {
                $this->askNextMessage($senderId, $chatId, 'talker_add_contains', $targetChatId, $messageId ?: null);
                return;
            }
            if ($key === 'delete') {
                $this->askNextMessage($senderId, $chatId, 'talker_delete', $targetChatId, $messageId ?: null);
                return;
            }
        }

        if ($action === 'flood') {
            if (preg_match('/^max(\d+)$/', $key, $fm)) {
                $flood = $group['flood'] ?? ['max_messages' => 5, 'seconds' => 8];
                $flood['max_messages'] = max(2, min(30, (int)$fm[1]));
                $flood['seconds'] = max(3, (int)($flood['seconds'] ?? 8));
                $this->db->updateGroup($targetChatId, ['flood_json' => Support::jsonEncode($flood)]);
                $this->editPanelPageToChat($chatId, $targetChatId, $page, $messageId ?: null, $senderId);
                return;
            }
        }

        if ($action === 'toggle') {
            if ($key === 'enabled') {
                $this->db->updateGroup($targetChatId, ['enabled' => !empty($group['enabled']) ? 0 : 1]);
            } else {
                $locks = $group['locks'];
                if ($key === 'talker_enabled') {
                    $locks[$key] = !$this->talkerEnabled($group);
                } else {
                    $locks[$key] = empty($locks[$key]);
                }
                $this->db->updateGroup($targetChatId, ['locks_json' => Support::jsonEncode($locks)]);
            }
            $this->editPanelPageToChat($chatId, $targetChatId, $page, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'warnlock') {
            if (array_key_exists($key, $this->lockDefinitions())) {
                $current = $this->lockWarningLimit($group, $key);
                $next = $this->nextLockWarningLimit($current);
                $this->setLockWarningLimit($targetChatId, $group, $key, $next);
            }
            $this->editPanelPageToChat($chatId, $targetChatId, $page, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'lockpunish') {
            if (array_key_exists($key, $this->lockDefinitions())) {
                $current = $this->lockPunishAction($group, $key);
                $next = $this->nextLockPunishAction($current);
                $this->setLockPunishAction($targetChatId, $group, $key, $next);
            }
            $this->editPanelPageToChat($chatId, $targetChatId, $page, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'warn') {
            if ($key === 'toggle') {
                $this->setLockOption($targetChatId, $group, 'warnings_enabled', !$this->warningEnabled($group));
            } elseif (preg_match('/^limit(\d+)$/', $key, $lm)) {
                $this->db->updateGroup($targetChatId, ['max_warnings' => max(1, min(20, (int)$lm[1]))]);
            }
            $this->editPanelPageToChat($chatId, $targetChatId, $page, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'punish') {
            if (in_array($key, ['delete', 'silence', 'kick', 'ban', 'none'], true)) {
                $this->setLockOption($targetChatId, $group, 'punish_action', $key);
            }
            $this->editPanelPageToChat($chatId, $targetChatId, $page, $messageId ?: null, $senderId);
            return;
        }

        if ($action === 'badword') {
            if ($key === 'add') {
                $this->askNextMessage($senderId, $chatId, 'group_badword_add', $targetChatId, $messageId ?: null);
                return;
            }

            if ($key === 'list') {
                $this->client->sendMessage($chatId, $this->groupBadWordsText($group), $messageId ?: null);
                return;
            }

            if ($key === 'clear') {
                $this->db->updateGroup($targetChatId, ['bad_words_json' => Support::jsonEncode([])]);
                $this->editPanelPageToChat($chatId, $targetChatId, $page, $messageId ?: null, $senderId);
                return;
            }
        }

        if ($action === 'settings_export') {
            $path = $this->db->saveGroupSettingsExport($targetChatId);
            $data = $this->db->exportGroupSettings($targetChatId);
            $json = Support::jsonEncode($data);
            $out = "✅ خروجی تنظیمات ساخته شد.
مسیر روی هاست:
{$path}

برای ورود روی گروه دیگر، دکمه «📥 ورود تنظیمات» را بزن و JSON را در پیوی بفرست.

" . $json;
            if (mb_strlen($out, 'UTF-8') > 3500) {
                $out = "✅ خروجی تنظیمات ساخته شد.
مسیر روی هاست:
{$path}

JSON طولانی است؛ فایل ساخته‌شده در پوشه backups ذخیره شد.";
            }
            $this->client->sendMessage($chatId, $out, $messageId ?: null);
            return;
        }

        if ($action === 'settings_import') {
            $this->askNextMessage($senderId, $chatId, 'group_settings_import', $targetChatId, $messageId ?: null);
            $this->client->sendMessage($chatId, "✅ JSON خروجی تنظیمات را در پیوی ربات بفرست.
اگر منصرف شدی، بنویس: لغو", $messageId ?: null);
            return;
        }

        if ($action === 'sendmsg') {
            $this->client->sendMessage($chatId, "⛔ ارسال پیام مستقیم به گروه برای ادمین‌های گروه حذف شده است.
فقط مالک اصلی می‌تواند از پنل اصلی، پیام همگانی صفی ارسال کند.", $messageId ?: null);
            return;
        }

        if ($action === 'delete' && $key === 'group') {
            $this->client->sendMessage($chatId, '⛔ حذف گروه از پنل داخل ربات غیرفعال شده است.', $messageId ?: null);
            return;
        }

        if ($action === 'forcejoin') {
            $this->client->sendMessage($chatId, '🔐 جوین اجباری از این نسخه فقط از پنل اصلی مالک ربات تنظیم می‌شود، نه پنل گروه‌ها.', $messageId ?: null);
            return;
        }
    }

    private function handlePendingMessage(array $n): bool
    {
        $chatId = (string)$n['chat_id'];
        $senderId = (string)$n['sender_id'];
        $messageId = (string)$n['message_id'];
        $text = trim((string)$n['text']);

        if ($this->isGroupChat($chatId) || $senderId === '') {
            return false;
        }

        $stateKey = 'pending_message:' . $senderId;
        $raw = $this->db->getState($stateKey);
        if (!$raw) {
            return false;
        }

        $pending = Support::jsonDecode($raw, []);
        if (!is_array($pending) || empty($pending['mode'])) {
            return false;
        }

        if ($this->isCommand($text)) {
            return false;
        }

        if ((int)($pending['expires_at'] ?? 0) < time()) {
            $this->db->setState($stateKey, '');
            return false;
        }

        if ($pending['mode'] === 'reset_database_confirm') {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات می‌تواند دیتابیس را ریست کند.', $messageId);
                $this->db->setState($stateKey, '');
                return true;
            }

            $cleanConfirm = $this->commandKey($this->cleanCommand($text));
            if (in_array($cleanConfirm, ['لغو', 'cancel'], true)) {
                $this->db->setState($stateKey, '');
                $this->client->sendMessage($chatId, '✅ ریست دیتابیس لغو شد.', $messageId);
                return true;
            }

            if (!in_array($cleanConfirm, ['تاییدریستدیتابیس', 'تاییدپاکسازیدیتابیس', 'confirmresetdatabase'], true)) {
                $this->db->setState($stateKey, '');
                $this->client->sendMessage($chatId, "❌ عبارت تایید درست نبود و ریست انجام نشد.
برای شروع دوباره از پنل اصلی مالک دکمه «🧨 ریست دیتابیس» را بزن.", $messageId);
                return true;
            }

            $this->performDatabaseReset($chatId, $messageId);
            return true;
        }

        if ($pending['mode'] === 'group_settings_import') {
            $target = (string)($pending['target'] ?? '');
            if ($target === '') {
                $this->db->setState($stateKey, '');
                $this->client->sendMessage($chatId, '❌ گروه مقصد پیدا نشد. دوباره از داخل گروه دستور ورود تنظیمات را بزن.', $messageId);
                return true;
            }
            $g = $this->db->getGroup($target);
            if (!$this->isPrivileged($senderId, $g)) {
                $this->db->setState($stateKey, '');
                $this->client->sendMessage($chatId, '⛔ دسترسی ورود تنظیمات این گروه را نداری.', $messageId);
                return true;
            }
            $data = Support::jsonDecode($text, []);
            if (!is_array($data) || empty($data['locks'])) {
                $this->client->sendMessage($chatId, "❌ متن تنظیمات معتبر نیست.
باید JSON خروجی دستور «خروجی تنظیمات» را کامل بفرستی.", $messageId);
                return true;
            }
            $this->db->importGroupSettings($target, $data, true);
            $this->db->setState($stateKey, '');
            $this->client->sendMessage($chatId, '✅ تنظیمات روی گروه اعمال شد.', $messageId);
            return true;
        }

        if ($pending['mode'] === 'all') {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات می‌تواند پیام همگانی بفرستد.', $messageId);
                return true;
            }
            $job = $this->queueBroadcastToAllTargets($text, $senderId);
            if (($job['total'] ?? 0) <= 0) {
                $this->client->sendMessage($chatId, '❌ مقصدی برای پیام همگانی پیدا نشد.', $messageId);
            } else {
                $this->client->sendMessage($chatId, "✅ پیام همگانی در صف کرون ثبت شد.
شناسه صف: #{$job['job_id']}
تعداد مقصد: {$job['total']}

ارسال مرحله‌ای با cron_broadcast.php انجام می‌شود.", $messageId);
            }
        } elseif ($pending['mode'] === 'group') {
            $this->client->sendMessage($chatId, "⛔ ارسال پیام مستقیم به گروه حذف شده است.
فقط مالک اصلی ربات می‌تواند از «پیام همگانی» استفاده کند.", $messageId);
        } elseif ($pending['mode'] === 'bot_name_set') {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات می‌تواند نام نمایشی را تنظیم کند.', $messageId);
                return true;
            }

            $this->setBotDisplayName($text);
            $this->client->sendMessage($chatId, "✅ نام ربات ذخیره شد:
" . $this->botDisplayName(), $messageId);
            $this->editOwnerPanelToChat($chatId, $messageId ?: null);

        } elseif ($pending['mode'] === 'support_set') {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات می‌تواند پشتیبانی را تنظیم کند.', $messageId);
                return true;
            }

            $this->setSupportText($text);
            $this->client->sendMessage($chatId, "✅ متن پشتیبانی ذخیره شد.\n\n" . $this->supportText(), $messageId);
            $this->editOwnerPanelToChat($chatId, $messageId ?: null);

        } elseif ($pending['mode'] === 'global_forcejoin_add') {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات می‌تواند جوین اجباری ربات را تغییر دهد.', $messageId);
                return true;
            }

            $added = $this->addGlobalForceJoinChannels($text);
            $this->client->sendMessage($chatId, "✅ {$added} مورد به جوین اجباری ربات اضافه شد.

" . $this->globalForceJoinListText(), $messageId);

        } elseif ($pending['mode'] === 'global_badword_add') {
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات می‌تواند فیلتر کلمات سراسری را تغییر دهد.', $messageId);
                return true;
            }

            $added = $this->addGlobalBadWords($text);
            $this->client->sendMessage($chatId, "✅ {$added} کلمه به فیلتر سراسری اضافه شد.\n\n" . $this->globalBadWordsText(), $messageId);

        } elseif ($pending['mode'] === 'group_badword_add') {
            $target = (string)($pending['target'] ?? '');
            $g = $this->db->getGroup($target);
            if (!$this->isPrivileged($senderId, $g)) {
                $this->client->sendMessage($chatId, '⛔ دسترسی تغییر فیلتر کلمات این گروه را نداری.', $messageId);
                return true;
            }

            $added = $this->addGroupBadWords($target, $g, $text);
            $this->client->sendMessage($chatId, "✅ {$added} کلمه به فیلتر گروهی اضافه شد.\n\n" . $this->groupBadWordsText($this->db->getGroup($target)), $messageId);

        } elseif (in_array($pending['mode'], ['talker_add_exact', 'talker_add_contains'], true)) {
            $target = (string)($pending['target'] ?? '');
            $g = $this->db->getGroup($target);
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ افزودن کلمات/پاسخ‌های سخنگو فقط برای مالک اصلی ربات مجاز است.', $messageId);
                return true;
            }
            [$trigger, $response] = Talker::parsePair($text);
            $section = $pending['mode'] === 'talker_add_contains' ? 'contains' : 'exact';
            if ($trigger === '' || $response === '' || !Talker::addReply($section, $trigger, $response)) {
                $this->client->sendMessage($chatId, "❌ فرمت اشتباه است.
مثال:
سلام | سلام عزیزم 🌹", $messageId);
                return true;
            }
            $this->client->sendMessage($chatId, "✅ پاسخ سخنگو ذخیره شد.
کلمه: {$trigger}
نوع: " . ($section === 'contains' ? 'شامل عبارت' : 'دقیق'), $messageId);

        } elseif ($pending['mode'] === 'talker_delete') {
            $target = (string)($pending['target'] ?? '');
            $g = $this->db->getGroup($target);
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ حذف پاسخ‌های سخنگو فقط برای مالک اصلی ربات مجاز است.', $messageId);
                return true;
            }
            $trigger = trim($text);
            $ok = Talker::deleteReply('exact', $trigger) || Talker::deleteReply('contains', $trigger) || Talker::deleteReply('random', $trigger);
            $this->client->sendMessage($chatId, $ok ? "✅ پاسخ «{$trigger}» حذف شد." : "❌ پاسخی با کلید «{$trigger}» پیدا نشد.", $messageId);

        } elseif ($pending['mode'] === 'forcejoin_add') {
            $target = (string)($pending['target'] ?? '');
            $g = $this->db->getGroup($target);
            if (!$this->isOwner($senderId)) {
                $this->client->sendMessage($chatId, '⛔ افزودن جوین اجباری فقط برای مالک اصلی ربات مجاز است.', $messageId);
                return true;
            }

            $added = $this->addForceJoinChannels($target, $g, $text);
            $this->client->sendMessage($chatId, "✅ {$added} مورد به جوین اجباری اضافه شد.

" . $this->forceJoinListText($this->db->getGroup($target)), $messageId);
        }

        $this->db->setState($stateKey, '');
        return true;
    }

    private function askNextMessage(string $senderId, string $privateChatId, string $mode, ?string $target, ?string $replyToMessageId = null): void
    {
        $this->db->setState('pending_message:' . $senderId, Support::jsonEncode([
            'mode' => $mode,
            'target' => $target,
            'expires_at' => time() + 600,
        ]));

        if ($mode === 'reset_database_confirm') {
            $txt = "🧨 ریست کامل دیتابیس

این عملیات همه داده‌های داخل دیتابیس را پاک می‌کند:
• همه گروه‌ها و پنل‌های گروه
• ادمین‌های داخلی گروه‌ها
• اخطارها، سکوت‌ها و تخلف‌ها
• آمار پیام‌ها
• پیوی‌های ثبت‌شده
• تنظیمات سراسری ذخیره‌شده در دیتابیس

قبل از حذف، بکاپ خودکار ساخته می‌شود.

برای تایید دقیقاً این متن را بفرست:
تایید ریست دیتابیس

برای لغو بفرست:
لغو";
        } elseif ($mode === 'all') {
            $txt = "📣 متن پیام همگانی را بفرست.
این پیام برای همه گروه‌های نصب‌شده و پیوی‌های ثبت‌شده صف می‌شود و با cron_broadcast.php مرحله‌ای ارسال می‌گردد.

برای هایپرلینک دقیقاً از این قالب استفاده کن:
`[متن قابل کلیک](https://rubika.ir/YourChannel)`

مثال پیام قابل ارسال:
`همگانی برای دیدن کانال روی [اینجا کلیک کنید](https://rubika.ir/MyChannel)`

نکته: متن نمونه بالا عمداً به صورت کد نمایش داده می‌شود تا لینک به شکل قابل کلیک تبدیل نشود و بتوانی فرمت خام را ببینی. ارسال واقعی بعد از اجرای cron_broadcast.php انجام می‌شود.";
        } elseif ($mode === 'forcejoin_add') {
            $txt = "➕ افزودن جوین اجباری

هر کانال/گروه را در یک خط بفرست.
فرمت پیشنهادی:
عنوان | لینک

مثال:
کانال اطلاع‌رسانی | https://rubika.ir/MyChannel
گروه پشتیبانی | https://rubika.ir/joing/XXXX

گروه هدف:
{$target}";
        } elseif ($mode === 'bot_name_set') {
            $txt = "🏷 تنظیم نام نمایشی ربات

نامی را بفرست که داخل پیام نصب و پنل نمایش داده شود.
مثال:
گاردی بات";
        } elseif ($mode === 'support_set') {
            $txt = "🛟 تنظیم پشتیبانی

متن پشتیبانی را بفرست.
مثال:
پشتیبانی: @LoopSTL

این متن در /start و راهنمای افزودن ربات نمایش داده می‌شود.";
        } elseif ($mode === 'global_badword_add') {
            $txt = "➕ افزودن کلمات فیلتر سراسری

هر کلمه یا عبارت را در یک خط بفرست.
این فیلتر روی همه گروه‌ها اعمال می‌شود.";
        } elseif ($mode === 'group_badword_add') {
            $txt = "➕ افزودن کلمات فیلتر گروهی

هر کلمه یا عبارت را در یک خط بفرست.
این فیلتر فقط روی همین گروه اعمال می‌شود.

گروه هدف:
{$target}";
        } elseif ($mode === 'talker_add_exact') {
            $txt = "➕ افزودن پاسخ دقیق سخنگو

فرمت را اینطوری بفرست:
کلمه | پاسخ

مثال:
سلام | سلام عزیزم 🌹";
        } elseif ($mode === 'talker_add_contains') {
            $txt = "➕ افزودن پاسخ شامل عبارت

وقتی متن کاربر شامل این کلمه باشد، ربات جواب می‌دهد.
فرمت:
کلمه | پاسخ

مثال:
ربات | جانم؟ 🤖";
        } elseif ($mode === 'talker_delete') {
            $txt = "🗑 حذف پاسخ سخنگو

فقط کلمه/کلید را بفرست.
مثال:
سلام";
        } else {
            $txt = "📣 متن پیام گروه را بفرست.
گروه هدف:
{$target}";
        }

        $this->client->sendMessage($privateChatId, $txt, $replyToMessageId);
    }

    private function handleLockCommand(string $chatId, string $messageId, array $group, string $key, string $clean): bool
    {
        if (in_array($key, ['قفلهمه', 'قفلکامل', 'lockall'], true)) {
            $locks = $group['locks'] ?? [];
            foreach (array_keys($this->lockDefinitions()) as $lk) {
                $locks[$lk] = true;
            }
            $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
            $this->client->sendMessage($chatId, "╭─ 🔐 همه قفل‌ها فعال شد\n╰─ تنظیمات ذخیره شد.", $messageId);
            return true;
        }

        if (in_array($key, ['بازهمه', 'بازکردنهمه', 'unlockall'], true)) {
            $locks = $group['locks'] ?? [];
            foreach (array_keys($this->lockDefinitions()) as $lk) {
                $locks[$lk] = false;
            }
            $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
            $this->client->sendMessage($chatId, "╭─ 🔓 همه قفل‌ها باز شد\n╰─ محدودیت‌ها برداشته شد.", $messageId);
            return true;
        }

        $directLock = $this->lockFromCommandKey($key);
        if ($directLock !== null) {
            [$lock, $enable] = $directLock;
            $locks = $group['locks'];
            $locks[$lock] = $enable;
            $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
            $this->client->sendMessage($chatId, $this->lockStatusText($lock, $enable), $messageId);
            return true;
        }

        $map = [
            'locklink' => ['link', true], 'قفللینک' => ['link', true], 'قفلکردنلینک' => ['link', true], 'قفلکردنقفللینک' => ['link', true],
            'unlocklink' => ['link', false], 'openlink' => ['link', false], 'بازلینک' => ['link', false], 'بازکردنلینک' => ['link', false], 'بازکردنقفللینک' => ['link', false],
            'lockforward' => ['forward', true], 'قفلفوروارد' => ['forward', true], 'unlockforward' => ['forward', false], 'بازفوروارد' => ['forward', false],
            'lockid' => ['rubika_id', true], 'قفلآیدی' => ['rubika_id', true], 'قفلایدی' => ['rubika_id', true], 'unlockid' => ['rubika_id', false], 'بازآیدی' => ['rubika_id', false], 'بازایدی' => ['rubika_id', false],
            'lockphone' => ['phone', true], 'قفلشماره' => ['phone', true], 'unlockphone' => ['phone', false], 'بازشماره' => ['phone', false],
            'lockmedia' => ['media', true], 'قفلرسانه' => ['media', true], 'unlockmedia' => ['media', false], 'بازرسانه' => ['media', false],
            'lockflood' => ['flood', true], 'unlockflood' => ['flood', false], 'lockrepeat' => ['repeat', true], 'unlockrepeat' => ['repeat', false],
        ];

        if (isset($map[$key])) {
            [$lock, $enable] = $map[$key];
            $locks = $group['locks'];
            $locks[$lock] = $enable;
            $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
            $this->client->sendMessage($chatId, $this->lockStatusText($lock, $enable), $messageId);
            return true;
        }

        if (preg_match('/^(قفل|قفل کردن|باز|باز کردن|بازکردن|باز کردن قفل|بازکردن قفل)\s+(.+)$/u', $clean, $m)) {
            $enable = in_array($m[1], ['قفل', 'قفل کردن'], true);
            $lock = $this->lockKey(trim($m[2]));

            if (!$lock) {
                $this->client->sendMessage($chatId, 'نام قفل نامعتبر است. مثال: قفل لینک، باز فوروارد، قفل شماره', $messageId);
                return true;
            }

            $locks = $group['locks'];
            $locks[$lock] = $enable;
            $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
            $this->client->sendMessage($chatId, $this->lockStatusText($lock, $enable), $messageId);
            return true;
        }

        if (preg_match('/^(.+?)\s+(قفل|بستن|باز|بازکردن|باز کردن)$/u', $clean, $m)) {
            $lock = $this->lockKey(trim($m[1]));
            $enable = in_array($m[2], ['قفل', 'بستن'], true);

            if (!$lock) {
                $this->client->sendMessage($chatId, 'نام قفل نامعتبر است. مثال: لینک قفل، گیف قفل، فوروارد باز', $messageId);
                return true;
            }

            $locks = $group['locks'];
            $locks[$lock] = $enable;
            $this->db->updateGroup($chatId, ['locks_json' => Support::jsonEncode($locks)]);
            $this->client->sendMessage($chatId, $this->lockStatusText($lock, $enable), $messageId);
            return true;
        }

        return false;
    }

    private function sendPrivateStart(string $chatId, string $senderId, ?string $replyToMessageId = null): void
    {
        if (!$this->canUseBot($senderId)) {
            $this->sendGlobalForceJoinNotice($chatId, $replyToMessageId);
            return;
        }

        $myGroups = count($this->groupsForUser($senderId));
        $allInstalls = count($this->allGroups());

        $rows = [
            ['buttons' => [
                $this->btn('kb:groups', '📋 گروه‌های من'),
                $this->btn('kb:add_group', '➕ افزودن به گروه'),
            ]],
            ['buttons' => [
                $this->btn('kb:install_stats', "👥 نصب ربات در {$allInstalls} گروه"),
            ]],
        ];

        if ($this->isOwner($senderId)) {
            $rows[] = ['buttons' => [
                $this->btn('kb:owner', '👑 پنل اصلی'),
            ]];
        }

        $inlineKeypad = ['rows' => $rows];

        if ($this->isOwner($senderId)) {
            $txt = "❤️ به پنل مدیریت ربات خوش آمدید.
" .
                "من " . $this->botDisplayName() . " هستم.

" .
                "👑 سطح دسترسی: مالک اصلی ربات
" .
                "👥 تعداد نصب کل: {$allInstalls}
" .
                "📋 گروه‌های قابل مدیریت: {$myGroups}

" .
                "از دکمه‌های زیر استفاده کن.";
        } else {
            $txt = "❤️ به ربات خوش آمدید.
" .
                "من " . $this->botDisplayName() . " هستم.

" .
                "👥 تعداد نصب کل ربات: {$allInstalls}
" .
                "📋 گروه‌های من: {$myGroups}

" .
                "✅ نحوه فعال‌سازی:
" .
                $this->botShareUrl() . "

" .
                "• ربات را به گروه اضافه کن.
" .
                "• ربات را ادمین کن و دسترسی حذف پیام بده.
" .
                "• داخل گروه دستور نصب یا /install را بزن.
" .
                "• سپس همین‌جا روی «📋 گروه‌های من» بزن.";
        }

        $supportLine = trim($this->supportText());
        if ($supportLine !== '') {
            $txt .= "\n\n" . $supportLine;
        }

        $this->client->sendMessage($chatId, $txt, $replyToMessageId, $inlineKeypad);
    }

    private function botDisplayName(): string
    {
        $name = trim((string)$this->db->getState('bot_display_name', ''));
        if ($name !== '') {
            return mb_substr($name, 0, 40, 'UTF-8');
        }

        $cfg = trim((string)($this->config['bot_display_name'] ?? $this->config['bot_name'] ?? ''));
        if ($cfg !== '') {
            return mb_substr($cfg, 0, 40, 'UTF-8');
        }

        return 'ربات مدیریت گروه';
    }

    private function setBotDisplayName(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'ربات مدیریت گروه';
        }
        $this->db->setState('bot_display_name', mb_substr($name, 0, 40, 'UTF-8'));
    }

    private function supportText(): string
    {
        $txt = trim((string)$this->db->getState('support_text', ''));
        return $txt !== '' ? $txt : 'پشتیبانی: @LoopSTL';
    }

    private function setSupportText(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            $text = 'پشتیبانی: @LoopSTL';
        }
        $this->db->setState('support_text', $text);
    }

    private function sendInstallStatsAd(string $chatId, ?string $replyToMessageId = null): void
    {
        $allInstalls = count($this->allGroups());
        $enabled = 0;
        foreach ($this->allGroups() as $g) {
            if (!empty($g['enabled'])) {
                $enabled++;
            }
        }

        $txt = "👥 آمار نصب ربات\n\n" .
            "ربات هم‌اکنون در {$allInstalls} گروه نصب شده است.\n" .
            "گروه‌های فعال: {$enabled}\n\n" .
            "برای افزودن ربات به گروه خودت، دکمه «➕ افزودن به گروه» را بزن.";

        $this->client->sendMessage($chatId, $txt, $replyToMessageId, [
            'rows' => [
                ['buttons' => [
                    $this->btn('kb:add_group', '➕ افزودن به گروه'),
                    $this->btn('kb:groups', '📋 گروه‌های من'),
                ]],
            ],
        ]);
    }

    private function sendAddGroupHelp(string $chatId, ?string $replyToMessageId = null): void
    {
        $txt = "➕ افزودن ربات به گروه

" .
            "1) از لینک زیر ربات را به گروه اضافه کن:
" .
            $this->botShareUrl() . "

" .
            "2) در تنظیمات ربات، دریافت پیام را فعال کن.
" .
            "3) ربات را ادمین کن و دسترسی حذف پیام بده.
" .
            "4) داخل گروه دستور نصب یا /install را بزن.
" .
            "5) بعد از نصب، در پیوی دکمه «📋 گروه‌های من» را بزن.

" .
            $this->supportText();

        $this->client->sendMessage($chatId, $txt, $replyToMessageId);
    }

    private function handlePrivateKeyboardText(array $n): bool
    {
        $chatId = (string)$n['chat_id'];
        $senderId = (string)$n['sender_id'];
        $messageId = (string)$n['message_id'];
        $text = $this->normalizeCommandText((string)$n['text']);
        if ($chatId !== '' && !$this->isGroupChat($chatId)) {
            $senderId = $this->resolvePrivateSenderId($n);
            $n['sender_id'] = $senderId;
            $this->db->registerPrivateChat($chatId, $senderId);
        }

        if ($this->isGroupChat($chatId) || $text === '') {
            return false;
        }

        if (str_contains($text, 'عضو شدم') || str_contains($text, 'تایید عضویت') || str_contains($text, 'تایید جوین')) {
            $this->markGlobalForceJoinVerified($senderId);
            $this->client->sendMessage($chatId, "✅ عضویت شما در جوین اجباری ربات ثبت شد.
اکنون می‌توانید از ربات استفاده کنید.", $messageId ?: null);
            $this->sendPrivateStart($chatId, $senderId, null);
            return true;
        }

        if (!$this->canUseBot($senderId)) {
            $this->sendGlobalForceJoinNotice($chatId, $messageId ?: null);
            return true;
        }

        // دکمه معمولی chat_keypad ممکن است فقط متن دکمه را به‌صورت پیام بفرستد.
        if (str_contains($text, 'گروه') && str_contains($text, 'من')) {
            $this->sendGroupsList($chatId, $senderId, $messageId ?: null);
            return true;
        }

        if (str_contains($text, 'افزودن') || str_contains($text, 'اضافه')) {
            $this->sendAddGroupHelp($chatId, $messageId ?: null);
            return true;
        }

        if ((str_contains($text, 'پنل') && str_contains($text, 'اصلی')) || str_contains($text, 'مالک')) {
            if ($this->isOwner($senderId)) {
                $this->editOwnerPanelToChat($chatId, $messageId ?: null);
            } else {
                $this->client->sendMessage($chatId, '⛔ فقط مالک اصلی ربات به این بخش دسترسی دارد.', $messageId ?: null);
            }
            return true;
        }

        return false;
    }

    private function sendGroupsList(string $sendChatId, string $senderId, ?string $replyToMessageId = null): void
    {
        $senderId = trim($senderId);
        if ($senderId === '' && $sendChatId !== '' && !$this->isGroupChat($sendChatId)) {
            $senderId = $sendChatId;
        }

        if (!$this->canUseBot($senderId)) {
            $this->sendGlobalForceJoinNotice($sendChatId, $replyToMessageId);
            return;
        }

        $groups = $this->groupsForUser($senderId);
        if (!$groups && $sendChatId !== '' && $sendChatId !== $senderId && !$this->isGroupChat($sendChatId)) {
            $groups = $this->groupsForUser($sendChatId);
            if ($groups) {
                $senderId = $sendChatId;
            }
        }

        if (!$groups) {
            $this->client->sendMessage($sendChatId, "هنوز گروهی برای شما نصب نشده است.
داخل گروه ربات را ادمین کن و /install را بزن.

اگر قبلاً نصب کرده‌ای، داخل همان گروه یک‌بار دوباره بزن: نصب", $replyToMessageId);
            return;
        }

        $rows = [];
        $lines = ["📋 گروه‌های من", "روی نام گروه بزن تا پنل همان گروه باز شود.", ""];
        foreach ($groups as $g) {
            $title = $this->groupLabel($g);
            $gid = (string)($g['chat_id'] ?? '');
            $rows[] = ['buttons' => [
                // دکمه فقط اسم گروه باشد؛ دستور پنل در پیوی نمایش داده نشود.
                $this->btn('ui:g:' . $gid, $title),
            ]];
            $lines[] = "• {$title}";
        }
        $lines[] = "";

        $this->client->sendMessage($sendChatId, implode("
", $lines), $replyToMessageId, ['rows' => $rows]);
    }

    private function askDatabaseResetConfirmation(string $senderId, string $privateChatId, ?string $replyToMessageId = null): void
    {
        $this->askNextMessage($senderId, $privateChatId, 'reset_database_confirm', null, $replyToMessageId);
    }

    private function performDatabaseReset(string $chatId, ?string $replyToMessageId = null): void
    {
        try {
            $backupPath = $this->db->backup();
            $counts = $this->db->resetAllData();

            $groups = (int)($counts['groups'] ?? 0);
            $warnings = (int)($counts['warnings'] ?? 0);
            $mutes = (int)($counts['soft_bans'] ?? 0);
            $violations = (int)($counts['violations'] ?? 0);
            $stats = (int)($counts['message_stats'] ?? 0);
            $private = (int)($counts['private_chats'] ?? 0);
            $state = (int)($counts['state'] ?? 0);

            $txt = "✅ دیتابیس با موفقیت ریست شد.

" .
                "📦 بکاپ قبل از حذف:
{$backupPath}

" .
                "موارد پاک‌شده:
" .
                "• گروه‌ها: {$groups}
" .
                "• اخطارها: {$warnings}
" .
                "• سکوت‌ها: {$mutes}
" .
                "• تخلف‌ها: {$violations}
" .
                "• آمار پیام‌ها: {$stats}
" .
                "• پیوی‌های ثبت‌شده: {$private}
" .
                "• state/settings دیتابیس: {$state}

" .
                "از این به بعد برای مدیریت هر گروه باید داخل همان گروه دوباره دستور «نصب» را بزنید.";
            $this->client->sendMessage($chatId, $txt, $replyToMessageId);
        } catch (\Throwable $e) {
            Support::log($this->config, 'database_reset_error', ['error' => $e->getMessage()]);
            $this->client->sendMessage($chatId, '❌ ریست دیتابیس انجام نشد.', $replyToMessageId);
        }
    }

    private function sendOwnerPanel(string $chatId, ?string $replyToMessageId = null): void
    {
        [$text, $keypad] = $this->ownerPanelData();
        $this->client->sendMessage($chatId, $text, $replyToMessageId, $keypad);
    }

    private function editOwnerPanelToChat(string $chatId, ?string $messageId = null): void
    {
        [$text, $keypad] = $this->ownerPanelData();

        if ($messageId) {
            $textRes = $this->client->editMessageText($chatId, $messageId, $text);
            $keypadRes = $this->client->editMessageKeypad($chatId, $messageId, $keypad);

            $textOk = (($textRes['status'] ?? '') === 'OK' || ($textRes['ok'] ?? false));
            $keypadOk = (($keypadRes['status'] ?? '') === 'OK' || ($keypadRes['ok'] ?? false));

            // اگر فقط متن ویرایش شود ولی دکمه‌ها ویرایش نشوند، پنل گمراه‌کننده می‌شود؛ پس پنل تازه ارسال می‌کنیم.
            if ($textOk && $keypadOk) {
                return;
            }

            Support::log($this->config, 'edit_owner_panel_fallback', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text_response' => $textRes,
                'keypad_response' => $keypadRes,
            ]);
        }

        $this->client->sendMessage($chatId, $text, null, $keypad);
    }

    private function ownerPanelData(): array
    {
        $text = $this->ownerStatsText() . "

👇 مدیریت کلی ربات:";
        $keypad = [
            'rows' => [
                ['buttons' => [
                    $this->btn('ui:owner:stats', '📊 آمار کلی'),
                    $this->btn('ui:groups', '📋 لیست گروه‌ها'),
                ]],
                ['buttons' => [
                    $this->btn('ui:owner:broadcast', '📣 پیام همگانی'),
                    $this->btn('ui:owner:broadcast_status', '📈 وضعیت همگانی'),
                    $this->btn('ui:owner:backup', '📦 بکاپ دیتابیس'),
                ]],
                ['buttons' => [
                    $this->btn('ui:owner:resetdb', '🧨 ریست دیتابیس'),
                ]],
                ['buttons' => [
                    $this->btn('ui:owner:botname:set', '🏷 تنظیم نام ربات'),
                    $this->btn('ui:owner:botname:show', '👁 نمایش نام'),
                ]],
                ['buttons' => [
                    $this->btn('ui:owner:support:set', '🛟 تنظیم پشتیبانی'),
                    $this->btn('ui:owner:support:show', '👁 نمایش پشتیبانی'),
                ]],
                ['buttons' => [
                    $this->btn('ui:owner:forcejoin:toggle', $this->globalForceJoinEnabledRaw() ? '✅ جوین اجباری ربات روشن' : '⛔ جوین اجباری ربات خاموش'),
                ]],
                ['buttons' => [
                    $this->btn('ui:owner:forcejoin:add', '➕ افزودن جوین اجباری'),
                    $this->btn('ui:owner:forcejoin:list', '📋 لیست جوین'),
                    $this->btn('ui:owner:forcejoin:clear', '🗑 حذف جوین'),
                ]],
                ['buttons' => [
                    $this->btn('ui:owner:badwords:toggle', $this->globalBadWordsEnabledRaw() ? '✅ فیلتر کلمات سراسری روشن' : '⛔ فیلتر کلمات سراسری خاموش'),
                ]],
                ['buttons' => [
                    $this->btn('ui:owner:badwords:add', '➕ افزودن کلمات سراسری'),
                    $this->btn('ui:owner:badwords:list', '📋 لیست کلمات'),
                    $this->btn('ui:owner:badwords:clear', '🗑 حذف کلمات'),
                ]],
            ],
        ];

        return [$text, $keypad];
    }

    private function sendPanelToChat(string $sendChatId, string $targetChatId, ?string $replyToMessageId = null, ?string $viewerId = null): void
    {
        [$text, $keypad] = $this->panelData($targetChatId, $viewerId);
        $this->client->sendMessage($sendChatId, $text, $replyToMessageId, $keypad);
    }

    private function editPanelToChat(string $sendChatId, string $targetChatId, ?string $messageId = null, ?string $viewerId = null): void
    {
        [$text, $keypad] = $this->panelData($targetChatId, $viewerId);

        if ($messageId) {
            $textRes = $this->client->editMessageText($sendChatId, $messageId, $text);
            $keypadRes = $this->client->editMessageKeypad($sendChatId, $messageId, $keypad);

            $textOk = (($textRes['status'] ?? '') === 'OK' || ($textRes['ok'] ?? false));
            $keypadOk = (($keypadRes['status'] ?? '') === 'OK' || ($keypadRes['ok'] ?? false));

            // اگر فقط متن ویرایش شود ولی دکمه‌ها ویرایش نشوند، پنل گمراه‌کننده می‌شود؛ پس پنل تازه ارسال می‌کنیم.
            if ($textOk && $keypadOk) {
                return;
            }

            Support::log($this->config, 'edit_group_panel_fallback', [
                'chat_id' => $sendChatId,
                'target_chat_id' => $targetChatId,
                'message_id' => $messageId,
                'text_response' => $textRes,
                'keypad_response' => $keypadRes,
            ]);
        }

        $this->client->sendMessage($sendChatId, $text, null, $keypad);
    }

    private function panelData(string $targetChatId, ?string $viewerId = null): array
    {
        return $this->panelPageData($targetChatId, 'main', $viewerId);
    }

    private function panelPageData(string $targetChatId, string $page = 'main', ?string $viewerId = null): array
    {
        $g = $this->db->getGroup($targetChatId);
        $page = $this->normalizePanelPage($page);

        return match (true) {
            str_starts_with($page, 'locks') => $this->panelLocksData($targetChatId, $g, $page, $viewerId),
            $page === 'settings' => $this->panelSettingsData($targetChatId, $g, $viewerId),
            $page === 'talker' => $this->panelTalkerData($targetChatId, $g, $viewerId),
            $page === 'rules' => $this->panelRulesData($targetChatId, $g, $viewerId),
            $page === 'tools' => $this->panelToolsData($targetChatId, $g, $viewerId),
            default => $this->panelMainData($targetChatId, $g, $viewerId),
        };
    }

    private function normalizePanelPage(string $page): string
    {
        $page = trim($page);
        if ($page === '') {
            return 'main';
        }
        if (preg_match('/^locks(\d+)$/', $page, $m)) {
            $pageNumber = max(1, (int)$m[1]);
            $perPage = 8;
            $pages = max(1, (int)ceil(count($this->lockDefinitions()) / $perPage));
            return 'locks' . min($pages, $pageNumber);
        }
        return in_array($page, ['main', 'settings', 'talker', 'rules', 'tools'], true) ? $page : 'main';
    }

    private function panelMainData(string $targetChatId, array $g, ?string $viewerId = null): array
    {
        $label = $this->groupLabel($g);
        $activeLocks = $this->activeLocksCount($g);
        $totalLocks = count($this->lockDefinitions());
        $muted = $this->softMutedCount($targetChatId);
        $max = max(1, (int)($g['max_warnings'] ?? 2));
        $floodMax = max(2, (int)($g['flood']['max_messages'] ?? 5));
        $talker = $this->talkerEnabled($g) ? 'روشن 🗣' : 'خاموش 🔇';
        $text = "⚙️ پنل گروه «{$label}»\n"
            . "وضعیت ربات: " . (!empty($g['enabled']) ? 'روشن ✅' : 'خاموش ⛔') . "\n"
            . "قفل‌ها: {$activeLocks}/{$totalLocks} فعال\n"
            . "اخطار: {$max} | اسپم: {$floodMax} پیام\n"
            . "سخنگو: {$talker}\n"
            . "سکوتی‌ها: {$muted}\n\n"
            . "از دکمه‌های زیر بخش موردنظر را باز کن:";

        return [$text, $this->panelKeypad($targetChatId, $g, $viewerId)];
    }

    private function panelKeypad(string $chatId, array $g, ?string $viewerId = null): array
    {
        $locks = $g['locks'] ?? [];
        $rows = [
            ['buttons' => [$this->btn('panel:' . $chatId . ':toggle:enabled:main', !empty($g['enabled']) ? '✅ ربات روشن' : '⛔ ربات خاموش')]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':page:locks1', '🔐 قفل‌ها'),
                $this->btn('panel:' . $chatId . ':page:settings', '⚙️ تنظیمات'),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':page:tools', '🧰 ابزار مدیریت'),
                $this->btn('panel:' . $chatId . ':page:rules', '🚨 جریمه و فیلتر'),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':page:talker', '🧠 تنظیم سخنگو'),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':muted:list', '🔇 سکوتی‌ها (' . $this->softMutedCount($chatId) . ')'),
                $this->btn('panel:' . $chatId . ':admins:list', '👥 ادمین‌ها'),
            ]],
        ];

        return ['rows' => $rows];
    }

    private function panelLocksData(string $chatId, array $g, string $page, ?string $viewerId = null): array
    {
        $locks = $g['locks'] ?? [];
        $defs = $this->lockDefinitions();
        $perPage = 8;
        $pageNumber = 1;
        if (preg_match('/^locks(\d+)$/', $page, $m)) {
            $pageNumber = max(1, (int)$m[1]);
        }
        $pages = max(1, (int)ceil(count($defs) / $perPage));
        $pageNumber = min($pages, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $slice = array_slice($defs, $offset, $perPage, true);
        $label = $this->groupLabel($g);

        $text = "🔐 قفل‌های گروه «{$label}»\n"
            . "صفحه {$pageNumber}/{$pages}\n\n"
            . "📌 جدول قفل‌ها سه ستونه است:\n"
            . "🔐 نام قفل = روشن/خاموش کردن قفل\n"
            . "⚖️ جریمه = با هر کلیک بین بن، سکوت، سیک، فقط حذف و بدون جریمه تغییر می‌کند\n"
            . "⚠️ اخطار = سقف اخطار مخصوص همان قفل\n\n"
            . "نمونه دستورها:\n"
            . "تنظیم اخطار لینک 3\n"
            . "تنظیم جریمه لینک سکوت\n\n"
            . $this->locksSummaryText($g, 8);

        $rows = [];
        $rows[] = ['buttons' => [
            $this->btn('panel:' . $chatId . ':toggleall:on:locks' . $pageNumber, '🔐 قفل همه'),
            $this->btn('panel:' . $chatId . ':toggleall:off:locks' . $pageNumber, '🔓 باز همه'),
        ]];

        $rows[] = ['buttons' => [
            $this->btn('panel:' . $chatId . ':noop:head:locks' . $pageNumber, '🔐 نام قفل'),
            $this->btn('panel:' . $chatId . ':noop:head:locks' . $pageNumber, '⚖️ جریمه'),
            $this->btn('panel:' . $chatId . ':noop:head:locks' . $pageNumber, '⚠️ اخطار'),
        ]];

        foreach ($slice as $lockKey => $lockName) {
            $rows[] = ['buttons' => [
                $this->btn('panel:' . $chatId . ':toggle:' . $lockKey . ':locks' . $pageNumber, $this->lockButtonText($lockKey, $lockName, $locks)),
                $this->btn('panel:' . $chatId . ':lockpunish:' . $lockKey . ':locks' . $pageNumber, $this->lockPunishButtonText($lockKey, $g)),
                $this->btn('panel:' . $chatId . ':warnlock:' . $lockKey . ':locks' . $pageNumber, $this->lockWarningButtonText($lockKey, $g)),
            ]];
        }

        $nav = [];
        if ($pageNumber > 1) {
            $nav[] = $this->panelPageBtn($chatId, 'locks' . ($pageNumber - 1), '⬅️ قبلی');
        }
        if ($pageNumber < $pages) {
            $nav[] = $this->panelPageBtn($chatId, 'locks' . ($pageNumber + 1), 'بعدی ➡️');
        }
        if ($nav) {
            $rows[] = ['buttons' => $nav];
        }

        $rows[] = ['buttons' => [$this->btn('panel:' . $chatId . ':back:panel', '⬅️ بازگشت')]];
        return [$text, ['rows' => $rows]];
    }

    private function panelSettingsData(string $chatId, array $g, ?string $viewerId = null): array
    {
        $locks = $g['locks'] ?? [];
        $max = max(1, (int)($g['max_warnings'] ?? 2));
        $warnOn = $this->warningEnabled($g);
        $floodMax = max(2, (int)($g['flood']['max_messages'] ?? 5));
        $label = $this->groupLabel($g);
        $text = "⚙️ تنظیمات گروه «{$label}»\n"
            . "اخطار: " . ($warnOn ? 'روشن ✅' : 'خاموش ⛔') . " | حد: {$max}\n"
            . "اسپم: {$floodMax} پیام پشت سر هم";

        $rows = [
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':toggle:talker_enabled:settings', $this->talkerEnabled($g) ? '🗣 سخنگو روشن' : '🔇 سخنگو خاموش'),
                $this->btn('panel:' . $chatId . ':page:talker', '🧠 پنل سخنگو'),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':warn:toggle:settings', $warnOn ? '⚠️ اخطار روشن' : '⚠️ اخطار خاموش'),
                $this->btn('panel:' . $chatId . ':warn:limit2:settings', 'حد اخطار: ' . $max),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':warn:limit1:settings', '1 اخطار'),
                $this->btn('panel:' . $chatId . ':warn:limit2:settings', '2 اخطار'),
                $this->btn('panel:' . $chatId . ':warn:limit3:settings', '3 اخطار'),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':flood:max3:settings', ($floodMax === 3 ? '✅ ' : '') . 'اسپم 3'),
                $this->btn('panel:' . $chatId . ':flood:max5:settings', ($floodMax === 5 ? '✅ ' : '') . 'اسپم 5'),
                $this->btn('panel:' . $chatId . ':flood:max7:settings', ($floodMax === 7 ? '✅ ' : '') . 'اسپم 7'),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':flood:max10:settings', ($floodMax === 10 ? '✅ ' : '') . 'اسپم 10'),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':page:rules', '🚨 جریمه و فیلتر'),
            ]],
            ['buttons' => [$this->btn('panel:' . $chatId . ':back:panel', '⬅️ بازگشت به پنل')]],
        ];

        return [$text, ['rows' => $rows]];
    }


    private function panelTalkerData(string $chatId, array $g, ?string $viewerId = null): array
    {
        $label = $this->groupLabel($g);
        $count = Talker::responseCount();
        $ownerView = $viewerId !== null && $this->isOwner((string)$viewerId);
        $text = "🧠 تنظیم سخنگو برای گروه «{$label}»
"
            . "وضعیت: " . ($this->talkerEnabled($g) ? 'روشن ✅' : 'خاموش ⛔') . "
"
            . "تعداد کل کلیدهای پاسخ: {$count}

";
        if ($ownerView) {
            $text .= "افزودن پاسخ با فرمت:
کلمه | پاسخ

"
                . "برای پاسخ دقیق: وقتی پیام دقیقاً همان کلمه باشد.
"
                . "برای پاسخ شامل: وقتی متن کاربر شامل آن کلمه باشد.";
        } else {
            $text .= "ادمین‌های گروه در این بخش فقط اجازه روشن/خاموش کردن سخنگو را دارند. افزودن، حذف و مشاهده کلیدهای سخنگو فقط برای مالک اصلی ربات فعال است.";
        }

        $rows = [
            ['buttons' => [$this->btn('panel:' . $chatId . ':toggle:talker_enabled:talker', $this->talkerEnabled($g) ? '🗣 سخنگو روشن' : '🔇 سخنگو خاموش')]],
        ];
        if ($ownerView) {
            $rows[] = ['buttons' => [
                $this->btn('panel:' . $chatId . ':talker:add_exact', '➕ پاسخ دقیق'),
                $this->btn('panel:' . $chatId . ':talker:add_contains', '➕ پاسخ شامل'),
            ]];
            $rows[] = ['buttons' => [
                $this->btn('panel:' . $chatId . ':talker:list', '📋 لیست پاسخ‌ها'),
                $this->btn('panel:' . $chatId . ':talker:delete', '🗑 حذف پاسخ'),
            ]];
        }
        $rows[] = ['buttons' => [$this->btn('panel:' . $chatId . ':page:settings', '⬅️ بازگشت به تنظیمات')]];
        $rows[] = ['buttons' => [$this->btn('panel:' . $chatId . ':back:panel', '🏠 پنل اصلی')]];

        return [$text, ['rows' => $rows]];
    }

private function panelRulesData(string $chatId, array $g, ?string $viewerId = null): array
    {
        $punish = $this->punishAction($g);
        $label = $this->groupLabel($g);
        $text = "🚨 جریمه و فیلتر گروه «{$label}»\n"
            . "جریمه فعلی: " . match ($punish) {
                'delete' => 'فقط حذف',
                'kick' => 'سیک/اخراج',
                'ban' => 'بن',
                'none' => 'بدون جریمه',
                default => 'سکوت نرم',
            } . "\n"
            . "از این بخش فیلتر کلمات و رفتار بعد از تخلف را تنظیم کن.";

        $rows = [
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':punish:delete:rules', ($punish === 'delete' ? '✅ ' : '') . 'فقط حذف'),
                $this->btn('panel:' . $chatId . ':punish:silence:rules', ($punish === 'silence' ? '✅ ' : '') . 'سکوت نرم'),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':punish:kick:rules', ($punish === 'kick' ? '✅ ' : '') . 'سیک/اخراج'),
                $this->btn('panel:' . $chatId . ':punish:ban:rules', ($punish === 'ban' ? '✅ ' : '') . 'بن'),
                $this->btn('panel:' . $chatId . ':punish:none:rules', ($punish === 'none' ? '✅ ' : '') . 'بدون جریمه'),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':badword:add', '➕ افزودن فیلتر'),
                $this->btn('panel:' . $chatId . ':badword:list', '📋 لیست فیلتر'),
            ]],
            ['buttons' => [$this->btn('panel:' . $chatId . ':badword:clear:rules', '🗑 حذف فیلترها')]],
            ['buttons' => [$this->btn('panel:' . $chatId . ':page:settings', '⬅️ بازگشت به تنظیمات')]],
            ['buttons' => [$this->btn('panel:' . $chatId . ':back:panel', '🏠 پنل اصلی')]],
        ];

        return [$text, ['rows' => $rows]];
    }

    private function panelToolsData(string $chatId, array $g, ?string $viewerId = null): array
    {
        $label = $this->groupLabel($g);
        $text = "🧰 ابزار مدیریت گروه «{$label}»\n"
            . "از این بخش لیست‌ها و ابزارهای مدیریتی را باز کن.";

        $rows = [
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':muted:list', '🔇 کاربران سکوت (' . $this->softMutedCount($chatId) . ')'),
                $this->btn('panel:' . $chatId . ':admins:list', '👥 لیست ادمین‌ها'),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':report:violations', '📊 گزارش تخلفات'),
            ]],
            ['buttons' => [
                $this->btn('panel:' . $chatId . ':settings_export:json:tools', '📤 خروجی تنظیمات'),
                $this->btn('panel:' . $chatId . ':settings_import:json:tools', '📥 ورود تنظیمات'),
            ]],
            ['buttons' => [$this->btn('panel:' . $chatId . ':back:panel', '⬅️ بازگشت به پنل')]],
        ];

        return [$text, ['rows' => $rows]];
    }


    private function violationReportPayload(string $chatId): array
    {
        $g = $this->db->getGroup($chatId);
        $label = $this->groupLabel($g);
        $top = $this->db->topViolators($chatId, 10);
        $recent = $this->db->recentViolations($chatId, 8);
        $mentions = [];

        $txt = "📊 گزارش تخلفات گروه «{$label}»\n\n";
        $txt .= "👥 کاربران پرتخلف:\n";
        if (!$top) {
            $txt .= "• هنوز تخلفی ثبت نشده است.\n";
        } else {
            foreach ($top as $i => $row) {
                $uid = (string)($row['user_id'] ?? '');
                $num = $this->faNumber($i + 1);
                $count = $this->faNumber((int)($row['total'] ?? 0));
                $labelUser = "کاربر {$num}";
                $txt .= "• {$labelUser}: {$count} تخلف\n";
                if ($uid !== '') $mentions[] = ['label' => $labelUser, 'user_id' => $uid];
            }
        }

        $txt .= "\n🕘 آخرین تخلفات:\n";
        if (!$recent) {
            $txt .= "• موردی نیست.";
        } else {
            foreach ($recent as $i => $row) {
                $num = $this->faNumber($i + 1);
                $reason = trim((string)($row['reason'] ?? 'نامشخص'));
                $action = trim((string)($row['action'] ?? ''));
                $txt .= "{$num}) {$reason}" . ($action !== '' ? " — {$action}" : '') . "\n";
            }
        }

        return [$txt, MetadataBuilder::mentions($txt, $mentions)];
    }

    private function activeLocksCount(array $g): int
    {
        $locks = $g['locks'] ?? [];
        $count = 0;
        foreach (array_keys($this->lockDefinitions()) as $lockKey) {
            if (!empty($locks[$lockKey])) {
                $count++;
            }
        }
        return $count;
    }

    private function locksSummaryText(array $g, int $limit = 8): string
    {
        $locks = $g['locks'] ?? [];
        $active = [];
        foreach ($this->lockDefinitions() as $lockKey => $lockName) {
            if (!empty($locks[$lockKey])) {
                $active[] = $lockName;
            }
        }
        if (!$active) {
            return "هنوز هیچ قفلی فعال نیست.";
        }
        $shown = array_slice($active, 0, max(1, $limit));
        $more = count($active) - count($shown);
        $text = "فعال‌ها: " . implode('، ', $shown);
        if ($more > 0) {
            $text .= " و {$more} مورد دیگر";
        }
        return $text;
    }

    private function editPanelPageToChat(string $sendChatId, string $targetChatId, string $page = 'main', ?string $messageId = null, ?string $viewerId = null): void
    {
        [$text, $keypad] = $this->panelPageData($targetChatId, $page, $viewerId);

        if ($messageId) {
            $textRes = $this->client->editMessageText($sendChatId, $messageId, $text);
            $keypadRes = $this->client->editMessageKeypad($sendChatId, $messageId, $keypad);

            $textOk = (($textRes['status'] ?? '') === 'OK' || ($textRes['ok'] ?? false));
            $keypadOk = (($keypadRes['status'] ?? '') === 'OK' || ($keypadRes['ok'] ?? false));

            if ($textOk && $keypadOk) {
                return;
            }

            Support::log($this->config, 'edit_group_panel_page_fallback', [
                'chat_id' => $sendChatId,
                'target_chat_id' => $targetChatId,
                'page' => $page,
                'message_id' => $messageId,
                'text_response' => $textRes,
                'keypad_response' => $keypadRes,
            ]);
        }

        $this->client->sendMessage($sendChatId, $text, null, $keypad);
    }

    private function editMutedUsersToChat(string $sendChatId, string $targetChatId, ?string $messageId = null, ?string $viewerId = null): void
    {
        [$text, $keypad] = $this->mutedUsersPanelData($targetChatId);

        if ($messageId) {
            $textRes = $this->client->editMessageText($sendChatId, $messageId, $text);
            $keypadRes = $this->client->editMessageKeypad($sendChatId, $messageId, $keypad);

            $textOk = (($textRes['status'] ?? '') === 'OK' || ($textRes['ok'] ?? false));
            $keypadOk = (($keypadRes['status'] ?? '') === 'OK' || ($keypadRes['ok'] ?? false));

            if ($textOk && $keypadOk) {
                return;
            }

            Support::log($this->config, 'edit_muted_panel_fallback', [
                'chat_id' => $sendChatId,
                'target_chat_id' => $targetChatId,
                'message_id' => $messageId,
                'text_response' => $textRes,
                'keypad_response' => $keypadRes,
            ]);
        }

        $this->client->sendMessage($sendChatId, $text, null, $keypad);
    }

    private function editAdminListToChat(string $sendChatId, string $targetChatId, ?string $messageId = null, ?string $viewerId = null): void
    {
        [$text, $metadata] = $this->adminListPayload($targetChatId);
        $keypad = ['rows' => [
            ['buttons' => [$this->btn('panel:' . $targetChatId . ':back:panel', '⬅️ بازگشت به پنل')]],
        ]];

        if ($messageId) {
            $textRes = $this->client->editMessageText($sendChatId, $messageId, $text);
            $keypadRes = $this->client->editMessageKeypad($sendChatId, $messageId, $keypad);
            $textOk = (($textRes['status'] ?? '') === 'OK' || ($textRes['ok'] ?? false));
            $keypadOk = (($keypadRes['status'] ?? '') === 'OK' || ($keypadRes['ok'] ?? false));
            if ($textOk && $keypadOk) {
                return;
            }
            Support::log($this->config, 'edit_admin_list_fallback', [
                'chat_id' => $sendChatId,
                'target_chat_id' => $targetChatId,
                'message_id' => $messageId,
                'text_response' => $textRes,
                'keypad_response' => $keypadRes,
            ]);
        }

        $this->client->sendMessage($sendChatId, $text, null, $keypad, null, $metadata ?: null);
    }

    private function mutedUsersPanelData(string $targetChatId): array
    {
        $g = $this->db->getGroup($targetChatId);
        $label = $this->groupLabel($g);
        $users = $this->softMutedUsers($targetChatId, 30);
        $count = count($users);

        if ($count === 0) {
            $text = "╭─ 🔇 کاربران سکوت
" .
                "├ گروه: {$label}
" .
                "╰─ فعلاً هیچ کاربری در سکوت نرم نیست.

" .
                "برای سکوت دستی، داخل گروه روی پیام کاربر ریپلای کن و بزن:
" .
                $this->codeCmd('سکوت') . "

" .
                "برای خروج دستی از سکوت، ریپلای کن و بزن:
" .
                $this->codeCmd('حذف سکوت');
        } else {
            $text = "╭─ 🔇 کاربران سکوت
" .
                "├ گروه: {$label}
" .
                "╰─ تعداد: {$count}

" .
                "برای آزادسازی، روی دکمه همان کاربر بزن.";
        }

        $rows = [];
        $i = 1;
        if ($users) {
            $text .= "
";
        }
        foreach ($users as $u) {
            $userId = (string)($u['user_id'] ?? '');
            if ($userId === '') {
                continue;
            }
            $expiresAt = (int)($u['expires_at'] ?? 0);
            $left = $expiresAt > 0 ? $this->formatRemainingSeconds($expiresAt - time()) : 'نامحدود';
            $text .= "
" . $this->faNumber($i) . ") مدت باقی‌مانده: " . $left;
            $rows[] = ['buttons' => [
                $this->btn('panel:' . $targetChatId . ':unmute:' . $userId, '✅ خروج کاربر ' . $i . ' از سکوت'),
            ]];
            $i++;
        }

        $rows[] = ['buttons' => [
            $this->btn('panel:' . $targetChatId . ':muted:list', '🔄 بروزرسانی'),
            $this->btn('panel:' . $targetChatId . ':back:panel', '↩️ بازگشت به پنل'),
        ]];

        return [$text, ['rows' => $rows]];
    }

    private function softMutedUsers(string $chatId, int $limit = 30): array
    {
        try {
            $limit = max(1, min(100, $limit));
            $stmt = $this->db->pdo()->prepare('SELECT user_id, reason, created_at, expires_at FROM soft_bans WHERE chat_id=? AND (expires_at IS NULL OR expires_at = 0 OR expires_at > ?) ORDER BY created_at DESC LIMIT ' . $limit);
            $stmt->execute([$chatId, time()]);
            return $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            Support::log($this->config, 'soft_muted_users_error', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function softMutedCount(string $chatId): int
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM soft_bans WHERE chat_id=? AND (expires_at IS NULL OR expires_at = 0 OR expires_at > ?)');
            $stmt->execute([$chatId, time()]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            Support::log($this->config, 'soft_muted_count_error', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    use BotAppHelpers;

}
