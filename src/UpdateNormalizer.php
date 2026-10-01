<?php
namespace RubikaGM;

final class UpdateNormalizer
{
    public static function one(array $input): ?array
    {
        if (isset($input['update']) && is_array($input['update'])) {
            $input = $input['update'];
        }

        if (isset($input['data']['update']) && is_array($input['data']['update'])) {
            $input = $input['data']['update'];
        }

        $updateType = (string)($input['type'] ?? '');
        if (in_array($updateType, ['StartedBot', 'StartBot'], true)) {
            $chatId = (string)($input['chat_id'] ?? $input['user_id'] ?? '');
            $senderId = (string)($input['sender_id'] ?? $input['user_id'] ?? '');
            if ($chatId === '') return null;
            return [
                'type' => 'StartedBot',
                'chat_id' => $chatId,
                'message_id' => '',
                'sender_id' => $senderId,
                'text' => '/start',
                'button_id' => '',
                'message' => [],
                'raw' => $input,
                'reply_to_message_id' => '',
                'forwarded' => false,
                'has_file' => false,
                'has_sticker' => false,
                'has_contact' => false,
                'has_location' => false,
                'has_poll' => false,
            ];
        }

        if (in_array($updateType, ['StoppedBot', 'StopBot'], true)) {
            $chatId = (string)($input['chat_id'] ?? $input['user_id'] ?? '');
            $senderId = (string)($input['sender_id'] ?? $input['user_id'] ?? '');
            if ($chatId === '') return null;
            return [
                'type' => 'StoppedBot',
                'chat_id' => $chatId,
                'message_id' => '',
                'sender_id' => $senderId,
                'text' => '',
                'button_id' => '',
                'message' => [],
                'raw' => $input,
                'reply_to_message_id' => '',
                'forwarded' => false,
                'has_file' => false,
                'has_sticker' => false,
                'has_contact' => false,
                'has_location' => false,
                'has_poll' => false,
            ];
        }

        $leave = self::memberLeaveFromRaw($input);
        if ($leave !== null) {
            return [
                'type' => 'MemberLeft',
                'chat_id' => (string)$leave['chat_id'],
                'message_id' => (string)($leave['message_id'] ?: ('left_' . time() . '_' . mt_rand(1000, 9999))),
                'sender_id' => (string)($leave['user_id'] ?? ''),
                'text' => '',
                'button_id' => '',
                'chat_title' => (string)($leave['chat_title'] ?? ''),
                'message' => [],
                'raw' => $input,
                'reply_to_message_id' => '',
                'forwarded' => false,
                'has_file' => false,
                'has_sticker' => false,
                'has_contact' => false,
                'has_location' => false,
                'has_poll' => false,
                'member_left' => true,
                'left_user_id' => (string)($leave['user_id'] ?? ''),
                'left_user_name' => (string)($leave['user_name'] ?? ''),
            ];
        }

        $join = self::memberJoinFromRaw($input);
        if ($join !== null) {
            return [
                'type' => 'MemberJoined',
                'chat_id' => (string)$join['chat_id'],
                'message_id' => (string)($join['message_id'] ?: ('join_' . time() . '_' . mt_rand(1000, 9999))),
                'sender_id' => (string)($join['user_id'] ?? ''),
                'text' => '',
                'button_id' => '',
                'chat_title' => (string)($join['chat_title'] ?? ''),
                'message' => [],
                'raw' => $input,
                'reply_to_message_id' => '',
                'forwarded' => false,
                'has_file' => false,
                'has_sticker' => false,
                'has_contact' => false,
                'has_location' => false,
                'has_poll' => false,
                'member_joined' => true,
                'join_user_id' => (string)($join['user_id'] ?? ''),
                'join_user_name' => (string)($join['user_name'] ?? ''),
                // شناسه مدیری که عضو/ربات را به گروه افزوده؛ با شناسه عضو جدید یکی نیست.
                'join_actor_id' => (string)($join['actor_id'] ?? ''),
                'join_actor_name' => (string)($join['actor_name'] ?? ''),
            ];
        }

        $inline =
            $input['inline_message'] ??
            $input['data']['inline_message'] ??
            $input['InlineMessage'] ??
            null;

        // بعضی آپدیت‌های کلیک دکمه شیشه‌ای به جای inline_message، button_id را در ریشه یا aux_data می‌فرستند.
        $rootAux = $input['aux_data'] ?? $input['data']['aux_data'] ?? [];
        if (!is_array($rootAux)) {
            $rootAux = [];
        }
        $rootButtonId = (string)(
            $rootAux['button_id'] ??
            $input['button_id'] ??
            $input['data']['button_id'] ??
            $input['callback_data'] ??
            $input['data']['callback_data'] ??
            ''
        );
        if ($rootButtonId !== '' && !is_array($inline)) {
            $chatId = (string)(
                $input['chat_id'] ??
                $input['data']['chat_id'] ??
                $input['user_id'] ??
                $input['data']['user_id'] ??
                ''
            );
            $senderId = (string)(
                $input['sender_id'] ??
                $input['data']['sender_id'] ??
                $input['user_id'] ??
                $input['data']['user_id'] ??
                ''
            );
            return [
                'type' => (string)($input['type'] ?? 'ButtonClick'),
                'chat_id' => $chatId,
                'message_id' => (string)($input['message_id'] ?? $input['data']['message_id'] ?? ''),
                'sender_id' => $senderId,
                'text' => (string)($input['text'] ?? $input['data']['text'] ?? ''),
                'button_id' => $rootButtonId,
                'chat_title' => (string)($input['chat_title'] ?? $input['group_title'] ?? ''),
                'message' => [],
                'raw' => $input,
                'reply_to_message_id' => '',
                'forwarded' => false,
                'has_file' => false,
                'has_sticker' => false,
                'has_contact' => false,
                'has_location' => false,
                'has_poll' => false,
            ];
        }

        if (is_array($inline)) {
            $aux = $inline['aux_data'] ?? [];
            return [
                'type' => 'InlineMessage',
                'chat_id' => (string)($inline['chat_id'] ?? $input['chat_id'] ?? $input['user_id'] ?? ''),
                'message_id' => (string)($inline['message_id'] ?? $input['message_id'] ?? ''),
                'sender_id' => (string)($inline['sender_id'] ?? $inline['user_id'] ?? $input['sender_id'] ?? $input['user_id'] ?? ''),
                'text' => (string)($inline['text'] ?? $input['text'] ?? ''),
                'button_id' => (string)($aux['button_id'] ?? $inline['button_id'] ?? $input['button_id'] ?? ''),
                'chat_title' => (string)($input['chat_title'] ?? $input['group_title'] ?? $inline['chat_title'] ?? $inline['group_title'] ?? ''),
                'message' => $inline,
                'raw' => $input,
                'reply_to_message_id' => '',
                'forwarded' => false,
                'has_file' => isset($inline['file']) && is_array($inline['file']),
                'has_sticker' => false,
                'has_contact' => false,
                'has_location' => isset($inline['location']) && is_array($inline['location']),
                'has_poll' => false,
            ];
        }

        $message =
            $input['new_message'] ??
            $input['message'] ??
            $input['updated_message'] ??
            null;

        if (!is_array($message)) {
            return null;
        }

        $aux = $message['aux_data'] ?? [];

        $chatId = (string)(
            $input['chat_id'] ??
            $message['chat_id'] ??
            ''
        );

        $messageId = (string)(
            $message['message_id'] ??
            $input['message_id'] ??
            ''
        );

        $senderId = (string)(
            $message['sender_id'] ??
            $message['from_id'] ??
            $message['author_object_guid'] ??
            $message['user_id'] ??
            ''
        );

        $text = (string)(
            $message['text'] ??
            $message['caption'] ??
            ''
        );

        if ($chatId === '' || $messageId === '') {
            return null;
        }

        $content = self::detectContent($message, $text);
        $fileSize = (int)$content['file_size'];
        $hasCaption = isset($message['caption']) && trim((string)$message['caption']) !== '';
        $hasFile = (bool)$content['has_file'];
        $isGif = (bool)$content['has_gif'];
        $isPhoto = (bool)$content['has_photo'];
        $isVideo = (bool)$content['has_video'];
        $isVoice = (bool)$content['has_voice'];
        $isMusic = (bool)$content['has_music'];
        $hasDocument = (bool)$content['has_document'];
        $hasMetadata = self::hasNestedKey($message, ['metadata', 'meta_data', 'message_metadata']);
        $msgJoin = self::memberJoinFromMessage($input, $message);
        $msgLeave = self::memberLeaveFromMessage($input, $message);
        $replyToMessageId = self::replyToMessageId($message);
        $replySenderId = self::replySenderId($message);
        $replySenderName = self::replySenderName($message);

        return [
            'type' => (string)($input['type'] ?? 'NewMessage'),
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'sender_id' => $senderId,
            'text' => $text,
            'button_id' => (string)($aux['button_id'] ?? $message['button_id'] ?? ''),
            'message' => $message,
            'raw' => $input,
            'reply_to_message_id' => $replyToMessageId,
            'reply_sender_id' => $replySenderId,
            'reply_sender_name' => $replySenderName,
            'forwarded' => isset($message['forwarded_from']) || isset($message['forwarded_message_id']) || isset($message['forwarded_from_chat']) || !empty($message['forwarded_no_link']) || !empty($message['is_forwarded']),
            'has_file' => (bool)$hasFile,
            'has_document' => (bool)$hasDocument,
            'has_sticker' => (bool)$content['has_sticker'],
            'has_contact' => (bool)$content['has_contact'],
            'has_location' => (bool)$content['has_location'],
            'has_poll' => (bool)$content['has_poll'],
            'has_text' => trim($text) !== '',
            'has_caption' => $hasCaption,
            'has_photo' => (bool)$isPhoto,
            'has_video' => (bool)$isVideo,
            'has_voice' => (bool)$isVoice,
            'has_gif' => (bool)$isGif,
            'has_music' => (bool)$isMusic,
            'has_reply' => $replyToMessageId !== '',
            'is_edited' => !empty($message['is_edited']) || !empty($input['is_edited']) || in_array((string)($input['type'] ?? ''), ['EditedMessage','UpdatedMessage'], true),
            'has_metadata' => (bool)$hasMetadata,
            'file_id' => (string)($content['file_id'] ?? ''),
            'file_name' => (string)($content['file_name'] ?? ''),
            'file_extension' => (string)($content['file_extension'] ?? ''),
            'file_size' => $fileSize,
            'content_type' => (string)$content['content_type'],
            'content_type_ambiguous' => !empty($content['content_type_ambiguous']),
            'content_type_reliable' => !empty($content['content_type_reliable']),
            'content_fingerprint' => (string)$content['content_fingerprint'],
            'member_joined' => $msgJoin !== null,
            'join_user_id' => $msgJoin['user_id'] ?? '',
            'join_user_name' => $msgJoin['user_name'] ?? '',
            'join_actor_id' => $msgJoin['actor_id'] ?? '',
            'join_actor_name' => $msgJoin['actor_name'] ?? '',
            'member_left' => $msgLeave !== null,
            'left_user_id' => $msgLeave['user_id'] ?? '',
            'left_user_name' => $msgLeave['user_name'] ?? '',
        ];
    }

    /**
     * ساختار پیام در نسخه‌ها و کلاینت‌های مختلف روبیکا یکسان نیست. این تابع
     * کلیدهای مستقیم، file_inline/attachment و type/mime را هم‌زمان بررسی می‌کند.
     */
    private static function detectContent(array $message, string $text): array
    {
        $explicit = static fn(array $keys): bool => self::hasPayloadKey($message, $keys);
        $nodes = self::contentNodes($message);
        $rootFileFields = array_intersect_key($message, array_flip([
            'file_id', 'file_unique_id', 'media_id', 'object_guid', 'file_name', 'filename',
            'mime_type', 'mime', 'mimetype', 'content_type', 'file_type', 'media_type',
            'size', 'file_size', 'filesize', 'fileSize', 'bytes', 'length',
        ]));
        $fieldNodes = $nodes;
        if ($rootFileFields !== []) $fieldNodes[] = $rootFileFields;
        if ($fieldNodes === []) $fieldNodes = [$message];
        $hints = self::contentHints($message, $nodes);
        $hintText = mb_strtolower(implode(' ', $hints), 'UTF-8');

        $fileName = self::firstNestedString($fieldNodes, ['file_name', 'filename', 'name', 'title']);
        $mime = mb_strtolower(self::firstNestedString($fieldNodes, ['mime_type', 'mime', 'mimetype', 'content_type']), 'UTF-8');
        $fileType = mb_strtolower(self::firstNestedString($fieldNodes, ['file_type', 'media_type', 'message_type', 'object_type', 'type', 'kind']), 'UTF-8');
        $fileSize = self::largestNestedInt($fieldNodes, ['size', 'file_size', 'filesize', 'fileSize', 'bytes', 'length']);
        $fileId = self::firstNestedString($fieldNodes, ['file_id', 'file_unique_id', 'media_id', 'object_guid', 'id', 'guid']);
        $fileExtension = mb_strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION), 'UTF-8');
        $truthy = static fn(array $keys): bool => self::hasTruthyNestedKey($message, $keys);

        $hasSticker = $explicit(['sticker', 'sticker_message', 'sticker_data', 'sticker_id'])
            || $truthy(['is_sticker', 'has_sticker'])
            || self::hintMatches($hintText . ' ' . $fileType, ['sticker']);

        $gifExplicit = $explicit(['gif', 'animation', 'animated_gif', 'gif_message', 'animation_message'])
            || $truthy(['is_gif', 'has_gif', 'is_animation', 'is_animated', 'animated'])
            || self::hintMatches($hintText . ' ' . $fileType, ['gif', 'animation', 'animated'])
            || str_contains($mime, 'image/gif')
            || $fileExtension === 'gif';
        $hasGif = $gifExplicit;

        $hasVoice = $explicit(['voice', 'voice_message', 'voice_note'])
            || $truthy(['is_voice', 'has_voice', 'is_voice_note'])
            || self::hintMatches($hintText . ' ' . $fileType, ['voice', 'voice_message', 'voice_note'])
            || str_contains($mime, 'audio/ogg')
            || str_contains($mime, 'audio/opus');

        $hasMusic = $explicit(['audio', 'music', 'audio_message'])
            || $truthy(['is_audio', 'has_audio', 'is_music', 'has_music'])
            || self::hintMatches($hintText . ' ' . $fileType, ['audio', 'music', 'song'])
            || (str_starts_with($mime, 'audio/') && !$hasVoice)
            || in_array($fileExtension, ['mp3', 'm4a', 'aac', 'flac', 'wav', 'oga', 'wma'], true);

        $videoExplicit = $explicit(['video', 'video_message', 'video_note', 'round_video'])
            || $truthy(['is_video', 'has_video'])
            || self::hintMatches($hintText . ' ' . $fileType, ['video', 'movie']);
        $hasVideo = $videoExplicit
            || str_starts_with($mime, 'video/')
            || in_array($fileExtension, ['mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi', '3gp', 'mpeg', 'mpg'], true);

        $hasPhoto = $explicit(['photo', 'image', 'picture', 'image_message'])
            || $truthy(['is_photo', 'has_photo', 'is_image', 'has_image'])
            || self::hintMatches($hintText . ' ' . $fileType, ['photo', 'image', 'picture'])
            || str_starts_with($mime, 'image/')
            || in_array($fileExtension, ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'heic', 'heif', 'avif'], true);

        // GIF، ویدیو، ویس و استیکر گاهی همراه thumbnail تصویری می‌آیند؛
        // تصویر بندانگشتی نباید نوع اصلی پیام را به «عکس» تبدیل کند.
        if ($hasGif || $hasSticker || $hasVideo || $hasVoice || $hasMusic) {
            $hasPhoto = false;
        }
        // قفل گیف و ویدیو مستقل‌اند؛ GIF صریح نباید هم‌زمان ویدیو محسوب شود.
        if ($hasGif) {
            $hasVideo = false;
        }

        $hasContact = $explicit(['contact', 'contact_message', 'contact_data'])
            || self::hintMatches($hintText, ['contact']);
        $hasLocation = $explicit(['location', 'live_location', 'geo', 'venue'])
            || self::hintMatches($hintText, ['location', 'live_location', 'geo', 'venue']);
        $hasPoll = $explicit(['poll', 'poll_message', 'quiz'])
            || self::hintMatches($hintText, ['poll', 'quiz']);

        $hasGenericFile = $explicit(['file', 'document', 'document_message', 'file_inline', 'file_info', 'attachment', 'attachments'])
            || self::hintMatches($hintText . ' ' . $fileType, ['file', 'document', 'attachment'])
            || $fileName !== ''
            || $mime !== ''
            || $fileSize > 0;
        $hasMedia = $hasPhoto || $hasVideo || $hasVoice || $hasGif || $hasMusic;
        $hasDocument = $hasGenericFile && !$hasMedia && !$hasSticker && !$hasContact && !$hasLocation && !$hasPoll;
        $hasFile = ($hasGenericFile && !$hasSticker && !$hasContact && !$hasLocation && !$hasPoll) || $hasMedia;

        // مدل File در Bot API رسمی روبیکا همیشه نوع رسانه را اعلام نمی‌کند.
        // GIF روبیکا نیز MP4 بدون صداست؛ MP4 فاقد type صریح را برای بررسی ثانویه علامت می‌زنیم.
        $mp4Like = $fileExtension === 'mp4' || str_contains($mime, 'video/mp4');
        $unknownTypedFile = $fileId !== '' && $fileExtension === '' && $mime === '' && $fileType === '' && $hasGenericFile;
        $contentTypeAmbiguous = ($mp4Like || $unknownTypedFile) && !$gifExplicit && !$videoExplicit
            && !self::hintMatches($hintText . ' ' . $fileType, ['gif', 'animation', 'video', 'movie']);
        $contentTypeReliable = !$contentTypeAmbiguous;

        $contentType = 'unknown';
        if ($hasSticker) $contentType = 'sticker';
        elseif ($hasGif) $contentType = 'gif';
        elseif ($hasVideo) $contentType = 'video';
        elseif ($hasVoice) $contentType = 'voice';
        elseif ($hasMusic) $contentType = 'music';
        elseif ($hasPhoto) $contentType = 'photo';
        elseif ($hasDocument) $contentType = 'file';
        elseif ($hasContact) $contentType = 'contact';
        elseif ($hasLocation) $contentType = 'location';
        elseif ($hasPoll) $contentType = 'poll';
        elseif (trim($text) !== '') $contentType = 'text';

        $fingerprintParts = ['type=' . $contentType];
        if (trim($text) !== '') {
            $normalizedText = preg_replace('/\\s+/u', ' ', trim($text)) ?: trim($text);
            $fingerprintParts[] = 'text=' . mb_strtolower($normalizedText, 'UTF-8');
        }
        if (!in_array($contentType, ['text', 'unknown'], true)) {
            foreach (['file_id', 'file_unique_id', 'sticker_id', 'animation_id', 'media_id', 'id', 'object_guid', 'access_hash', 'hash', 'dc_id'] as $key) {
                $value = self::firstNestedString($fieldNodes, [$key]);
                if ($value !== '') $fingerprintParts[] = $key . '=' . $value;
            }
        }
        if ($fileName !== '') $fingerprintParts[] = 'name=' . mb_strtolower($fileName, 'UTF-8');
        if ($mime !== '') $fingerprintParts[] = 'mime=' . $mime;
        if ($fileType !== '') $fingerprintParts[] = 'file_type=' . $fileType;
        if ($fileSize > 0) $fingerprintParts[] = 'size=' . $fileSize;

        // اگر شناسه‌ای وجود نداشت، فقط payload محتوایی را وارد اثر انگشت می‌کنیم؛
        // message_id و sender_id عمداً وارد نمی‌شوند تا تکرار همان رسانه قابل تشخیص باشد.
        if (count($fingerprintParts) === 1 && $contentType !== 'unknown') {
            $payload = self::firstContentPayload($message);
            if ($payload !== null) {
                $fingerprintParts[] = 'payload=' . Support::jsonEncode($payload);
            }
        }

        return [
            'has_file' => $hasFile,
            'has_document' => $hasDocument,
            'has_sticker' => $hasSticker,
            'has_contact' => $hasContact,
            'has_location' => $hasLocation,
            'has_poll' => $hasPoll,
            'has_photo' => $hasPhoto,
            'has_video' => $hasVideo,
            'has_voice' => $hasVoice,
            'has_gif' => $hasGif,
            'has_music' => $hasMusic,
            'file_id' => $fileId,
            'file_name' => $fileName,
            'file_extension' => $fileExtension,
            'file_size' => $fileSize,
            'content_type' => $contentType,
            'content_type_ambiguous' => $contentTypeAmbiguous,
            'content_type_reliable' => $contentTypeReliable,
            'content_fingerprint' => hash('sha256', implode('|', $fingerprintParts)),
        ];
    }

    private static function hasPayloadKey(array $message, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $message)) continue;
            $value = $message[$key];
            if ($value === null || $value === false || $value === '' || $value === []) continue;
            return true;
        }
        return false;
    }

    private static function hasTruthyNestedKey(mixed $node, array $keys, int $depth = 0): bool
    {
        if ($depth > 5 || !is_array($node)) return false;
        foreach ($node as $key => $value) {
            if (in_array((string)$key, $keys, true)) {
                if (is_bool($value) && $value) return true;
                if (is_int($value) && $value === 1) return true;
                if (is_string($value) && in_array(mb_strtolower(trim($value), 'UTF-8'), ['1', 'true', 'yes', 'on', 'gif', 'animation'], true)) return true;
            }
            if (is_array($value) && self::hasTruthyNestedKey($value, $keys, $depth + 1)) return true;
        }
        return false;
    }

    private static function contentNodes(array $message): array
    {
        $keys = [
            'file', 'document', 'document_message', 'file_inline', 'file_info', 'attachment', 'attachments', 'media',
            'photo', 'image', 'picture', 'image_message', 'video', 'video_message', 'video_note', 'round_video',
            'voice', 'voice_message', 'voice_note', 'audio', 'audio_message', 'music',
            'gif', 'animation', 'animated_gif', 'sticker', 'sticker_message', 'sticker_data', 'sticker_id',
            'contact', 'contact_message', 'contact_data', 'location', 'live_location', 'geo', 'venue',
            'poll', 'poll_message', 'quiz',
        ];
        $nodes = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $message)) {
                $nodes[] = $message[$key];
            }
        }
        return $nodes;
    }

    private static function contentHints(array $message, array $nodes): array
    {
        $hints = [];
        foreach (['type', 'message_type', 'object_type', 'file_type', 'media_type', 'kind', 'mime_type', 'mime', 'content_type'] as $key) {
            if (isset($message[$key]) && is_scalar($message[$key])) {
                $hints[] = (string)$message[$key];
            }
        }
        foreach ($nodes as $node) {
            self::collectNestedValues($node, ['type', 'message_type', 'object_type', 'file_type', 'media_type', 'kind', 'mime_type', 'mime', 'content_type'], $hints, 0);
        }
        return array_values(array_unique(array_filter(array_map('strval', $hints), static fn(string $v): bool => trim($v) !== '')));
    }

    private static function collectNestedValues(mixed $node, array $wantedKeys, array &$out, int $depth): void
    {
        if ($depth > 4 || !is_array($node)) return;
        foreach ($node as $key => $value) {
            if (in_array((string)$key, $wantedKeys, true) && is_scalar($value)) {
                $out[] = (string)$value;
            }
            if (is_array($value)) {
                self::collectNestedValues($value, $wantedKeys, $out, $depth + 1);
            }
        }
    }

    private static function firstNestedString(array $nodes, array $keys): string
    {
        $values = [];
        foreach ($nodes as $node) {
            self::collectNestedValues($node, $keys, $values, 0);
        }
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') return $value;
        }
        return '';
    }

    private static function largestNestedInt(array $nodes, array $keys): int
    {
        $values = [];
        foreach ($nodes as $node) {
            self::collectNestedValues($node, $keys, $values, 0);
        }
        $max = 0;
        foreach ($values as $value) {
            if (is_numeric($value)) $max = max($max, (int)$value);
        }
        return $max;
    }

    private static function hintMatches(string $haystack, array $needles): bool
    {
        $haystack = mb_strtolower($haystack, 'UTF-8');
        foreach ($needles as $needle) {
            if (preg_match('/(?:^|[^a-z0-9])' . preg_quote($needle, '/') . '(?:$|[^a-z0-9])/iu', $haystack)) {
                return true;
            }
        }
        return false;
    }

    private static function hasNestedKey(mixed $node, array $keys, int $depth = 0): bool
    {
        if ($depth > 4 || !is_array($node)) return false;
        foreach ($node as $key => $value) {
            if (in_array((string)$key, $keys, true) && $value !== null && $value !== false && $value !== '' && $value !== []) {
                return true;
            }
            if (is_array($value) && self::hasNestedKey($value, $keys, $depth + 1)) {
                return true;
            }
        }
        return false;
    }

    private static function firstContentPayload(array $message): mixed
    {
        foreach (['sticker', 'sticker_id', 'gif', 'animation', 'photo', 'image', 'video', 'voice', 'audio', 'music', 'document', 'file', 'file_inline', 'attachment', 'media', 'contact', 'location', 'poll'] as $key) {
            if (array_key_exists($key, $message)) return $message[$key];
        }
        return null;
    }

    private static function replyToMessageId(array $message): string
    {
        foreach (['reply_to_message_id', 'reply_message_id', 'reply_id', 'replied_message_id', 'quoted_message_id'] as $k) {
            $v = trim((string)($message[$k] ?? ''));
            if ($v !== '') return $v;
        }
        foreach (['reply_to_message', 'reply_message', 'replied_message', 'quoted_message'] as $k) {
            if (isset($message[$k]) && is_array($message[$k])) {
                $reply = $message[$k];
                foreach (['message_id', 'id', 'messageId'] as $rk) {
                    $v = trim((string)($reply[$rk] ?? ''));
                    if ($v !== '') return $v;
                }
            }
        }
        return '';
    }

    private static function replySenderId(array $message): string
    {
        foreach (['reply_sender_id', 'reply_to_sender_id', 'replied_sender_id', 'reply_user_id'] as $k) {
            $v = trim((string)($message[$k] ?? ''));
            if ($v !== '') return $v;
        }
        foreach (['reply_to_message', 'reply_message', 'replied_message', 'quoted_message'] as $k) {
            if (isset($message[$k]) && is_array($message[$k])) {
                $reply = $message[$k];
                foreach (['sender_id', 'from_id', 'author_object_guid', 'user_id', 'object_guid', 'guid'] as $rk) {
                    $v = trim((string)($reply[$rk] ?? ''));
                    if ($v !== '') return $v;
                }
                foreach (['sender', 'author', 'user', 'from'] as $parent) {
                    if (isset($reply[$parent]) && is_array($reply[$parent])) {
                        foreach (['sender_id', 'user_id', 'object_guid', 'guid', 'id'] as $rk) {
                            $v = trim((string)($reply[$parent][$rk] ?? ''));
                            if ($v !== '') return $v;
                        }
                    }
                }
            }
        }
        return '';
    }

    private static function replySenderName(array $message): string
    {
        foreach (['reply_sender_name', 'reply_to_sender_name', 'replied_sender_name', 'reply_user_name'] as $k) {
            $v = trim((string)($message[$k] ?? ''));
            if ($v !== '') return mb_substr($v, 0, 40, 'UTF-8');
        }
        foreach (['reply_to_message', 'reply_message', 'replied_message', 'quoted_message'] as $k) {
            if (isset($message[$k]) && is_array($message[$k])) {
                $reply = $message[$k];
                foreach (['sender_name','author_name','first_name','last_name','name','title','sender_title','username'] as $rk) {
                    $v = trim((string)($reply[$rk] ?? ''));
                    if ($v !== '') return mb_substr($v, 0, 40, 'UTF-8');
                }
                foreach (['sender','author','user','from'] as $parent) {
                    if (isset($reply[$parent]) && is_array($reply[$parent])) {
                        $first = trim((string)($reply[$parent]['first_name'] ?? ''));
                        $last = trim((string)($reply[$parent]['last_name'] ?? ''));
                        $name = trim($first . ' ' . $last);
                        if ($name !== '') return mb_substr($name, 0, 40, 'UTF-8');
                        foreach (['name','title','username'] as $rk) {
                            $v = trim((string)($reply[$parent][$rk] ?? ''));
                            if ($v !== '') return mb_substr($v, 0, 40, 'UTF-8');
                        }
                    }
                }
            }
        }
        return '';
    }

    private static function memberJoinFromRaw(array $input): ?array
    {
        $type = strtolower((string)($input['type'] ?? $input['event'] ?? $input['action'] ?? ''));
        $looks = false;
        foreach (['join', 'joined', 'addmember', 'addedmember', 'newmember', 'newchatmember', 'memberadded', 'عضو'] as $kw) {
            if ($type !== '' && str_contains($type, strtolower($kw))) {
                $looks = true;
                break;
            }
        }

        // کلید عمومی member به‌تنهایی نوع رویداد را مشخص نمی‌کند؛ فقط وقتی type واقعاً join است از آن استفاده می‌کنیم.
        $member = $input['new_member'] ?? $input['new_chat_member'] ?? $input['added_member'] ?? null;
        $members = $input['new_members'] ?? $input['new_chat_members'] ?? $input['added_members'] ?? null;
        if (!$member && is_array($members) && isset($members[0]) && is_array($members[0])) {
            $member = $members[0];
        }
        if (!$member && $looks) {
            $member = $input['member'] ?? $input['user'] ?? null;
        }

        $hasExplicitJoinId = isset($input['joined_user_id']) || isset($input['new_member_id']) || isset($input['added_user_id']);
        if (!$looks && !$member && !$hasExplicitJoinId) {
            return null;
        }

        $chatId = (string)($input['chat_id'] ?? $input['group_id'] ?? $input['object_guid'] ?? '');
        if ($chatId === '') {
            return null;
        }

        $userId = '';
        $name = '';
        if (is_array($member)) {
            $userId = (string)($member['user_id'] ?? $member['sender_id'] ?? $member['member_guid'] ?? $member['object_guid'] ?? $member['guid'] ?? '');
            $first = trim((string)($member['first_name'] ?? ''));
            $last = trim((string)($member['last_name'] ?? ''));
            $name = trim($first . ' ' . $last);
            if ($name === '') {
                $name = trim((string)($member['name'] ?? $member['title'] ?? $member['username'] ?? ''));
            }
        }
        if ($userId === '') {
            $userId = (string)($input['joined_user_id'] ?? $input['new_member_id'] ?? $input['added_user_id'] ?? $input['member_guid'] ?? $input['user_id'] ?? '');
        }
        if ($name === '') {
            $name = trim((string)($input['joined_user_name'] ?? $input['member_name'] ?? $input['user_name'] ?? ''));
        }

        // در رویداد اضافه‌شدن عضو، sender_id/actor_id معمولاً شخص انجام‌دهنده عملیات است؛
        // شناسه عضو افزوده‌شده از member خوانده می‌شود. این دو مقدار نباید با هم جایگزین شوند.
        $actor = $input['actor'] ?? $input['added_by'] ?? $input['inviter'] ?? $input['admin'] ?? null;
        $actorId = trim((string)(
            $input['actor_id'] ??
            $input['added_by_user_id'] ??
            $input['added_by_id'] ??
            $input['inviter_id'] ??
            $input['adder_id'] ??
            $input['performed_by'] ??
            $input['sender_id'] ??
            ''
        ));
        $actorName = trim((string)(
            $input['actor_name'] ??
            $input['added_by_name'] ??
            $input['inviter_name'] ??
            $input['sender_name'] ??
            ''
        ));
        if (is_array($actor)) {
            if ($actorId === '') {
                $actorId = trim((string)($actor['user_id'] ?? $actor['sender_id'] ?? $actor['object_guid'] ?? $actor['guid'] ?? $actor['id'] ?? ''));
            }
            if ($actorName === '') {
                $first = trim((string)($actor['first_name'] ?? ''));
                $last = trim((string)($actor['last_name'] ?? ''));
                $actorName = trim($first . ' ' . $last);
                if ($actorName === '') {
                    $actorName = trim((string)($actor['name'] ?? $actor['title'] ?? $actor['username'] ?? ''));
                }
            }
        }
        // بعضی payloadها sender_id را برابر خود عضو جدید می‌فرستند؛ در این حالت actor نامعتبر است.
        if ($actorId !== '' && $userId !== '' && hash_equals($userId, $actorId)) {
            $actorId = '';
            $actorName = '';
        }

        return [
            'chat_id' => $chatId,
            'message_id' => (string)($input['message_id'] ?? ''),
            'user_id' => $userId,
            'user_name' => $name,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'chat_title' => (string)($input['chat_title'] ?? $input['group_title'] ?? ''),
        ];
    }

    private static function memberJoinFromMessage(array $input, array $message): ?array
    {
        foreach (['new_chat_member', 'new_member', 'added_member', 'member'] as $k) {
            if (isset($message[$k]) && is_array($message[$k])) {
                $raw = $input;
                $raw['member'] = $message[$k];
                $raw['chat_id'] = $raw['chat_id'] ?? $message['chat_id'] ?? '';
                $raw['message_id'] = $message['message_id'] ?? '';
                $raw['actor_id'] = $raw['actor_id'] ?? $message['sender_id'] ?? $message['from_id'] ?? $message['author_object_guid'] ?? '';
                $raw['type'] = 'NewChatMember';
                return self::memberJoinFromRaw($raw);
            }
        }
        foreach (['new_chat_members', 'new_members', 'added_members'] as $k) {
            if (isset($message[$k]) && is_array($message[$k])) {
                $raw = $input;
                $raw['new_members'] = $message[$k];
                $raw['chat_id'] = $raw['chat_id'] ?? $message['chat_id'] ?? '';
                $raw['message_id'] = $message['message_id'] ?? '';
                $raw['actor_id'] = $raw['actor_id'] ?? $message['sender_id'] ?? $message['from_id'] ?? $message['author_object_guid'] ?? '';
                $raw['type'] = 'NewChatMember';
                return self::memberJoinFromRaw($raw);
            }
        }
        $type = strtolower((string)($input['type'] ?? $message['type'] ?? ''));
        if (str_contains($type, 'join') || str_contains($type, 'newmember') || str_contains($type, 'addmember')) {
            $raw = $input;
            $raw['chat_id'] = $raw['chat_id'] ?? $message['chat_id'] ?? '';
            $raw['message_id'] = $message['message_id'] ?? '';
            $raw['actor_id'] = $raw['actor_id'] ?? $message['sender_id'] ?? $message['from_id'] ?? $message['author_object_guid'] ?? '';
            return self::memberJoinFromRaw($raw);
        }
        return null;
    }


    private static function memberLeaveFromRaw(array $input): ?array
    {
        $type = strtolower((string)($input['type'] ?? $input['event'] ?? $input['action'] ?? ''));
        $looks = false;
        foreach (['leave', 'left', 'remove', 'removed', 'kick', 'kicked', 'delete_member', 'memberleft', 'memberremoved', 'chatmemberleft', 'خروج', 'حذف'] as $kw) {
            if ($type !== '' && str_contains($type, strtolower($kw))) {
                $looks = true;
                break;
            }
        }

        // کلید عمومی member در رویدادهای join هم وجود دارد؛ بدون type خروج نباید آن را MemberLeft فرض کنیم.
        $member = $input['left_member'] ?? $input['old_member'] ?? $input['removed_member'] ?? $input['kicked_member'] ?? null;
        $members = $input['left_members'] ?? $input['removed_members'] ?? $input['kicked_members'] ?? null;
        if (!$member && is_array($members) && isset($members[0]) && is_array($members[0])) {
            $member = $members[0];
        }
        if (!$member && $looks) {
            $member = $input['member'] ?? $input['user'] ?? null;
        }

        $hasExplicitLeaveId = isset($input['left_user_id']) || isset($input['removed_user_id']) || isset($input['kicked_user_id']);
        if (!$looks && !$member && !$hasExplicitLeaveId) {
            return null;
        }

        $chatId = (string)($input['chat_id'] ?? $input['group_id'] ?? $input['object_guid'] ?? '');
        if ($chatId === '') {
            return null;
        }

        $userId = '';
        $name = '';
        if (is_array($member)) {
            $userId = (string)($member['user_id'] ?? $member['sender_id'] ?? $member['member_guid'] ?? $member['object_guid'] ?? $member['guid'] ?? '');
            $first = trim((string)($member['first_name'] ?? ''));
            $last = trim((string)($member['last_name'] ?? ''));
            $name = trim($first . ' ' . $last);
            if ($name === '') {
                $name = trim((string)($member['name'] ?? $member['title'] ?? $member['username'] ?? ''));
            }
        }
        if ($userId === '') {
            $userId = (string)($input['left_user_id'] ?? $input['removed_user_id'] ?? $input['kicked_user_id'] ?? $input['member_guid'] ?? $input['user_id'] ?? '');
        }
        if ($name === '') {
            $name = trim((string)($input['left_user_name'] ?? $input['removed_user_name'] ?? $input['member_name'] ?? $input['user_name'] ?? ''));
        }

        return [
            'chat_id' => $chatId,
            'message_id' => (string)($input['message_id'] ?? ''),
            'user_id' => $userId,
            'user_name' => $name,
            'chat_title' => (string)($input['chat_title'] ?? $input['group_title'] ?? ''),
        ];
    }

    private static function memberLeaveFromMessage(array $input, array $message): ?array
    {
        foreach (['left_chat_member', 'left_member', 'removed_member', 'kicked_member', 'old_member'] as $k) {
            if (isset($message[$k]) && is_array($message[$k])) {
                $raw = $input;
                $raw['member'] = $message[$k];
                $raw['chat_id'] = $raw['chat_id'] ?? $message['chat_id'] ?? '';
                $raw['message_id'] = $message['message_id'] ?? '';
                $raw['type'] = 'MemberLeft';
                return self::memberLeaveFromRaw($raw);
            }
        }
        foreach (['left_chat_members', 'left_members', 'removed_members', 'kicked_members'] as $k) {
            if (isset($message[$k]) && is_array($message[$k])) {
                $raw = $input;
                $raw['left_members'] = $message[$k];
                $raw['chat_id'] = $raw['chat_id'] ?? $message['chat_id'] ?? '';
                $raw['message_id'] = $message['message_id'] ?? '';
                $raw['type'] = 'MemberLeft';
                return self::memberLeaveFromRaw($raw);
            }
        }
        $type = strtolower((string)($input['type'] ?? $message['type'] ?? ''));
        if (str_contains($type, 'leave') || str_contains($type, 'left') || str_contains($type, 'remove') || str_contains($type, 'kick')) {
            $raw = $input;
            $raw['chat_id'] = $raw['chat_id'] ?? $message['chat_id'] ?? '';
            $raw['message_id'] = $message['message_id'] ?? '';
            return self::memberLeaveFromRaw($raw);
        }
        return null;
    }

    public static function many(array $response): array
    {
        $updates =
            $response['updates'] ??
            $response['data']['updates'] ??
            [];

        if (!is_array($updates)) {
            return [];
        }

        $out = [];

        foreach ($updates as $u) {
            if (!is_array($u)) {
                continue;
            }

            $n = self::one($u);

            if ($n) {
                $out[] = $n;
            }
        }

        return $out;
    }

    public static function nextOffset(array $response): ?string
    {
        $v =
            $response['next_offset_id'] ??
            $response['data']['next_offset_id'] ??
            null;

        return $v === null ? null : (string)$v;
    }
}
