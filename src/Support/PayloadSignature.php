<?php

namespace Vigilance\Support;

/**
 * Signs the serialized job Vigilance stores for retry, so restoring it can
 * tell "the payload our own capture wrote" from "a payload somebody edited in
 * the database".
 *
 * A signed payload is safe to unserialize in full — notifications, mailables,
 * model identifiers, collections, dates and enums included. Restricting the
 * unserialize by class instead cannot do both: allowing only the job class
 * leaves every nested object incomplete, and allowing whole namespaces lets
 * through the Illuminate classes most known gadget chains are built from.
 *
 * The MAC is keyed on the application key (previous keys still verify, so a
 * key rotation does not strand payloads already captured) and stored inline
 * in payload_raw, so no column is needed.
 */
class PayloadSignature
{
    protected const PREFIX = 'vgl1:';

    /**
     * Prefix a serialized payload with its signature. Without an application
     * key there is nothing to sign with, so the payload is stored as-is and
     * restoring it falls back to the restricted unserialize.
     */
    public static function sign(?string $serialized): ?string
    {
        $keys = static::keys();

        if ($serialized === null || $keys === []) {
            return $serialized;
        }

        return static::PREFIX.static::mac($serialized, $keys[0]).':'.$serialized;
    }

    /**
     * The serialized payload if the stored value carries a valid signature,
     * null if it is unsigned or does not verify.
     */
    public static function verify(string $stored): ?string
    {
        if (! static::isSigned($stored)) {
            return null;
        }

        $parts = explode(':', substr($stored, strlen(static::PREFIX)), 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$mac, $serialized] = $parts;

        foreach (static::keys() as $key) {
            if (hash_equals(static::mac($serialized, $key), $mac)) {
                return $serialized;
            }
        }

        return null;
    }

    public static function isSigned(string $stored): bool
    {
        return str_starts_with($stored, static::PREFIX);
    }

    protected static function mac(string $serialized, string $key): string
    {
        return hash_hmac('sha256', 'vigilance.payload|'.$serialized, $key);
    }

    /**
     * The current application key first, then any previous keys.
     *
     * @return list<string>
     */
    protected static function keys(): array
    {
        $keys = [config('app.key'), ...(array) config('app.previous_keys', [])];

        return array_values(array_filter($keys, fn ($key) => is_string($key) && $key !== ''));
    }
}
