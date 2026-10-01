<?php
namespace RubikaGM;

final class MessageFormatter
{
    public static function warningNotice(string $reasonText, int $warnings, int $max, string $userId, string $suffix = ''): array
    {
        $reason = trim(explode('،', $reasonText)[0] ?? $reasonText);
        $reason = self::friendlyReason($reason);
        $label = 'کاربر عزیز';
        $text = "**⚠️ هشدار تخلف**\n" .
            "› {$label}، ارسال {$reason} ممنوع است.\n" .
            "› اخطار: {$warnings}/{$max}" . $suffix;
        $metadata = MetadataBuilder::merge(
            MetadataBuilder::mention($text, $label, $userId),
            MetadataBuilder::bolds($text, ['⚠️ هشدار تخلف'])
        );
        return [$text, $metadata];
    }

    public static function friendlyReason(string $reason): string
    {
        $map = [
            'لینک/آیدی' => 'لینک یا آیدی',
            'آیدی/منشن' => 'آیدی',
            'شماره تماس' => 'شماره تلفن',
            'کلمات غیرمجاز' => 'کلمات غیرمجاز',
            'فیلتر کلمات سراسری' => 'کلمه غیرمجاز',
            'اسپم' => 'اسپم',
            'تکرار پیام' => 'پیام تکراری',
        ];
        return $map[$reason] ?? $reason;
    }

    public static function compactAction(string $icon, string $title, string $sub = ''): string
    {
        $text = "╭─ {$icon} {$title}";
        if ($sub !== '') {
            $text .= "\n╰─ {$sub}";
        }
        return $text;
    }
}
