<?php

declare(strict_types=1);

namespace PawLife\Notifications;

use DateTimeImmutable;
use PawLife\Http\HttpException;
use PawLife\Privacy\PrivacyService;

/**
 * Genera, desde el servidor, un recordatorio de "sin paseos" por episodio de
 * inactividad. Es opt-in: solo actúa para quien habilitó
 * `preferencias.inactividad.habilitada` y con el intervalo que eligió.
 *
 * Un episodio es "desde el final de un paseo (o, si la persona lo pidió, desde
 * el alta de la mascota) hasta el siguiente paseo". El ID del recordatorio
 * deriva de (usuario, mascota, origen del episodio): ejecutar el cron cinco
 * veces seguidas o a la vez no puede crear dos avisos del mismo episodio. Un
 * paseo nuevo abre otro episodio y completa los avisos pendientes del anterior.
 *
 * El recordatorio resultante lo envía `PushDispatcher` como cualquier otro, así
 * que respeta `recordatoriosCuidados` y las reglas de dispositivos.
 */
final class InactivityReminderService
{
    /** Tope por ejecución; el resto se atiende en la siguiente. */
    public const MAX_USERS = 200;
    public const MAX_CREATED = 50;

    public function __construct(private readonly ReminderStore $db)
    {
    }

    /** @return array{usuarios: int, mascotas: int, creados: int, completados: int, omitidos: int} */
    public function run(?DateTimeImmutable $clock = null, bool $dryRun = false): array
    {
        $now = $clock ?? new DateTimeImmutable('now');
        $stats = ['usuarios' => 0, 'mascotas' => 0, 'creados' => 0, 'completados' => 0, 'omitidos' => 0];

        $users = $this->db->queryDocuments(
            'users',
            ['preferencias.inactividad.habilitada' => true],
            null,
            self::MAX_USERS,
        );

        foreach ($users as $user) {
            $uid = $user['id'];
            $profile = $user['data'];
            $prefs = PrivacyService::preferences($profile);
            if (($profile['estadoCuenta'] ?? 'activa') !== 'activa'
                || $prefs['inactividad']['habilitada'] !== true
                || $prefs['recordatoriosCuidados'] !== true) {
                $stats['omitidos']++;
                continue;
            }
            $stats['usuarios']++;
            $intervalSeconds = $prefs['inactividad']['intervaloHoras'] * 3600;

            foreach ($this->db->queryDocuments('mascotas', ['userId' => $uid]) as $pet) {
                $stats['mascotas']++;
                if ($stats['creados'] >= self::MAX_CREATED) {
                    $stats['omitidos']++;
                    continue;
                }
                $this->handlePet(
                    $uid,
                    $pet['id'],
                    $pet['data'],
                    $prefs['inactividad']['incluirSinPaseos'],
                    $intervalSeconds,
                    $now,
                    $dryRun,
                    $stats,
                    (string) $prefs['idioma'],
                );
            }
        }
        return $stats;
    }

    /** @param array<string, int> $stats */
    private function handlePet(
        string $uid,
        string $petId,
        array $pet,
        bool $includeWithoutWalks,
        int $intervalSeconds,
        DateTimeImmutable $now,
        bool $dryRun,
        array &$stats,
        string $locale = 'es',
    ): void {
        $last = $this->lastWalk($uid, $petId);
        $origin = $this->episodeOrigin($last, $pet, $includeWithoutWalks);
        if ($origin === null) {
            $stats['omitidos']++;
            return;
        }

        // Un paseo posterior a avisos anteriores los deja sin sentido.
        if (!$dryRun) {
            $stats['completados'] += $this->completeStale($uid, $petId, $origin);
        }

        if ($now->getTimestamp() - $origin->getTimestamp() < $intervalSeconds) {
            $stats['omitidos']++;
            return;
        }

        $id = self::reminderId($uid, $petId, $origin);
        if ($this->db->getDocument('recordatorios/' . $id) !== null) {
            $stats['omitidos']++;
            return;
        }
        if ($dryRun) {
            $stats['creados']++;
            return;
        }

        // Revalidar justo antes de crear: si apareció un paseo mientras se
        // preparaba el aviso, ese episodio ya terminó.
        $again = $this->episodeOrigin($this->lastWalk($uid, $petId), $pet, $includeWithoutWalks);
        if ($again === null || $again->format('U.u') !== $origin->format('U.u')) {
            $stats['omitidos']++;
            return;
        }

        try {
            $this->db->createDocument('recordatorios', [
                'userId' => $uid,
                'mascotaId' => $petId,
                'tipo' => 'inactividad',
                'entidadId' => is_string($last['id'] ?? null) ? $last['id'] : $petId,
                'fecha' => $now,
                'mensaje' => self::message((string) ($pet['nombre'] ?? ''), $origin, $now, $locale),
                'completado' => false,
                'origenEpisodio' => $origin,
                'generadoPor' => 'servidor',
                'creadoEn' => $now,
                'actualizadoEn' => $now,
            ], $id);
            $stats['creados']++;
        } catch (HttpException $e) {
            if ($e->status() !== 409) throw $e;
            $stats['omitidos']++;
        }
    }

    /** @return array{id: string, data: array<string, mixed>}|null */
    private function lastWalk(string $uid, string $petId): ?array
    {
        $rows = $this->db->queryDocuments('paseos', ['userId' => $uid, 'mascotaId' => $petId], 'fechaInicio desc', 1);
        return $rows[0] ?? null;
    }

    /** @param array{id: string, data: array<string, mixed>}|null $last */
    private function episodeOrigin(?array $last, array $pet, bool $includeWithoutWalks): ?DateTimeImmutable
    {
        if ($last !== null) {
            $end = $last['data']['fechaFin'] ?? $last['data']['fechaInicio'] ?? null;
            return $end instanceof DateTimeImmutable ? $end : null;
        }
        $created = $pet['creadoEn'] ?? null;
        return $includeWithoutWalks && $created instanceof DateTimeImmutable ? $created : null;
    }

    private function completeStale(string $uid, string $petId, DateTimeImmutable $currentOrigin): int
    {
        $completed = 0;
        $open = $this->db->queryDocuments('recordatorios', [
            'userId' => $uid, 'mascotaId' => $petId, 'tipo' => 'inactividad', 'completado' => false,
        ]);
        foreach ($open as $reminder) {
            $episode = $reminder['data']['origenEpisodio'] ?? null;
            if ($episode instanceof DateTimeImmutable && $episode < $currentOrigin) {
                $this->db->patchDocument('recordatorios/' . $reminder['id'], [
                    'completado' => true,
                    'actualizadoEn' => new DateTimeImmutable('now'),
                ]);
                $completed++;
            }
        }
        return $completed;
    }

    public static function reminderId(string $uid, string $petId, DateTimeImmutable $origin): string
    {
        return 'inact_' . substr(hash('sha256', $uid . "\0" . $petId . "\0" . $origin->format('U.u')), 0, 40);
    }

    /** Texto del aviso en el idioma elegido por la persona (es, en o pt). */
    public static function message(string $name, DateTimeImmutable $origin, DateTimeImmutable $now, string $locale = 'es'): string
    {
        $hours = max(1, intdiv($now->getTimestamp() - $origin->getTimestamp(), 3600));
        $useHours = $hours < 48;
        $n = $useHours ? $hours : intdiv($hours, 24);
        $name = trim($name);
        $text = match ($locale) {
            'en' => ($name === '' ? 'Your pet' : $name) . " hasn't been walked for $n "
                . ($useHours ? ($n === 1 ? 'hour' : 'hours') : 'days') . '.',
            'pt' => ($name === '' ? 'Seu pet' : $name) . " está há $n "
                . ($useHours ? ($n === 1 ? 'hora' : 'horas') : 'dias') . ' sem passear.',
            default => ($name === '' ? 'Tu mascota' : $name) . " lleva $n "
                . ($useHours ? ($n === 1 ? 'hora' : 'horas') : 'días') . ' sin pasear.',
        };
        return mb_substr($text, 0, 240);
    }
}
