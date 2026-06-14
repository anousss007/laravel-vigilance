<?php

namespace Vigilance\Support;

class FailureSignature
{
    /**
     * Build a stable fingerprint for a failure so repeated occurrences group
     * together. The message is normalized (ids, numbers, uuids and quoted
     * strings stripped) so "User 5 not found" and "User 9 not found" collapse.
     */
    public static function for(string $type, ?string $name, ?string $exceptionClass, ?string $message): string
    {
        return hash('sha256', implode('|', [
            $type,
            $name ?? '',
            $exceptionClass ?? '',
            static::normalize($message ?? ''),
        ]));
    }

    public static function normalize(string $message): string
    {
        $patterns = [
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '<uuid>',
            '/\b[0-9a-f]{16,}\b/i' => '<hash>',
            '/0x[0-9a-f]+/i' => '<hex>',
            '/\d+/' => '<n>',
            '/\'[^\']*\'/' => '<s>',
            '/"[^"]*"/' => '<s>',
        ];

        $message = preg_replace(array_keys($patterns), array_values($patterns), $message) ?? $message;

        return trim(mb_substr($message, 0, 500));
    }
}
