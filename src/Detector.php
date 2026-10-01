<?php
namespace RubikaGM;

final class Detector
{
    public static function reasons(array $n, array $group, Database $db): array
    {
        $text = (string)($n['text'] ?? '');
        $locks = $group['locks'] ?? [];
        $reasons = [];

        if (!empty($locks['content']) && self::hasAnyContent($n)) $reasons[] = 'محتوا';
        if (!empty($locks['text']) && trim($text) !== '' && empty($n['has_caption'])) $reasons[] = 'متن';
        if (!empty($locks['link']) && self::hasLink($text)) $reasons[] = 'لینک';
        if (!empty($locks['rubika_id']) && self::hasMention($text)) $reasons[] = 'آیدی/منشن';
        if (!empty($locks['email']) && self::hasEmail($text)) $reasons[] = 'ایمیل';
        if (!empty($locks['phone']) && self::hasPhone($text)) $reasons[] = 'شماره تماس';
        if (!empty($locks['number']) && self::hasNumber($text)) $reasons[] = 'عدد';
        if (!empty($locks['hashtag']) && self::hasHashtag($text)) $reasons[] = 'هشتگ';
        if (!empty($locks['english_text']) && self::hasEnglish($text)) $reasons[] = 'متن انگلیسی';
        if (!empty($locks['emoji']) && self::hasEmoji($text)) $reasons[] = 'ایموجی';
        if (!empty($locks['crash_code']) && self::hasCrashCode($text)) $reasons[] = 'کد هنگی';
        if (!empty($locks['swear']) && self::hasSwear($text)) $reasons[] = 'فحش';
        if (!empty($locks['bio_check']) && self::hasBioCheck($text)) $reasons[] = 'بیوچک';

        if (!empty($locks['forward']) && !empty($n['forwarded'])) $reasons[] = 'فوروارد';
        if (!empty($locks['reply']) && (!empty($n['has_reply']) || !empty($n['reply_to_message_id']))) $reasons[] = 'ریپلای';
        if (!empty($locks['edit']) && !empty($n['is_edited'])) $reasons[] = 'ویرایش';
        if (!empty($locks['metadata']) && !empty($n['has_metadata'])) $reasons[] = 'متادیتا';
        if (!empty($locks['caption']) && !empty($n['has_caption'])) $reasons[] = 'کپشن';

        if (!empty($locks['file']) && (!empty($n['has_document']) || (!empty($n['has_file']) && !self::hasMedia($n)))) $reasons[] = 'فایل';
        if (!empty($locks['media']) && self::hasMedia($n)) $reasons[] = 'رسانه';
        if (!empty($locks['photo']) && !empty($n['has_photo'])) $reasons[] = 'عکس';
        if (!empty($locks['video']) && !empty($n['has_video'])) $reasons[] = 'ویدیو';
        if (!empty($locks['voice']) && !empty($n['has_voice'])) $reasons[] = 'ویس';
        if (!empty($locks['gif']) && !empty($n['has_gif'])) $reasons[] = 'گیف';
        if (!empty($locks['music']) && !empty($n['has_music'])) $reasons[] = 'آهنگ';
        if (!empty($locks['sticker']) && !empty($n['has_sticker'])) $reasons[] = 'استیکر';
        if (!empty($locks['contact']) && !empty($n['has_contact'])) $reasons[] = 'مخاطب';
        if (!empty($locks['location']) && !empty($n['has_location'])) $reasons[] = 'لوکیشن';
        if (!empty($locks['poll']) && !empty($n['has_poll'])) $reasons[] = 'نظرسنجی';
        if (!empty($locks['large_file']) && (int)($n['file_size'] ?? 0) >= 10485760) $reasons[] = 'فایل حجیم';

        if (!empty($locks['bad_words']) && self::hasBadWord($text, $group['bad_words'] ?? [])) $reasons[] = 'کلمات غیرمجاز';

        if ($text !== '' && (!empty($locks['flood']) || !empty($locks['repeat']))) {
            $hash = Support::textHash($text);
            $window = (int)($group['flood']['seconds'] ?? 8);
            $counts = $db->floodCount($n['chat_id'], $n['sender_id'], $hash, max(3, $window));
            if (!empty($locks['flood']) && $counts['total'] >= (int)($group['flood']['max_messages'] ?? 5)) $reasons[] = 'اسپم';
            if (!empty($locks['repeat']) && $counts['repeat'] >= (int)($group['repeat']['max_repeats'] ?? 3)) $reasons[] = 'تکرار پیام';
        }

        return array_values(array_unique($reasons));
    }

    private static function hasAnyContent(array $n): bool
    {
        return trim((string)($n['text'] ?? '')) !== ''
            || !empty($n['has_file']) || !empty($n['has_sticker']) || !empty($n['has_contact'])
            || !empty($n['has_location']) || !empty($n['has_poll']) || !empty($n['has_photo'])
            || !empty($n['has_video']) || !empty($n['has_voice']) || !empty($n['has_gif']) || !empty($n['has_music']);
    }

    private static function hasMedia(array $n): bool
    {
        return !empty($n['has_photo']) || !empty($n['has_video']) || !empty($n['has_voice']) || !empty($n['has_gif']) || !empty($n['has_music']);
    }

    private static function hasLink(string $text): bool
    {
        return (bool)preg_match('~(https?://|www\.|rubika\.ir/|t\.me/|telegram\.me/|bit\.ly/|join\.?|joing/|\b[a-z0-9][a-z0-9\-]{2,}\.(?:ir|com|net|org|me|io|app|site|xyz)\b)~iu', $text);
    }

    private static function hasMention(string $text): bool
    {
        return (bool)preg_match('~(?<!\w)@[a-zA-Z0-9_\.]{4,}~u', $text);
    }

    private static function hasEmail(string $text): bool
    {
        return (bool)filter_var(trim($text), FILTER_VALIDATE_EMAIL) || (bool)preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text);
    }

    private static function hasPhone(string $text): bool
    {
        $d = Support::normalizeDigits($text);
        return (bool)preg_match('/(?:\+?98|0)?9\d{9}/u', $d);
    }

    private static function hasNumber(string $text): bool
    {
        $d = Support::normalizeDigits($text);
        return (bool)preg_match('/\d/u', $d);
    }

    private static function hasHashtag(string $text): bool
    {
        return (bool)preg_match('/#[\p{L}\p{N}_]+/u', $text);
    }

    private static function hasEnglish(string $text): bool
    {
        return (bool)preg_match('/[a-zA-Z]{3,}/u', $text);
    }

    private static function hasEmoji(string $text): bool
    {
        return (bool)preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text);
    }

    private static function hasCrashCode(string $text): bool
    {
        if (mb_strlen($text, 'UTF-8') > 1500) return true;
        if (preg_match('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]{5,}/u', $text)) return true;
        if (preg_match('/(\p{Mn}){6,}/u', $text)) return true;
        return false;
    }

    private static function hasSwear(string $text): bool
    {
        $words = ['کیر','کص','کون','جنده','کسکش','حرومزاده','بی ناموس','مادرجنده','گایید','گاییدم','fuck','shit','bitch'];
        return self::hasBadWord($text, $words);
    }

    private static function hasBioCheck(string $text): bool
    {
        $clean = mb_strtolower(Support::normalizeDigits($text), 'UTF-8');
        $clean = preg_replace('/\s+/u', '', $clean) ?: $clean;
        return str_contains($clean, 'بیوچک') || str_contains($clean, 'بیوگرافیچک') || str_contains($clean, 'biocheck');
    }

    private static function hasBadWord(string $text, array $words): bool
    {
        $hay = mb_strtolower($text, 'UTF-8');
        foreach ($words as $word) {
            $word = trim((string)$word);
            if ($word !== '' && mb_stripos($hay, mb_strtolower($word, 'UTF-8'), 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }
}
