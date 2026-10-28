<?php

declare(strict_types=1);

namespace PawLife\Notifications;

use DateTimeImmutable;
use PawLife\Auth\AuthenticatedUser;
use PawLife\Auth\NotificationAdmin;
use PawLife\Http\HttpException;
use PawLife\Privacy\PrivacyService;

/**
 * Aviso del equipo a todas las personas elegibles, en lotes duraderos del
 * servidor (no un topic de FCM): hace falta controlar la elegibilidad, las
 * bajas, los resultados y poder reanudar tras un corte.
 *
 * Garantías:
 * - La audiencia se congela al crear el trabajo (`corte`): quien se registra
 *   después no entra en un trabajo ya iniciado.
 * - Cada dispositivo tiene UN solo registro (`notificationJobItems`) con ID
 *   determinista (trabajo + hash del token). Un dispositivo repetido en varias
 *   cuentas recibe un solo envío.
 * - Antes de cada envío se revalida la cuenta y el permiso del administrador.
 * - Un envío cuyo resultado se desconoce (tiempo de espera, corte entre FCM y
 *   la escritura) queda como `incierta` y NO se reintenta a ciegas: se prefiere
 *   un posible no envío a un duplicado. Nunca se informa "entregado": solo
 *   "aceptado por FCM".
 * - El trabajo avanza con un cursor por ID de usuario; los contadores y el
 *   cursor se guardan juntos tras cada persona.
 */
final class GlobalNotification
{
    public const MAX_USERS_PER_RUN = 25;
    public const MAX_SENDS_PER_RUN = 100;
    /** Política por cuenta, explícita: lo que exceda se cuenta como omitido, no se recorta en silencio. */
    public const MAX_DEVICES_PER_ACCOUNT = 5;
    private const LEASE_SECONDS = 120;

    public function __construct(
        private readonly GlobalNotificationStore $db,
        private readonly PushSender $fcm,
    ) {
    }

    /** @return array{mensaje: string, solicitudId: string} */
    public static function validate(array $body): array
    {
        $message = $body['mensaje'] ?? null;
        $id = $body['solicitudId'] ?? null;
        if (!is_string($message) || trim($message) === '' || preg_match('/^.{1,240}$/us', trim($message)) !== 1) {
            throw HttpException::badRequest('Escribí un mensaje de hasta 240 caracteres.');
        }
        if (!is_string($id) || preg_match('/^[a-zA-Z0-9-]{16,80}$/', $id) !== 1) {
            throw HttpException::badRequest('La solicitud necesita un identificador valido.');
        }
        if (($body['confirmarTodos'] ?? null) !== true) {
            throw HttpException::badRequest('Confirmá explícitamente que el aviso va a todas las personas.');
        }
        return ['mensaje' => trim($message), 'solicitudId' => $id];
    }

    /** @return array<string, mixed> */
    public function create(AuthenticatedUser $admin, array $body, ?DateTimeImmutable $clock = null): array
    {
        $data = self::validate($body);
        NotificationAdmin::assertAllowed($admin, $this->db);
        $now = $clock ?? new DateTimeImmutable('now');
        $id = 'global-' . hash('sha256', $admin->uid . ':' . $data['solicitudId']);
        $path = 'notificationJobs/' . $id;

        $existing = $this->db->getDocument($path);
        if ($existing !== null) {
            if (($existing['mensaje'] ?? null) !== $data['mensaje']) {
                throw new HttpException(409, 'La solicitud ya se usó para otro mensaje.');
            }
            return self::status($id, $existing);
        }
        if ($this->db->queryDocuments('notificationJobs', ['estado' => 'en_proceso'], null, 1) !== []) {
            throw new HttpException(409, 'Ya hay un envío a todos en proceso.', errorCode: 'NOTIFICATION_JOB_IN_PROGRESS');
        }
        (new ReminderRateLimiter($this->db))->consume('admin-notification:' . $admin->uid, $now);
        $job = [
            'administradorId' => $admin->uid,
            'mensaje' => $data['mensaje'],
            'estado' => 'en_proceso',
            'corte' => $now,
            'cursorUsuario' => null,
            'contadores' => self::emptyCounters(),
            'leaseHasta' => null,
            'creadoEn' => $now,
            'actualizadoEn' => $now,
        ];
        try {
            $this->db->createDocument('notificationJobs', $job, $id);
        } catch (HttpException $e) {
            if ($e->status() !== 409) throw $e;
            $job = $this->db->getDocument($path) ?? throw $e;
            if (($job['mensaje'] ?? null) !== $data['mensaje']) {
                throw new HttpException(409, 'La solicitud ya se usó para otro mensaje.');
            }
        }
        return self::status($id, $job);
    }

    /** @return array<string, mixed> */
    public function get(AuthenticatedUser $admin, string $id): array
    {
        NotificationAdmin::assertAllowed($admin, $this->db);
        if (preg_match('/^global-[a-f0-9]{64}$/D', $id) !== 1) throw HttpException::notFound('No existe el envío.');
        $job = $this->db->getDocument('notificationJobs/' . $id);
        if ($job === null) throw HttpException::notFound('No existe el envío.');
        return self::status($id, $job);
    }

    /**
     * Procesa el siguiente tramo del trabajo en curso. Pensado para el cron:
     * dos ejecuciones simultáneas no pisan el mismo trabajo (lease con versión).
     *
     * @return array<string, mixed>
     */
    public function processNext(?DateTimeImmutable $clock = null): array
    {
        $now = $clock ?? new DateTimeImmutable('now');
        $run = ['trabajo' => null, 'estado' => null, 'usuarios' => 0, 'envios' => 0];
        $jobs = $this->db->queryDocuments('notificationJobs', ['estado' => 'en_proceso'], null, 1);
        if ($jobs === []) return $run;

        $jobId = $jobs[0]['id'];
        $path = 'notificationJobs/' . $jobId;
        $versioned = $this->db->getDocumentVersioned($path);
        if ($versioned === null) return $run;
        $job = $versioned['data'];
        if (($job['estado'] ?? null) !== 'en_proceso') return $run;
        $lease = $job['leaseHasta'] ?? null;
        if ($lease instanceof DateTimeImmutable && $lease > $now) return $run + ['omitido' => 'lease_activo'];

        try {
            $this->db->patchDocument($path, [
                'leaseHasta' => $now->modify('+' . self::LEASE_SECONDS . ' seconds'),
                'actualizadoEn' => $now,
            ], $versioned['updateTime']);
        } catch (HttpException $e) {
            if ($e->status() === 409) return $run + ['omitido' => 'otro_proceso'];
            throw $e;
        }
        $run['trabajo'] = $jobId;

        // El permiso se valida al crear Y al procesar: si se revoca, se detiene.
        try {
            NotificationAdmin::assertAllowed(new AuthenticatedUser((string) $job['administradorId']), $this->db);
        } catch (HttpException) {
            $this->db->patchDocument($path, [
                'estado' => 'detenido', 'motivoDetencion' => 'permiso_revocado',
                'leaseHasta' => null, 'actualizadoEn' => $now,
            ]);
            return ['estado' => 'detenido'] + $run;
        }

        $counters = self::counters($job['contadores'] ?? []);
        $cutoff = $job['corte'] instanceof DateTimeImmutable ? $job['corte'] : $now;
        $page = $this->db->collectionPage('users', is_string($job['cursorUsuario'] ?? null) ? $job['cursorUsuario'] : null, self::MAX_USERS_PER_RUN);
        $sends = 0;
        $finishedPage = true;
        $cursor = $job['cursorUsuario'] ?? null;

        foreach ($page as $user) {
            if ($sends >= self::MAX_SENDS_PER_RUN) { $finishedPage = false; break; }
            $delta = $this->processUser($jobId, (string) $job['mensaje'], $cutoff, $user['id'], $user['data'], $sends);
            foreach ($delta as $key => $value) {
                if ($key === 'motivos') {
                    foreach ($value as $reason => $n) $counters['omitidosPorMotivo'][$reason] = ($counters['omitidosPorMotivo'][$reason] ?? 0) + $n;
                } else {
                    $counters[$key] += $value;
                }
            }
            $counters['usuarios']++;
            $cursor = $user['id'];
            $run['usuarios']++;
            // Cursor y contadores avanzan juntos: tras un corte, la persona
            // vuelve a recorrerse y sus registros ya decididos solo se recuentan.
            $this->db->patchDocument($path, [
                'cursorUsuario' => $cursor, 'contadores' => $counters,
                'leaseHasta' => (new DateTimeImmutable('now'))->modify('+' . self::LEASE_SECONDS . ' seconds'),
                'actualizadoEn' => new DateTimeImmutable('now'),
            ]);
        }
        $run['envios'] = $sends;

        if ($finishedPage && count($page) < self::MAX_USERS_PER_RUN) {
            $this->db->patchDocument($path, [
                'estado' => 'completado', 'completadoEn' => new DateTimeImmutable('now'),
                'leaseHasta' => null, 'actualizadoEn' => new DateTimeImmutable('now'),
            ]);
            $run['estado'] = 'completado';
        } else {
            $this->db->patchDocument($path, ['leaseHasta' => null, 'actualizadoEn' => new DateTimeImmutable('now')]);
            $run['estado'] = 'en_proceso';
        }
        return $run;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed> incrementos de contadores (más `motivos`)
     */
    private function processUser(string $jobId, string $message, DateTimeImmutable $cutoff, string $uid, array $profile, int &$sends): array
    {
        $delta = ['usuariosOmitidos' => 0, 'aceptadas' => 0, 'fallidas' => 0, 'inciertas' => 0, 'dispositivosOmitidos' => 0, 'motivos' => []];
        $skipUser = static function (string $reason) use (&$delta): array {
            $delta['usuariosOmitidos']++;
            $delta['motivos'][$reason] = ($delta['motivos'][$reason] ?? 0) + 1;
            return $delta;
        };

        $created = $profile['creadoEn'] ?? null;
        if ($created instanceof DateTimeImmutable && $created > $cutoff) return $skipUser('posterior_al_corte');
        if (!self::eligible($profile)) {
            return $skipUser(($profile['estadoCuenta'] ?? 'activa') !== 'activa' ? 'cuenta_no_activa' : 'avisos_desactivados');
        }

        $tokens = [];
        foreach ($this->db->queryDocuments('dispositivos', ['userId' => $uid], 'actualizadoEn desc', 30) as $device) {
            $info = $device['data'];
            $token = $info['token'] ?? null;
            if (!is_string($token) || $token === '' || ($info['demo'] ?? false) === true || ($info['activo'] ?? true) === false) continue;
            $tokens[$token] ??= $device['id'];
        }
        if ($tokens === []) return $skipUser('sin_dispositivo');

        $index = 0;
        foreach ($tokens as $token => $deviceId) {
            if (++$index > self::MAX_DEVICES_PER_ACCOUNT) {
                $delta['dispositivosOmitidos']++;
                $delta['motivos']['limite_por_cuenta'] = ($delta['motivos']['limite_por_cuenta'] ?? 0) + 1;
                continue;
            }
            $itemId = 'item-' . hash('sha256', $jobId . "\0" . hash('sha256', (string) $token));
            $itemPath = 'notificationJobItems/' . $itemId;
            $existing = $this->db->getDocument($itemPath);
            if ($existing !== null) {
                if (($existing['ownerId'] ?? null) !== $uid) {
                    $delta['dispositivosOmitidos']++;
                    $delta['motivos']['token_duplicado'] = ($delta['motivos']['token_duplicado'] ?? 0) + 1;
                    continue;
                }
                $state = (string) ($existing['estado'] ?? '');
                if ($state === 'procesando') {
                    // Corte entre reservar y registrar el resultado: se desconoce si FCM lo aceptó.
                    $this->db->patchDocument($itemPath, ['estado' => 'incierta', 'codigo' => 'CORTE_DURANTE_ENVIO', 'actualizadoEn' => new DateTimeImmutable('now')]);
                    $state = 'incierta';
                }
                self::tally($delta, $state);
                continue;
            }
            try {
                $this->db->createDocument('notificationJobItems', [
                    'jobId' => $jobId, 'ownerId' => $uid, 'tokenHash' => hash('sha256', (string) $token),
                    'estado' => 'procesando', 'creadoEn' => new DateTimeImmutable('now'),
                ], $itemId);
            } catch (HttpException $e) {
                if ($e->status() !== 409) throw $e;
                $delta['dispositivosOmitidos']++;
                $delta['motivos']['token_duplicado'] = ($delta['motivos']['token_duplicado'] ?? 0) + 1;
                continue;
            }

            // Última comprobación antes de enviar: bajas entre páginas o durante el trabajo.
            $fresh = $this->db->getDocument('users/' . $uid);
            if ($fresh === null || !self::eligible($fresh)) {
                $this->db->patchDocument($itemPath, ['estado' => 'omitida', 'codigo' => 'BAJA_ANTES_DEL_ENVIO', 'actualizadoEn' => new DateTimeImmutable('now')]);
                self::tally($delta, 'omitida');
                $delta['motivos']['baja_antes_del_envio'] = ($delta['motivos']['baja_antes_del_envio'] ?? 0) + 1;
                continue;
            }

            $sends++;
            try {
                $this->fcm->send((string) $token, $jobId, '', 'global', $message);
                $this->db->patchDocument($itemPath, ['estado' => 'aceptada', 'actualizadoEn' => new DateTimeImmutable('now')]);
                self::tally($delta, 'aceptada');
            } catch (\Throwable $e) {
                $code = $e instanceof HttpException ? $e->errorCode : null;
                $state = match ($code) {
                    'FCM_TOKEN_INVALID', 'FCM_REJECTED', 'FCM_TRANSIENT' => 'fallida',
                    default => 'incierta', // tiempo de espera o error desconocido: no reintentar a ciegas
                };
                if ($code === 'FCM_TOKEN_INVALID') {
                    // Solo un código verificable de token inválido desactiva el dispositivo.
                    try {
                        $this->db->patchDocument('dispositivos/' . $deviceId, ['activo' => false, 'actualizadoEn' => new DateTimeImmutable('now')]);
                    } catch (\Throwable) {
                    }
                }
                $this->db->patchDocument($itemPath, ['estado' => $state, 'codigo' => $code ?? 'DESCONOCIDO', 'actualizadoEn' => new DateTimeImmutable('now')]);
                self::tally($delta, $state);
            }
        }
        return $delta;
    }

    /** @param array<string, mixed> $profile */
    private static function eligible(array $profile): bool
    {
        return ($profile['estadoCuenta'] ?? 'activa') === 'activa'
            && PrivacyService::preferences($profile)['avisosEquipo'] === true;
    }

    /** @param array<string, mixed> $delta */
    private static function tally(array &$delta, string $state): void
    {
        match ($state) {
            'aceptada' => $delta['aceptadas']++,
            'fallida' => $delta['fallidas']++,
            'incierta' => $delta['inciertas']++,
            default => $delta['dispositivosOmitidos']++,
        };
    }

    /** @return array<string, mixed> */
    private static function emptyCounters(): array
    {
        return ['usuarios' => 0, 'usuariosOmitidos' => 0, 'aceptadas' => 0, 'fallidas' => 0,
            'inciertas' => 0, 'dispositivosOmitidos' => 0, 'omitidosPorMotivo' => []];
    }

    /** @return array<string, mixed> */
    private static function counters(mixed $stored): array
    {
        $out = self::emptyCounters();
        if (!is_array($stored)) return $out;
        foreach (array_keys($out) as $key) {
            if ($key === 'omitidosPorMotivo') {
                $out[$key] = is_array($stored[$key] ?? null) ? array_map('intval', $stored[$key]) : [];
            } else {
                $out[$key] = (int) ($stored[$key] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Resumen para el panel: contadores y estado, nunca tokens ni perfiles.
     * "aceptadas" significa aceptadas por FCM; no hay evidencia de entrega.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public static function status(string $id, array $job): array
    {
        $format = static fn (mixed $d): ?string => $d instanceof DateTimeImmutable ? $d->format(DATE_ATOM) : null;
        return [
            'id' => $id,
            'estado' => (string) ($job['estado'] ?? 'en_proceso'),
            'mensaje' => (string) ($job['mensaje'] ?? ''),
            'contadores' => self::counters($job['contadores'] ?? []),
            'motivoDetencion' => $job['motivoDetencion'] ?? null,
            'corte' => $format($job['corte'] ?? null),
            'creadoEn' => $format($job['creadoEn'] ?? null),
            'completadoEn' => $format($job['completadoEn'] ?? null),
        ];
    }
}
