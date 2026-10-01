<?php
namespace RubikaGM;

final class Support
{
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function now(): int
    {
        return time();
    }

    public static function jsonEncode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public static function jsonDecode(?string $json, mixed $default = []): mixed
    {
        if ($json === null || trim($json) === '') {
            return $default;
        }
        $data = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : $default;
    }

    public static function log(array $config, string $message, array $context = []): void
    {
        $file = $config['paths']['log'] ?? __DIR__ . '/../logs/bot.log';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $maxBytes = max(262144, (int)($config['log_max_bytes'] ?? 5242880));
        $rotateFiles = max(1, min(10, (int)($config['log_rotate_files'] ?? 3)));
        clearstatcache(true, $file);
        if (is_file($file) && (int)@filesize($file) >= $maxBytes) {
            for ($i = $rotateFiles; $i >= 1; $i--) {
                $src = $i === 1 ? $file : $file . '.' . ($i - 1);
                $dst = $file . '.' . $i;
                if (is_file($src)) @rename($src, $dst);
            }
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if ($context) {
            $line .= ' ' . self::jsonEncode($context);
        }
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function normalizeDigits(string $text): string
    {
        return strtr($text, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
    }

    public static function faBool(bool $value): string
    {
        return $value ? 'روشن' : 'خاموش';
    }

    public static function textHash(string $text): string
    {
        $text = trim(mb_strtolower(self::normalizeDigits($text), 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        return hash('sha256', $text);
    }
}
