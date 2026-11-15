<?php
declare(strict_types=1);
namespace PawLife\Privacy;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Franja diaria de "no molestar" de un usuario: el servidor no envía push dentro
 * de ella. Se evalúa en la zona horaria del usuario (nombre IANA). Puede cruzar
 * la medianoche; si desde y hasta coinciden no hay franja.
 */
final class QuietHours
{
    /** @param mixed $stored valor guardado en `preferencias.noMolestar` */
    public static function normalize(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $desde = self::minutes($stored['desdeMin'] ?? null) ?? 22 * 60;
        $hasta = self::minutes($stored['hastaMin'] ?? null) ?? 7 * 60;
        $zona = $stored['zonaHoraria'] ?? 'UTC';
        if (!is_string($zona) || !self::validZone($zona)) $zona = 'UTC';
        return ['habilitada' => ($stored['habilitada'] ?? false) === true,
            'desdeMin' => $desde, 'hastaMin' => $hasta, 'zonaHoraria' => $zona];
    }

    public static function validZone(string $zone): bool
    {
        return $zone !== '' && strlen($zone) <= 64 && in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    public static function minutes(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value < 1440 ? $value : null;
    }

    /** @param array<string, mixed> $profile perfil de `users/{uid}` */
    public static function isQuiet(array $profile, DateTimeImmutable $now): bool
    {
        $q = self::normalize(($profile['preferencias'] ?? [])['noMolestar'] ?? null);
        if (!$q['habilitada'] || $q['desdeMin'] === $q['hastaMin']) return false;
        $local = $now->setTimezone(new DateTimeZone($q['zonaHoraria']));
        $m = (int) $local->format('G') * 60 + (int) $local->format('i');
        return $q['desdeMin'] < $q['hastaMin']
            ? $m >= $q['desdeMin'] && $m < $q['hastaMin']
            : $m >= $q['desdeMin'] || $m < $q['hastaMin'];
    }
}
