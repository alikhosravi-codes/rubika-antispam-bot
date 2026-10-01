<?php
namespace RubikaGM;

// حداقل polyfill برای هاست‌هایی که افزونه mbstring ندارند.
// پروژه بهتر است با mbstring اجرا شود، اما این بخش جلوی Fatal Error را می‌گیرد.
if (!function_exists(__NAMESPACE__ . '\mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        return function_exists('\mb_strlen') ? \mb_strlen($string, $encoding ?: null) : strlen($string);
    }
}
if (!function_exists(__NAMESPACE__ . '\mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        if (function_exists('\mb_substr')) {
            return $length === null ? \mb_substr($string, $start, null, $encoding ?: null) : \mb_substr($string, $start, $length, $encoding ?: null);
        }
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}
if (!function_exists(__NAMESPACE__ . '\mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        return function_exists('\mb_strtolower') ? \mb_strtolower($string, $encoding ?: null) : strtolower($string);
    }
}
if (!function_exists(__NAMESPACE__ . '\mb_strpos')) {
    function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return function_exists('\mb_strpos') ? \mb_strpos($haystack, $needle, $offset, $encoding ?: null) : strpos($haystack, $needle, $offset);
    }
}
if (!function_exists(__NAMESPACE__ . '\mb_stripos')) {
    function mb_stripos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return function_exists('\mb_stripos') ? \mb_stripos($haystack, $needle, $offset, $encoding ?: null) : stripos($haystack, $needle, $offset);
    }
}
if (!function_exists(__NAMESPACE__ . '\mb_str_split')) {
    function mb_str_split(string $string, int $length = 1, ?string $encoding = null): array
    {
        if (function_exists('\mb_str_split')) {
            return \mb_str_split($string, $length, $encoding ?: null);
        }
        if ($length !== 1) {
            return str_split($string, $length);
        }
        $parts = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? $parts : str_split($string);
    }
}

\spl_autoload_register(function (string $class): void {
    $prefix = 'RubikaGM\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
