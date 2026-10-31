<?php

namespace App\Support;

/**
 * Reading and checking JSON bodies with friendly, field-specific errors
 * (the same messages as the reference backend).
 */
class Input
{
    public static function text(array $data, string $key, bool $required = false, int $maxLength = 200, ?string $message = null, string $default = ''): string
    {
        $value = $data[$key] ?? null;
        $value = $value === null ? '' : trim(is_scalar($value) ? (string) $value : '');
        if ($required && $value === '') {
            throw ApiError::invalid($message ?? "Enter {$key}.", $key);
        }
        if (mb_strlen($value) > $maxLength) {
            throw ApiError::invalid("Keep this under {$maxLength} characters.", $key);
        }

        return $value !== '' ? $value : $default;
    }

    public static function integer(array $data, string $key, bool $required = false, ?int $minimum = null, ?string $message = null, int $default = 0): int
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw ApiError::invalid($message ?? "Enter {$key}.", $key);
            }

            return $default;
        }
        if (! is_numeric($value)) {
            throw ApiError::invalid($message ?? 'Enter a number.', $key);
        }
        $number = (int) round((float) $value);
        if ($minimum !== null && $number < $minimum) {
            throw ApiError::invalid($message ?? "Enter a number of at least {$minimum}.", $key);
        }

        return $number;
    }
}
