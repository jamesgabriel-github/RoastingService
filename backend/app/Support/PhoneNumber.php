<?php

namespace App\Support;

final class PhoneNumber
{
    /**
     * Normalize a PH mobile number to `09XXXXXXXXX`.
     *
     * Accepts `09XXXXXXXXX` (11 digits) or `+639XXXXXXXXX` as-is; anything
     * else returns null so callers can reject it as an invalid format.
     */
    public static function normalize(string $raw): ?string
    {
        $trimmed = trim($raw);

        if (preg_match('/^09\d{9}$/', $trimmed)) {
            return $trimmed;
        }

        if (preg_match('/^\+639\d{9}$/', $trimmed)) {
            return '0'.substr($trimmed, 3);
        }

        return null;
    }
}
