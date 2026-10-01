<?php
namespace RubikaGM;

use RuntimeException;

final class RubikaClient
{
    private string $token;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->token = trim((string)($config['bot_token'] ?? ''));
        if ($this->token === '' || str_contains($this->token, 'PUT_RUBIKA')) {
            throw new RuntimeException('توکن ربات در config.php تنظیم نشده است.');
        }
    }

    public function request(string $method, array $payload = [], int $timeout = 20): array
    {
        $url = 'https://botapi.rubika.ir/v3/' . rawurlencode($this->token) . '/' . $method;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok'=>false, 'status'=>'FAILED', 'error'=>'curl_init_failed'];
        }
        $body = Support::jsonEncode($payload ?: new \stdClass());
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno) {
            Support::log($this->config, 'curl_error', ['method'=>$method, 'error'=>$error]);
            return ['ok'=>false, 'error'=>'curl:' . $error, 'status'=>$status, 'raw'=>$raw];
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            Support::log($this->config, 'invalid_json_response', ['method'=>$method, 'status'=>$status, 'raw'=>$raw]);
            return ['ok'=>false, 'error'=>'invalid_json', 'status'=>$status, 'raw'=>$raw];
        }
        $decoded['_http_status'] = $status;
        return $decoded;
    }

    public function getMe(): array
    {
        return $this->request('getMe', [], 15);
    }

    public function getUpdates(?string $offsetId = null, int $limit = 50): array
    {
        $payload = ['limit' => $limit];
        if ($offsetId) $payload['offset_id'] = $offsetId;
        return $this->request('getUpdates', $payload, (int)($this->config['polling']['timeout'] ?? 20));
    }

    public function getChat(string $chatId): array
    {
        return $this->request('getChat', ['chat_id' => $chatId], 15);
    }

    private function markdownParseMode(): string
    {
        // نسخه‌های قبلی به‌صورت پیش‌فرض Markdown می‌فرستادند؛ API رسمی روبیکا
        // قالب‌بندی را از metadata می‌گیرد و ارسال parse_mode می‌تواند خطا بدهد.
        if ((int)($this->config['config_version'] ?? 0) < 85) return '';
        return trim((string)($this->config['parse_mode'] ?? ''));
    }

    private function withoutParseMode(array $payload): array
    {
        unset($payload['parse_mode']);
        return $payload;
    }

    private function codeMetaType(): string
    {
        // برای متن‌های داخل `...` در راهنماها. اگر روی یک نسخه روبیکا نوع دیگری لازم شد، از config.php قابل تغییر است.
        return (string)($this->config['code_meta_type'] ?? 'Mono');
    }

    private function metadataIndexUnit(): string
    {
        // روبیکا در metadata معمولاً offset را بر اساس UTF-16 code unit می‌خواند.
        // char کپی را فعال می‌کرد ولی بعد از ایموجی/فارسی کمی جابه‌جا می‌شد؛ byte هم در بعضی نسخه‌ها کلاً کپی را خراب می‌کند.
        // پیش‌فرض امن‌تر: utf16. اگر روی نسخه‌ای خاص لازم شد، در config.php به char برگردان.
        $unit = strtolower((string)($this->config['metadata_index_unit'] ?? 'utf16'));
        return in_array($unit, ['char', 'utf16'], true) ? $unit : 'utf16';
    }

    private function prepareFormattedText(string $text, ?array $metadata = null): array
    {
        return MetadataBuilder::prepareMarkdownMetadata($text, $metadata, $this->codeMetaType(), $this->metadataIndexUnit());
    }

    private function debugLog(string $message, array $context = []): void
    {
        if (!empty($this->config['debug'])) {
            Support::log($this->config, $message, $context);
        }
    }

    public function sendMessage(string $chatId, string $text, ?string $replyToMessageId = null, ?array $inlineKeypad = null, ?array $chatKeypad = null, ?array $metadata = null): array
    {
        $originalText = $text;
        $prepared = $this->prepareFormattedText($text, $metadata);
        $text = (string)$prepared['text'];
        $metadata = $prepared['metadata'];

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        // وقتی metadata می‌سازیم، دیگر parse_mode لازم نیست و در بعضی نسخه‌های API فقط متن خام را خراب می‌کند.
        $parseMode = $this->markdownParseMode();
        if (empty($metadata) && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        if ($replyToMessageId) {
            $payload['reply_to_message_id'] = $replyToMessageId;
        }

        if ($inlineKeypad) {
            $payload['inline_keypad'] = $inlineKeypad;
        }

        if ($chatKeypad) {
            $payload['chat_keypad'] = $chatKeypad;
            $payload['chat_keypad_type'] = 'New';
        }

        if ($metadata) {
            $payload['metadata'] = $metadata;
        }

        $this->debugLog('sendMessage_payload', $payload);
        $res = $this->request('sendMessage', $payload, 15);
        $this->debugLog('sendMessage_response', $res);

        $status = (string)($res['status'] ?? '');
        if ($status !== 'OK' && isset($payload['parse_mode'])) {
            // بعضی نسخه‌های API روبیکا parse_mode را قبول نمی‌کنند.
            // اگر رد شد، بدون parse_mode دوباره می‌فرستیم تا پیام از دست نرود.
            $plainPayload = $this->withoutParseMode($payload);
            $this->debugLog('sendMessage_parse_mode_fallback_payload', $plainPayload);
            $plainRes = $this->request('sendMessage', $plainPayload, 15);
            $this->debugLog('sendMessage_parse_mode_fallback_response', $plainRes);
            if ((string)($plainRes['status'] ?? '') === 'OK' || ($plainRes['ok'] ?? false)) {
                return $plainRes;
            }
        }

        if ($status !== 'OK' && $metadata) {
            // اگر نوع metadata توسط API رد شد، پیام را با متن اصلی و بدون metadata می‌فرستیم تا پیام از دست نرود.
            $plainPayload = [
                'chat_id' => $chatId,
                'text' => $originalText,
            ];
            if ($parseMode !== '') $plainPayload['parse_mode'] = $parseMode;
            if ($replyToMessageId) {
                $plainPayload['reply_to_message_id'] = $replyToMessageId;
            }
            if ($inlineKeypad) {
                $plainPayload['inline_keypad'] = $inlineKeypad;
            }
            if ($chatKeypad) {
                $plainPayload['chat_keypad'] = $chatKeypad;
                $plainPayload['chat_keypad_type'] = 'New';
            }
            $this->debugLog('sendMessage_metadata_fallback_payload', $plainPayload);
            $plainRes = $this->request('sendMessage', $plainPayload, 15);
            $this->debugLog('sendMessage_metadata_fallback_response', $plainRes);
            if ((string)($plainRes['status'] ?? '') === 'OK' || ($plainRes['ok'] ?? false)) {
                return $plainRes;
            }
        }

        if (($inlineKeypad || $chatKeypad) && $status !== 'OK') {
            // اگر روبیکا دکمه‌ها را نپذیرفت، خود متن را بدون دکمه می‌فرستیم تا کاربر حداقل دستور جایگزین را ببیند.
            // این fallback مخصوصاً برای «گروه‌های من» مهم است؛ چون button_id بلند یا ناسازگار می‌تواند کل پیام را رد کند.
            $fallback = [
                'chat_id' => $chatId,
                'text' => $text,
            ];
            if ($replyToMessageId) {
                $fallback['reply_to_message_id'] = $replyToMessageId;
            }
            if ($metadata) {
                $fallback['metadata'] = $metadata;
            }
            $this->debugLog('sendMessage_fallback_payload', $fallback);
            $fallbackRes = $this->request('sendMessage', $fallback, 15);
            $this->debugLog('sendMessage_fallback_response', $fallbackRes);
            return $fallbackRes;
        }

        return $res;
    }

    public function getFile(string $fileId): array
    {
        return $this->request('getFile', ['file_id' => $fileId], 10);
    }

    /**
     * GIF در روبیکا به صورت MP4 بدون ترک صدا نگهداری می‌شود، اما در بعضی آپدیت‌ها
     * مدل File فقط file_id/file_name/size دارد و type را نمی‌فرستد. این متد فقط
     * برای MP4 مبهم و فقط هنگام فعال بودن قفل گیف فراخوانی می‌شود.
     */
    public function probeGifFile(string $fileId, string $fileName = ''): array
    {
        static $cache = [];
        $cacheKey = $fileId !== '' ? $fileId : $fileName;
        if ($cacheKey !== '' && isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === 'gif') {
            return $cache[$cacheKey] = [
                'resolved' => true,
                'is_gif' => true,
                'has_video' => true,
                'has_audio' => false,
                'source' => 'file_extension',
            ];
        }
        if ($fileId === '') {
            return ['resolved' => false, 'is_gif' => false, 'source' => 'missing_file_id'];
        }

        $file = $this->getFile($fileId);
        $typeHint = mb_strtolower($this->firstScalarRecursive($file, ['type', 'file_type', 'media_type', 'message_type', 'kind']), 'UTF-8');
        if (in_array($typeHint, ['gif', 'animation', 'animated'], true)) {
            return $cache[$cacheKey] = ['resolved' => true, 'is_gif' => true, 'has_video' => true, 'has_audio' => false, 'source' => 'get_file_type'];
        }
        if (in_array($typeHint, ['video', 'movie'], true)) {
            return $cache[$cacheKey] = ['resolved' => true, 'is_gif' => false, 'has_video' => true, 'has_audio' => true, 'source' => 'get_file_type'];
        }

        $url = $this->firstScalarRecursive($file, ['download_url', 'downloadUrl', 'file_url', 'url']);
        if (!$this->isAllowedDownloadUrl($url)) {
            return $cache[$cacheKey] = [
                'resolved' => false,
                'is_gif' => false,
                'source' => 'invalid_download_url',
                'get_file_response' => $file,
            ];
        }

        $pathHint = mb_strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''), 'UTF-8');
        if (preg_match('~(?:^|[/_.-])(gif|animation|animated)(?:[/_.-]|$)~iu', $pathHint)) {
            return $cache[$cacheKey] = ['resolved' => true, 'is_gif' => true, 'has_video' => true, 'has_audio' => false, 'source' => 'download_url_hint'];
        }

        $settings = (array)($this->config['media_detection'] ?? []);
        $timeout = max(2, min(12, (int)($settings['probe_timeout_seconds'] ?? 5)));
        $maxBytes = max(131072, min(2097152, (int)($settings['probe_max_bytes'] ?? 524288)));

        $first = $this->downloadRange($url, 0, $maxBytes - 1, $timeout, $maxBytes);
        $bytes = (string)($first['body'] ?? '');
        $total = (int)($first['total_size'] ?? 0);

        if ($total > $maxBytes) {
            $start = max(0, $total - $maxBytes);
            $last = $this->downloadRange($url, $start, $total - 1, $timeout, $maxBytes);
            $bytes .= (string)($last['body'] ?? '');
        }

        $scan = $this->scanMp4Tracks($bytes, $fileName, (string)($first['content_type'] ?? ''));
        $scan['download_url_host'] = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        return $cache[$cacheKey] = $scan;
    }

    private function firstScalarRecursive(mixed $node, array $keys, int $depth = 0): string
    {
        if ($depth > 6 || !is_array($node)) return '';
        foreach ($node as $key => $value) {
            if (in_array((string)$key, $keys, true) && is_scalar($value)) {
                $value = trim((string)$value);
                if ($value !== '') return $value;
            }
        }
        foreach ($node as $value) {
            if (!is_array($value)) continue;
            $found = $this->firstScalarRecursive($value, $keys, $depth + 1);
            if ($found !== '') return $found;
        }
        return '';
    }

    private function isAllowedDownloadUrl(string $url): bool
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return false;
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (!in_array($scheme, ['https', 'http'], true) || $host === '') return false;
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return false;
        return true;
    }

    private function downloadRange(string $url, int $start, int $end, int $timeout, int $maxBytes): array
    {
        $headers = [];
        $body = '';
        $ch = curl_init($url);
        if ($ch === false) return ['body' => '', 'error' => 'curl_init_failed'];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RANGE => $start . '-' . $end,
            CURLOPT_USERAGENT => 'RubikaGroupManager/89',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $maxBytes): int {
                $remaining = $maxBytes - strlen($body);
                if ($remaining <= 0) return 0;
                if (strlen($chunk) > $remaining) {
                    $body .= substr($chunk, 0, $remaining);
                    return 0; // توقف دانلود؛ CURLE_WRITE_ERROR در این حالت قابل انتظار است.
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $total = 0;
        if (!empty($headers['content-range']) && preg_match('~/([0-9]+)$~', $headers['content-range'], $m)) {
            $total = (int)$m[1];
        } elseif (!empty($headers['content-length']) && is_numeric($headers['content-length'])) {
            $length = (int)$headers['content-length'];
            $total = ($http === 206) ? max($end + 1, $length) : $length;
        }

        return [
            'body' => $body,
            'http_status' => $http,
            'errno' => $errno,
            'error' => $error,
            'total_size' => $total,
            'content_type' => (string)($headers['content-type'] ?? ''),
        ];
    }

    private function scanMp4Tracks(string $bytes, string $fileName, string $contentType): array
    {
        $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        $looksMp4 = $extension === 'mp4'
            || str_contains(strtolower($contentType), 'video/mp4')
            || strpos(substr($bytes, 0, 128), 'ftyp') !== false;
        if (!$looksMp4 || $bytes === '') {
            return ['resolved' => false, 'is_gif' => false, 'source' => 'not_mp4_or_empty'];
        }

        $handlers = [];
        $offset = 0;
        while (($pos = strpos($bytes, 'hdlr', $offset)) !== false) {
            $handler = substr($bytes, $pos + 12, 4);
            if (strlen($handler) === 4) $handlers[] = $handler;
            $offset = $pos + 4;
        }

        $hasVideo = in_array('vide', $handlers, true)
            || strpos($bytes, 'avc1') !== false
            || strpos($bytes, 'hvc1') !== false
            || strpos($bytes, 'hev1') !== false
            || strpos($bytes, 'vp09') !== false;
        $hasAudio = in_array('soun', $handlers, true)
            || strpos($bytes, 'mp4a') !== false
            || strpos($bytes, 'Opus') !== false
            || strpos($bytes, 'ac-3') !== false
            || strpos($bytes, 'ec-3') !== false;

        if (!$hasVideo) {
            return [
                'resolved' => false,
                'is_gif' => false,
                'has_video' => false,
                'has_audio' => $hasAudio,
                'source' => 'mp4_tracks_not_found',
            ];
        }

        return [
            'resolved' => true,
            'is_gif' => !$hasAudio,
            'has_video' => true,
            'has_audio' => $hasAudio,
            'source' => 'mp4_track_probe',
        ];
    }

    public function deleteMessage(string $chatId, string $messageId): array
    {
        $res = $this->request('deleteMessage', ['chat_id'=>$chatId, 'message_id'=>$messageId], 10);
        $ok = (($res['status'] ?? '') === 'OK' || ($res['ok'] ?? false));
        $http = (int)($res['_http_status'] ?? $res['status'] ?? 0);
        if (!$ok && ($http === 0 || $http === 408 || $http === 429 || $http >= 500)) {
            usleep(120000);
            return $this->request('deleteMessage', ['chat_id'=>$chatId, 'message_id'=>$messageId], 10);
        }
        return $res;
    }

    public function editMessageText(string $chatId, string $messageId, string $text, ?array $inlineKeypad = null, ?array $chatKeypad = null): array
    {
        // طبق مستندات روبیکا editMessageText فقط متن را ویرایش می‌کند.
        // برای ویرایش دکمه‌ها باید جداگانه editMessageKeypad صدا زده شود.
        $originalText = $text;
        $prepared = $this->prepareFormattedText($text, null);
        $text = (string)$prepared['text'];
        $metadata = $prepared['metadata'];

        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];
        $parseMode = $this->markdownParseMode();
        if ($metadata) {
            $payload['metadata'] = $metadata;
        } elseif ($parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        $this->debugLog('editMessageText_payload', $payload);
        $res = $this->request('editMessageText', $payload, 10);
        $this->debugLog('editMessageText_response', $res);
        if ((string)($res['status'] ?? '') !== 'OK' && isset($payload['parse_mode'])) {
            $plainPayload = $this->withoutParseMode($payload);
            $this->debugLog('editMessageText_parse_mode_fallback_payload', $plainPayload);
            $plainRes = $this->request('editMessageText', $plainPayload, 10);
            $this->debugLog('editMessageText_parse_mode_fallback_response', $plainRes);
            return $plainRes;
        }
        if ((string)($res['status'] ?? '') !== 'OK' && $metadata) {
            $plainPayload = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $originalText,
            ];
            if ($parseMode !== '') $plainPayload['parse_mode'] = $parseMode;
            $this->debugLog('editMessageText_metadata_fallback_payload', $plainPayload);
            $plainRes = $this->request('editMessageText', $plainPayload, 10);
            $this->debugLog('editMessageText_metadata_fallback_response', $plainRes);
            return $plainRes;
        }
        return $res;
    }

    public function editMessageKeypad(string $chatId, string $messageId, array $inlineKeypad): array
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'inline_keypad' => $inlineKeypad,
        ];

        $this->debugLog('editMessageKeypad_payload', $payload);

        // نام متد در مستندات با عنوان editMessageKeypad آمده، ولی در مثال cURL نام editInlineKeypad دیده می‌شود.
        // اول نام مستندات را تست می‌کنیم؛ اگر روبیکا رد کرد، با نام مثال هم تلاش می‌کنیم.
        $res = $this->request('editMessageKeypad', $payload, 10);
        $this->debugLog('editMessageKeypad_response', $res);

        if (($res['status'] ?? '') === 'OK' || ($res['ok'] ?? false)) {
            return $res;
        }

        $fallback = $this->request('editInlineKeypad', $payload, 10);
        $this->debugLog('editInlineKeypad_fallback_response', $fallback);
        return $fallback;
    }

    public function setCommands(): array
    {
        return $this->request('setCommands', [
            'bot_commands' => [
                ['command'=>'start', 'description'=>'شروع'],
                ['command'=>'help', 'description'=>'راهنما'],
                ['command'=>'id', 'description'=>'نمایش شناسه‌ها'],
            ]
        ], 15);
    }

    public function updateBotEndpoint(string $url, string $type): array
    {
        return $this->request('updateBotEndpoints', ['url'=>$url, 'type'=>$type], 15);
    }



    public function promoteGroupAdmin(string $chatId, string $userId): array
    {
        return ['ok'=>false, 'status'=>'UNSUPPORTED', 'dev_message'=>'Bot API رسمی روبیکا متد ترفیع مدیر گروه ارائه نکرده است.'];
    }

    public function demoteGroupAdmin(string $chatId, string $userId): array
    {
        return ['ok'=>false, 'status'=>'UNSUPPORTED', 'dev_message'=>'Bot API رسمی روبیکا متد تنزیل مدیر گروه ارائه نکرده است.'];
    }


    public function experimentalKick(string $chatId, string $userId): array
    {
        $payload = ['chat_id'=>$chatId, 'user_id'=>$userId];
        if (!$this->hardBanEnabled()) {
            return ['ok'=>false, 'status'=>'DISABLED', 'dev_message'=>'hard ban is disabled'];
        }
        $ban = $this->request('banChatMember', $payload, 10);
        if (!$this->isSuccessful($ban)) return $ban + ['_method'=>'banChatMember'];
        $unban = $this->request('unbanChatMember', $payload, 10);
        if (!$this->isSuccessful($unban)) {
            return ['ok'=>false, 'status'=>'FAILED', 'dev_message'=>'کاربر بن شد ولی رفع بن برای تبدیل به اخراج انجام نشد.', 'ban_response'=>$ban, 'unban_response'=>$unban, '_method'=>'banChatMember+unbanChatMember'];
        }
        return ['ok'=>true, 'status'=>'OK', 'data'=>['status'=>'Done'], '_method'=>'banChatMember+unbanChatMember'];
    }

    public function experimentalHardBan(string $chatId, string $userId): array
    {
        if (!$this->hardBanEnabled()) {
            return ['ok'=>false, 'status'=>'DISABLED', 'dev_message'=>'hard ban is disabled'];
        }
        $payload = ['chat_id'=>$chatId, 'user_id'=>$userId];
        $res = $this->request('banChatMember', $payload, 10);
        return $res + ['_method'=>'banChatMember'];
    }

    public function experimentalUnban(string $chatId, string $userId): array
    {
        $payload = ['chat_id'=>$chatId, 'user_id'=>$userId];
        $res = $this->request('unbanChatMember', $payload, 10);
        return $res + ['_method'=>'unbanChatMember'];
    }

    private function isSuccessful(array $response): bool
    {
        return ($response['ok'] ?? false) === true
            || strtoupper((string)($response['status'] ?? '')) === 'OK'
            || strcasecmp((string)($response['data']['status'] ?? ''), 'Done') === 0;
    }

    private function hardBanEnabled(): bool
    {
        // در configهای قبل از v85 این گزینه وجود داشت ولی عملاً نادیده گرفته می‌شد؛ رفتار قبلی را حفظ می‌کنیم.
        if ((int)($this->config['config_version'] ?? 0) < 85) return true;
        return !empty($this->config['experimental']['hard_ban_enabled']);
    }
}
