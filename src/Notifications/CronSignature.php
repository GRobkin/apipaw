<?php

declare(strict_types=1);

namespace PawLife\Notifications;

use PawLife\Http\HttpException;
use PawLife\Http\Request;

final class CronSignature
{
    private const PUBLIC_KEY_FILE = __DIR__ . '/push-public.pem';

    public static function verify(Request $request): void
    {
        $timestamp = $request->header('x-pawlife-timestamp');
        $signature = $request->header('x-pawlife-signature');
        if ($timestamp === null || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 180 || $signature === null) {
            throw HttpException::unauthorized('Firma del programador ausente o caducada.');
        }
        $publicKey = @file_get_contents(self::PUBLIC_KEY_FILE);
        if (!is_string($publicKey) || !self::valid($timestamp, $signature, $publicKey, time())) {
            throw HttpException::unauthorized('Firma del programador invalida.');
        }
    }

    public static function valid(string $timestamp, string $signature, string $publicKey, int $now): bool
    {
        if (!ctype_digit($timestamp) || abs($now - (int) $timestamp) > 180) return false;
        $raw = base64_decode(strtr($signature, '-_', '+/'), true);
        if ($raw === false || $publicKey === '') return false;
        $payload = "POST\n/api/internal/send-reminders\n" . $timestamp;
        return openssl_verify($payload, $raw, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
