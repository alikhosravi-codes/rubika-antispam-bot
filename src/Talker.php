<?php
namespace RubikaGM;

final class Talker
{
    private static ?array $data = null;

    public static function reply(array $n, array $group = []): ?string
    {
        // سخنگو به صورت پیش‌فرض روشن است؛ فقط اگر صریحاً خاموش شده باشد جواب نمی‌دهد.
        $locks = (isset($group['locks']) && is_array($group['locks'])) ? $group['locks'] : [];
        if (array_key_exists('talker_enabled', $locks) && !$locks['talker_enabled']) {
            return null;
        }

        $text = trim((string)($n['text'] ?? ''));
        if ($text === '') {
            return null;
        }

        $clean = self::clean($text);
        $clean = self::stripAddressing($clean);
        if ($clean === '') {
            return null;
        }

        // پاسخ‌های قابل توسعه از data/talker.json یا yad.json خوانده می‌شود.
        $jsonReply = self::jsonReply($clean);
        if ($jsonReply !== null && $jsonReply !== '') {
            return $jsonReply;
        }

        if (in_array($clean, ['شانس', 'شانس من'], true)) {
            $chance = random_int(0, 100);
            $filled = intdiv($chance, 10);
            $bar = str_repeat('▓', $filled) . str_repeat('░', 10 - $filled);
            $desc = $chance < 30 ? '💔 امروز خیلی شانسی نیستی' : ($chance < 70 ? '👍 بد نیست' : ($chance < 90 ? '🎉 عالیه' : '🔥 شگفت‌انگیز'));
            return "🎯 شانس شما: {$chance}%\n┌──────────────┐\n│ {$bar}\n│ {$desc}\n└──────────────┘";
        }

        if ($clean === 'تاس') {
            $num = random_int(1, 6);
            $dice = [1 => '⚀', 2 => '⚁', 3 => '⚂', 4 => '⚃', 5 => '⚄', 6 => '⚅'][$num];
            return "🎲 پرتاب تاس: {$dice} عدد {$num}";
        }

        if (in_array($clean, ['پرتاب', 'سکه', 'پرتاب سکه'], true)) {
            return '🪙 پرتاب سکه: ' . (random_int(0, 1) ? 'شیر' : 'خط');
        }

        if (in_array($clean, ['سازنده', 'مالک ربات', 'خالق'], true)) {
            return "👨‍💻 سازنده: تیم مدیریت ربات\n📢 پشتیبانی را از پنل اصلی تنظیم کنید.";
        }

        $map = [
            'سلام' => 'سلام 🌹 خوش اومدی',
            'درود' => 'درود 🌹',
            'خوبی' => 'خوبم، ممنون که پرسیدی 🌿',
            'بات' => 'جانم؟ 🤖',
            'ربات' => 'جانم؟ 🤖',
            'خدافظ' => 'خدافظ 🌹',
            'خداحافظ' => 'خداحافظ 🌹',
            'مرسی' => 'خواهش می‌کنم 🌹',
            'ممنون' => 'خواهش می‌کنم 🌹',
        ];

        return $map[$clean] ?? null;
    }

    public static function listText(int $limit = 80): string
    {
        $data = self::data();
        $lines = ["🧠 پاسخ‌های سخنگو", ""];
        $count = 0;
        foreach (['exact' => 'تطبیق دقیق', 'contains' => 'شامل عبارت', 'random' => 'تصادفی'] as $section => $title) {
            $items = $data[$section] ?? [];
            if (!is_array($items) || !$items) {
                continue;
            }
            $lines[] = "◄ {$title}";
            foreach ($items as $trigger => $responses) {
                $resList = is_array($responses) ? array_values(array_filter(array_map('strval', $responses))) : [(string)$responses];
                $sample = mb_substr((string)($resList[0] ?? ''), 0, 60, 'UTF-8');
                $n = count($resList);
                $lines[] = "• {$trigger} → {$n} پاسخ" . ($sample !== '' ? " | {$sample}" : '');
                $count++;
                if ($count >= $limit) {
                    $lines[] = "…";
                    break 2;
                }
            }
            $lines[] = "";
        }
        if ($count === 0) {
            $lines[] = "هنوز پاسخی ثبت نشده است.";
        }
        $lines[] = "";
        $lines[] = "فرمت افزودن از پنل:";
        $lines[] = "کلمه | پاسخ";
        return trim(implode("\n", $lines));
    }

    public static function addReply(string $section, string $trigger, string $response): bool
    {
        $section = in_array($section, ['exact', 'contains', 'random'], true) ? $section : 'exact';
        $trigger = self::clean($trigger);
        $response = trim($response);
        if ($trigger === '' || $response === '') {
            return false;
        }
        $data = self::data();
        if (!isset($data[$section]) || !is_array($data[$section])) {
            $data[$section] = [];
        }
        $old = $data[$section][$trigger] ?? [];
        if (is_string($old)) {
            $old = [$old];
        }
        if (!is_array($old)) {
            $old = [];
        }
        $old[] = $response;
        $data[$section][$trigger] = array_values(array_unique(array_filter(array_map('strval', $old), fn($v) => trim($v) !== '')));
        return self::saveData($data);
    }

    public static function deleteReply(string $section, string $trigger): bool
    {
        $section = in_array($section, ['exact', 'contains', 'random'], true) ? $section : 'exact';
        $trigger = self::clean($trigger);
        if ($trigger === '') {
            return false;
        }
        $data = self::data();
        if (isset($data[$section]) && is_array($data[$section]) && array_key_exists($trigger, $data[$section])) {
            unset($data[$section][$trigger]);
            return self::saveData($data);
        }
        return false;
    }

    public static function parsePair(string $text): array
    {
        $text = trim($text);
        foreach (["|", "=>", "=", ":"] as $sep) {
            if (str_contains($text, $sep)) {
                [$a, $b] = array_pad(explode($sep, $text, 2), 2, '');
                return [trim($a), trim($b)];
            }
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])));
        if (count($lines) >= 2) {
            return [$lines[0], implode("\n", array_slice($lines, 1))];
        }
        return ['', ''];
    }

    public static function responseCount(): int
    {
        $count = 0;
        foreach (['exact', 'contains', 'random'] as $section) {
            $items = self::data()[$section] ?? [];
            if (is_array($items)) {
                $count += count($items);
            }
        }
        return $count;
    }

    private static function path(): string
    {
        $dir = dirname(__DIR__) . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/talker.json';
    }

    private static function saveData(array $data): bool
    {
        if (!isset($data['exact']) || !is_array($data['exact'])) $data['exact'] = [];
        if (!isset($data['contains']) || !is_array($data['contains'])) $data['contains'] = [];
        if (!isset($data['random']) || !is_array($data['random'])) $data['random'] = [];
        $ok = @file_put_contents(self::path(), Support::jsonEncode($data), LOCK_EX) !== false;
        if ($ok) {
            self::$data = $data;
        }
        return $ok;
    }

    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $paths = [
            self::path(),
            dirname(__DIR__) . '/yad.json',
        ];

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($json)) {
                if (!isset($json['exact']) && !isset($json['contains']) && !isset($json['random'])) {
                    $json = ['exact' => $json, 'contains' => [], 'random' => []];
                }
                return self::$data = $json;
            }
        }

        return self::$data = ['exact' => [], 'contains' => [], 'random' => []];
    }

    private static function jsonReply(string $clean): ?string
    {
        $data = self::data();
        if (!$data) {
            return null;
        }

        // ساختار پیشنهادی:
        // {"exact":{"سلام":["..."]}, "contains":{"ربات":["..."]}, "random":{"جوک":["..."]}}
        foreach (['exact', 'random'] as $section) {
            $items = $data[$section] ?? [];
            if (is_array($items) && array_key_exists($clean, $items)) {
                return self::pick($items[$clean]);
            }
        }

        // اگر پاسخ به صورت «تطبیق دقیق» ذخیره شده باشد، در پیام‌های معمولی گروه هم با وجود همان عبارت پاسخ بدهد.
        // این باعث می‌شود سخنگو فقط وابسته به ریپلای نباشد و در متن‌های عادی مثل «ربات سلام» هم کار کند.
        $exactItems = $data['exact'] ?? [];
        if (is_array($exactItems)) {
            foreach ($exactItems as $trigger => $responses) {
                $trigger = self::clean((string)$trigger);
                if ($trigger !== '' && mb_strlen($trigger, 'UTF-8') >= 2 && str_contains($clean, $trigger)) {
                    return self::pick($responses);
                }
            }
        }

        $contains = $data['contains'] ?? [];
        if (is_array($contains)) {
            foreach ($contains as $needle => $responses) {
                $needle = self::clean((string)$needle);
                if ($needle !== '' && str_contains($clean, $needle)) {
                    return self::pick($responses);
                }
            }
        }

        // سازگاری با yad.json قدیمی: {"جوک":[...], "فال":[...]}
        foreach ($data as $key => $responses) {
            if (in_array($key, ['exact', 'contains', 'random'], true)) {
                continue;
            }
            $k = self::clean((string)$key);
            if ($k !== '' && ($clean === $k || str_contains($clean, $k))) {
                return self::pick($responses);
            }
        }

        return null;
    }

    private static function pick(mixed $responses): ?string
    {
        if (is_string($responses)) {
            return trim($responses) !== '' ? $responses : null;
        }
        if (is_array($responses)) {
            $list = array_values(array_filter(array_map('strval', $responses), fn($v) => trim($v) !== ''));
            if ($list) {
                return $list[array_rand($list)];
            }
        }
        return null;
    }

    private static function stripAddressing(string $text): string
    {
        // حذف پیشوند/پسوندهای رایج خطاب به ربات تا پاسخ‌ها در پیام عادی گروه هم فعال شوند.
        $text = preg_replace('/(^|\s)@[a-zA-Z0-9_]{3,}(?=\s|$)/u', ' ', $text) ?: $text;
        $text = preg_replace('/^(?:ربات|بات|یخی|ربات یخی|آی ربات|ای ربات)\s+/u', '', $text) ?: $text;
        $text = preg_replace('/\s+(?:ربات|بات|یخی|ربات یخی)$/u', '', $text) ?: $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        return trim($text);
    }

    private static function clean(string $text): string
    {
        $text = Support::normalizeDigits($text);
        $text = str_replace(["\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}", "\xC2\xA0"], ' ', $text);
        $text = strtr($text, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']);
        $text = preg_replace('/[!؟?.,،؛:ـ\-]+/u', '', $text) ?: $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        return mb_strtolower(trim($text), 'UTF-8');
    }
}
