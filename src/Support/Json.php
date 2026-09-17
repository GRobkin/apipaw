<?php

declare(strict_types=1);

namespace PawLife\Support;

use JsonException;

final class Json
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return array<string, mixed>|null  null si el texto no es un objeto JSON valido.
     */
    public static function decodeObject(string $raw): ?array
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $raw): string
    {
        $padded = str_pad($raw, (int) (ceil(strlen($raw) / 4) * 4), '=', STR_PAD_RIGHT);

        return (string) base64_decode(strtr($padded, '-_', '+/'), true);
    }
}
