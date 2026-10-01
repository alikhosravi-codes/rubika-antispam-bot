<?php
namespace RubikaGM;

final class MetadataBuilder
{
    public static function mention(string $text, string $label, string $userId): array
    {
        $from = mb_strpos($text, $label, 0, 'UTF-8');
        if ($from === false || $userId === '') {
            return [];
        }
        return [
            'meta_data_parts' => [
                [
                    'type' => 'MentionText',
                    'from_index' => $from,
                    'length' => mb_strlen($label, 'UTF-8'),
                    'mention_text_user_id' => $userId,
                ],
            ],
        ];
    }


    public static function mentions(string $text, array $mentions): array
    {
        $parts = [];
        foreach ($mentions as $item) {
            $label = (string)($item['label'] ?? '');
            $userId = (string)($item['user_id'] ?? '');
            if ($label === '' || $userId === '') continue;
            $from = mb_strpos($text, $label, 0, 'UTF-8');
            if ($from === false) continue;
            $parts[] = [
                'type' => 'MentionText',
                'from_index' => $from,
                'length' => mb_strlen($label, 'UTF-8'),
                'mention_text_user_id' => $userId,
            ];
        }
        return $parts ? ['meta_data_parts' => $parts] : [];
    }

    public static function bold(string $text, string $label): array
    {
        $from = mb_strpos($text, $label, 0, 'UTF-8');
        if ($from === false) {
            return [];
        }
        return [
            'meta_data_parts' => [
                [
                    'type' => 'Bold',
                    'from_index' => $from,
                    'length' => mb_strlen($label, 'UTF-8'),
                ],
            ],
        ];
    }

    public static function bolds(string $text, array $labels): array
    {
        $parts = [];
        $seen = [];
        foreach ($labels as $label) {
            $label = (string)$label;
            if ($label === '' || isset($seen[$label])) continue;
            $seen[$label] = true;
            $from = mb_strpos($text, $label, 0, 'UTF-8');
            if ($from === false) continue;
            $parts[] = [
                'type' => 'Bold',
                'from_index' => $from,
                'length' => mb_strlen($label, 'UTF-8'),
            ];
        }
        return $parts ? ['meta_data_parts' => $parts] : [];
    }

    public static function code(string $text, string $label, string $type = 'Mono'): array
    {
        $from = mb_strpos($text, $label, 0, 'UTF-8');
        if ($from === false || $label === '') {
            return [];
        }
        return [
            'meta_data_parts' => [
                [
                    'type' => $type,
                    'from_index' => $from,
                    'length' => mb_strlen($label, 'UTF-8'),
                ],
            ],
        ];
    }

    public static function merge(array ...$items): array
    {
        $parts = [];
        foreach ($items as $meta) {
            foreach (($meta['meta_data_parts'] ?? []) as $part) {
                if (is_array($part)) $parts[] = $part;
            }
        }
        return $parts ? ['meta_data_parts' => $parts] : [];
    }

    /**
     * روبیکا Bot API متن را مثل پیام دستی مارک‌داون‌خوانی نمی‌کند.
     * این متد مارکرهای ساده راهنما را از متن حذف می‌کند و معادلشان را به metadata تبدیل می‌کند:
     * - **متن** => Bold
     * - `دستور` => Mono/Code style برای کپی‌پذیر شدن دستور
     * خروجی شامل متن تمیز، metadata جدید و metadata قبلی با offset اصلاح‌شده است.
     */
    public static function prepareMarkdownMetadata(string $text, ?array $metadata = null, string $codeType = 'Mono', string $indexUnit = 'utf16'): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $len = count($chars);
        $out = [];
        $map = [];
        $parts = [];
        $i = 0;

        $findClosingOnSameLine = static function(array $chars, int $start, int $len, string $marker, int $markerLen): ?int {
            for ($j = $start; $j < $len; $j++) {
                if (($chars[$j] ?? '') === "\n" || ($chars[$j] ?? '') === "\r") {
                    return null;
                }
                if ($markerLen === 1) {
                    if (($chars[$j] ?? '') === $marker) return $j;
                } else {
                    if (($chars[$j] ?? '') === $marker && ($chars[$j + 1] ?? '') === $marker) return $j;
                }
            }
            return null;
        };

        while ($i < $len) {

            // [عنوان](https://...) به لینک متادیتای روبیکا تبدیل می‌شود.
            // این فرمت مخصوص پیام همگانی و پیام‌های معمولی است تا لینک با متن دلخواه نمایش داده شود.
            if (($chars[$i] ?? '') === '[') {
                $closeLabel = null;
                for ($j = $i + 1; $j < $len; $j++) {
                    if (($chars[$j] ?? '') === "\n" || ($chars[$j] ?? '') === "\r") {
                        break;
                    }
                    if (($chars[$j] ?? '') === ']') {
                        $closeLabel = $j;
                        break;
                    }
                }

                if ($closeLabel !== null && ($chars[$closeLabel + 1] ?? '') === '(') {
                    $closeUrl = null;
                    for ($j = $closeLabel + 2; $j < $len; $j++) {
                        if (($chars[$j] ?? '') === "\n" || ($chars[$j] ?? '') === "\r") {
                            break;
                        }
                        if (($chars[$j] ?? '') === ')') {
                            $closeUrl = $j;
                            break;
                        }
                    }

                    if ($closeUrl !== null && $closeLabel > $i + 1 && $closeUrl > $closeLabel + 2) {
                        $label = implode('', array_slice($chars, $i + 1, $closeLabel - $i - 1));
                        $url = trim(implode('', array_slice($chars, $closeLabel + 2, $closeUrl - $closeLabel - 2)));
                        if ($label !== '' && preg_match('/^(https?:\/\/|rubika:\/\/|www\.|rubika\.ir\/|@)/iu', $url)) {
                            if (str_starts_with($url, 'www.')) {
                                $url = 'https://' . $url;
                            } elseif (str_starts_with($url, 'rubika.ir/')) {
                                $url = 'https://' . $url;
                            } elseif (str_starts_with($url, '@')) {
                                $url = 'https://rubika.ir/' . ltrim($url, '@');
                            }

                            $from = count($out);
                            for ($k = $i + 1; $k < $closeLabel; $k++) {
                                $map[$k] = count($out);
                                $out[] = $chars[$k];
                            }
                            $length = count($out) - $from;
                            if ($length > 0) {
                                $parts[] = [
                                    'type' => 'Link',
                                    'from_index' => $from,
                                    'length' => $length,
                                    'link_url' => $url,
                                ];
                            }
                            $i = $closeUrl + 1;
                            continue;
                        }
                    }
                }
            }

            // **bold** فقط وقتی بسته‌شدن در همان خط پیدا شود؛ مارکر ناقص را خام نگه می‌داریم.
            if (($chars[$i] ?? '') === '*' && ($chars[$i + 1] ?? '') === '*') {
                $end = $findClosingOnSameLine($chars, $i + 2, $len - 1, '*', 2);
                if ($end !== null && $end > $i + 2) {
                    $from = count($out);
                    for ($k = $i + 2; $k < $end; $k++) {
                        $map[$k] = count($out);
                        $out[] = $chars[$k];
                    }
                    $length = count($out) - $from;
                    if ($length > 0) {
                        $parts[] = ['type' => 'Bold', 'from_index' => $from, 'length' => $length];
                    }
                    $i = $end + 2;
                    continue;
                }
            }

            // `code` فقط وقتی بسته‌شدن قبل از پایان همان خط باشد.
            // این جلوی خراب‌شدن metadata با یک بک‌تیک توضیحی و بدون جفت را می‌گیرد.
            if (($chars[$i] ?? '') === '`') {
                $end = $findClosingOnSameLine($chars, $i + 1, $len, '`', 1);
                if ($end !== null && $end > $i + 1) {
                    $from = count($out);
                    for ($k = $i + 1; $k < $end; $k++) {
                        $map[$k] = count($out);
                        $out[] = $chars[$k];
                    }
                    $length = count($out) - $from;
                    if ($length > 0) {
                        // بیشتر نسخه‌های روبیکا برای متن تک‌فاصله/کد از Mono استفاده می‌کنند.
                        // اگر نسخه‌ای متفاوت بود، code_meta_type در config.php قابل تغییر است.
                        $parts[] = ['type' => $codeType, 'from_index' => $from, 'length' => $length];
                    }
                    $i = $end + 1;
                    continue;
                }
            }

            $map[$i] = count($out);
            $out[] = $chars[$i];
            $i++;
        }

        $cleanText = implode('', $out);

        // offset در روبیکا برای متن فارسی/ایموجی باید UTF-16 باشد؛
        // char کپی را فعال می‌کند ولی بعد از ایموجی جابه‌جا می‌شود؛ byte ممکن است اصلاً کپی را از کار بیندازد.
        $sliceByChars = static function(string $s, int $start, ?int $length = null): string {
            $start = max(0, $start);
            if (function_exists('\mb_substr')) {
                return $length === null ? \mb_substr($s, $start, null, 'UTF-8') : \mb_substr($s, $start, $length, 'UTF-8');
            }
            $arr = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($arr)) {
                return $length === null ? substr($s, $start) : substr($s, $start, $length);
            }
            return implode('', array_slice($arr, $start, $length));
        };

        $toApiIndex = static function(string $s, int $charOffset) use ($indexUnit, $sliceByChars): int {
            $charOffset = max(0, $charOffset);
            if ($indexUnit === 'char') {
                return $charOffset;
            }
            $prefix = $sliceByChars($s, 0, $charOffset);
            if ($indexUnit === 'utf16') {
                if (function_exists('\mb_convert_encoding')) {
                    return (int)(strlen(\mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')) / 2);
                }
                if (function_exists('\iconv')) {
                    $converted = @\iconv('UTF-8', 'UTF-16LE//IGNORE', $prefix);
                    if (is_string($converted)) {
                        return (int)(strlen($converted) / 2);
                    }
                }
                return $charOffset;
            }
            // fallback: همان char index تا قابلیت کپی از کار نیفتد.
            return $charOffset;
        };
        $toApiLength = static function(string $s, int $charFrom, int $charLength) use ($toApiIndex): int {
            $charLength = max(0, $charLength);
            $from = $toApiIndex($s, $charFrom);
            $to = $toApiIndex($s, $charFrom + $charLength);
            return max(0, $to - $from);
        };

        foreach (($metadata['meta_data_parts'] ?? []) as $part) {
            if (!is_array($part)) continue;
            $from = (int)($part['from_index'] ?? -1);
            $length = (int)($part['length'] ?? 0);
            if ($from >= 0 && $length > 0) {
                $end = $from + $length - 1;
                if (isset($map[$from], $map[$end])) {
                    $part['from_index'] = $map[$from];
                    $part['length'] = $map[$end] - $map[$from] + 1;
                }
            }
            $parts[] = $part;
        }

        foreach ($parts as &$part) {
            $charFrom = (int)($part['from_index'] ?? 0);
            $charLength = (int)($part['length'] ?? 0);
            $part['from_index'] = $toApiIndex($cleanText, $charFrom);
            $part['length'] = $toApiLength($cleanText, $charFrom, $charLength);
        }
        unset($part);
        $parts = array_values(array_filter($parts, static fn($part) => is_array($part) && (int)($part['length'] ?? 0) > 0));
        $parts = array_slice($parts, 0, 30);

        return [
            'text' => $cleanText,
            'metadata' => $parts ? ['meta_data_parts' => $parts] : null,
            'changed' => $cleanText !== $text || !empty($parts),
        ];
    }

}
