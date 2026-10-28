<?php

declare(strict_types=1);

namespace PawLife\Notifications;

use PawLife\Http\HttpException;
use PawLife\Http\Request;

final class CronSignature
{
    public const REMINDERS_PATH = '/api/internal/send-reminders';
    public const DELETE_ACCOUNTS_PATH = '/api/internal/delete-accounts';
    public const INACTIVITY_PATH = '/api/internal/inactivity-reminders';
    private const PUBLIC_KEY_FILE = __DIR__ . '/push-public.pem';

    public static function verify(Request $request, string $path = self::REMINDERS_PATH): void
    {
        $timestamp = $request->header('x-pawlife-timestamp');
        $signature = $request->header('x-pawlife-signature');
        if ($timestamp === null || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 180 || $signature === null) {
            throw HttpException::unauthorized('Firma del programador ausente o caducada.');
        }
        $publicKey = @file_get_contents(self::PUBLIC_KEY_FILE);
        if (!is_string($publicKey) || !self::valid($timestamp, $signature, $publicKey, time(), $path)) {
            throw HttpException::unauthorized('Firma del programador invalida.');
        }
    }

    public static function valid(string $timestamp, string $signature, string $publicKey, int $now, string $path = self::REMINDERS_PATH): bool
    {
        if (!ctype_digit($timestamp) || abs($now - (int) $timestamp) > 180) return false;
        $raw = base64_decode(strtr($signature, '-_', '+/'), true);
        if ($raw === false || $publicKey === '') return false;
        $payload = "POST\n" . $path . "\n" . $timestamp;
        return openssl_verify($payload, $raw, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
