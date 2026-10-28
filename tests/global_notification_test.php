<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use PawLife\Auth\AuthenticatedUser;
use PawLife\Http\HttpException;
use PawLife\Notifications\GlobalNotification;
use PawLife\Notifications\GlobalNotificationStore;
use PawLife\Notifications\PushSender;

function ok(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rejects(callable $action, int $status, ?string $code = null): void
{
    try { $action(); } catch (HttpException $e) {
        ok($e->status() === $status, 'estado ' . $e->status() . ' en vez de ' . $status);
        if ($code !== null) ok($e->errorCode === $code, 'código ' . var_export($e->errorCode, true));
        return;
    }
    throw new RuntimeException('Debía rechazarse');
}

/** Almacén en memoria con versiones (lease/CAS), orden por ID y filtros de igualdad. */
final class JobsStore implements GlobalNotificationStore
{
    /** @var array<string, array<string, mixed>> */
    public array $documents = [];
    /** @var array<string, int> */
    private array $versions = [];
    /** Hook que se ejecuta una vez justo antes del primer PATCH de un documento que contenga la clave. */
    public ?Closure $beforeLeasePatch = null;

    private static function field(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) return null;
            $data = $data[$part];
        }
        return $data;
    }

    public function queryDocuments(string $collection, array $filters, ?string $orderBy = null, ?int $limit = null): array
    {
        $rows = [];
        foreach ($this->documents as $path => $data) {
            if (!str_starts_with($path, $collection . '/') || substr_count($path, '/') !== 1) continue;
            foreach ($filters as $field => $value) if (self::field($data, (string) $field) !== $value) continue 2;
            $rows[] = ['id' => substr($path, strlen($collection) + 1), 'data' => $data];
        }
        if ($orderBy !== null) {
            [$field, $dir] = array_pad(explode(' ', $orderBy), 2, 'asc');
            usort($rows, static fn ($a, $b) => (($a['data'][$field] ?? null) <=> ($b['data'][$field] ?? null)) * ($dir === 'desc' ? -1 : 1));
        }
        return array_slice($rows, 0, $limit ?? PHP_INT_MAX);
    }

    public function collectionPage(string $collection, ?string $afterId, int $limit): array
    {
        $rows = $this->queryDocuments($collection, []);
        usort($rows, static fn ($a, $b) => strcmp($a['id'], $b['id']));
        if ($afterId !== null) $rows = array_values(array_filter($rows, static fn ($r) => strcmp($r['id'], $afterId) > 0));
        return array_slice($rows, 0, $limit);
    }

    public function queryDateWindowPage(string $collection, string $field, DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 100, ?array $after = null): array
    {
        return [];
    }

    public function getDocument(string $documentPath): ?array { return $this->documents[$documentPath] ?? null; }

    public function getDocumentVersioned(string $documentPath): ?array
    {
        $d = $this->getDocument($documentPath);
        return $d === null ? null : ['data' => $d, 'updateTime' => (string) ($this->versions[$documentPath] ?? 1)];
    }

    public function createDocument(string $collectionPath, array $data, ?string $documentId = null): array
    {
        $path = $collectionPath . '/' . $documentId;
        if (isset($this->documents[$path])) throw new HttpException(409, 'duplicado');
        $this->documents[$path] = $data;
        $this->versions[$path] = 1;
        return ['id' => (string) $documentId, 'data' => $data];
    }

    public function patchDocument(string $documentPath, array $data, ?string $expectedUpdateTime = null): array
    {
        ok(isset($this->documents[$documentPath]), 'PATCH sobre documento inexistente: ' . $documentPath);
        if ($this->beforeLeasePatch !== null && str_starts_with($documentPath, 'notificationJobs/') && $expectedUpdateTime !== null) {
            $hook = $this->beforeLeasePatch; $this->beforeLeasePatch = null; $hook($documentPath);
        }
        if ($expectedUpdateTime !== null && $expectedUpdateTime !== (string) ($this->versions[$documentPath] ?? 1)) {
            throw new HttpException(409, 'versión distinta');
        }
        $this->documents[$documentPath] = array_merge($this->documents[$documentPath], $data);
        $this->versions[$documentPath] = ($this->versions[$documentPath] ?? 1) + 1;
        return ['id' => basename($documentPath), 'data' => $this->documents[$documentPath]];
    }
}

/** Envía según un guion por token: ausente = aceptado. */
final class ScriptedSender implements PushSender
{
    /** @var list<string> */
    public array $sent = [];
    /** @var array<string, Throwable> */
    public array $fail = [];
    public ?Closure $onSend = null;

    public function send(string $deviceToken, string $reminderId, string $petId, string $type, string $message): void
    {
        if ($this->onSend !== null) ($this->onSend)($deviceToken);
        if (isset($this->fail[$deviceToken])) throw $this->fail[$deviceToken];
        $this->sent[] = $deviceToken;
    }
}

const BODY = ['mensaje' => 'Aviso para todos', 'solicitudId' => '12345678-1234-1234-1234-123456789012', 'confirmarTodos' => true];
$t0 = new DateTimeImmutable('2026-10-20T10:00:00Z');
$admin = new AuthenticatedUser('admin', email: 'admin@example.test');

function world(int $users = 3): JobsStore
{
    $s = new JobsStore();
    $s->documents['configuracion/panel-notificaciones'] = ['administradores' => ['admin']];
    for ($i = 1; $i <= $users; $i++) {
        $uid = sprintf('u%03d', $i);
        $s->documents["users/$uid"] = ['nombre' => "U$i", 'creadoEn' => new DateTimeImmutable('2026-01-01T00:00:00Z')];
        $s->documents["dispositivos/d$uid"] = ['userId' => $uid, 'token' => "tok-$uid", 'actualizadoEn' => new DateTimeImmutable('2026-10-01T00:00:00Z')];
    }
    return $s;
}

function drain(GlobalNotification $job, DateTimeImmutable $t, int $max = 20): array
{
    $last = [];
    for ($i = 0; $i < $max; $i++) {
        $last = $job->processNext($t->modify('+' . ($i * 5) . ' minutes'));
        if (($last['estado'] ?? null) !== 'en_proceso') return $last;
    }
    throw new RuntimeException('El trabajo no terminó');
}

// ---- validación, permisos e idempotencia ----
$s = world(); $sender = new ScriptedSender(); $job = new GlobalNotification($s, $sender);
rejects(fn () => $job->create($admin, array_diff_key(BODY, ['confirmarTodos' => 1]), $t0), 400);
rejects(fn () => $job->create($admin, ['confirmarTodos' => false] + BODY, $t0), 400);
rejects(fn () => $job->create($admin, array_replace(BODY, ['mensaje' => str_repeat('a', 241)]), $t0), 400);
rejects(fn () => $job->create($admin, array_replace(BODY, ['mensaje' => '  ']), $t0), 400);
rejects(fn () => $job->create($admin, array_replace(BODY, ['solicitudId' => 'corto']), $t0), 400);
rejects(fn () => $job->create(new AuthenticatedUser('normal'), BODY, $t0), 403);
$created = $job->create($admin, BODY, $t0);
ok($created['estado'] === 'en_proceso' && str_starts_with($created['id'], 'global-') && $created['contadores']['aceptadas'] === 0, 'trabajo mal creado');
ok($job->create($admin, BODY, $t0)['id'] === $created['id'], 'la misma solicitud debe devolver el mismo trabajo');
rejects(fn () => $job->create($admin, array_replace(BODY, ['mensaje' => 'Otro mensaje']), $t0), 409);
rejects(fn () => $job->create($admin, array_replace(BODY, ['solicitudId' => '99999999-1234-1234-1234-123456789012']), $t0), 409, 'NOTIFICATION_JOB_IN_PROGRESS');
rejects(fn () => $job->get(new AuthenticatedUser('normal'), $created['id']), 403);
rejects(fn () => $job->get($admin, 'global-' . str_repeat('0', 64)), 404);
rejects(fn () => $job->get($admin, '../users/admin'), 404);
ok(!str_contains(json_encode($job->get($admin, $created['id'])), 'tok-'), 'el estado no debe exponer tokens');

// ---- 0 usuarios ----
$s = world(0); $job = new GlobalNotification($s, $sender = new ScriptedSender());
$id = $job->create($admin, BODY, $t0)['id'];
ok(drain($job, $t0)['estado'] === 'completado' && $job->get($admin, $id)['estado'] === 'completado' && $sender->sent === [], '0 usuarios: debe completar sin enviar');

// ---- muchas páginas, token duplicado, bajas y corte de audiencia ----
$s = world(60);
$s->documents['dispositivos/dup'] = ['userId' => 'u002', 'token' => 'tok-u001', 'actualizadoEn' => new DateTimeImmutable('2026-10-02T00:00:00Z')]; // mismo token que u001
$s->documents['users/u003']['preferencias'] = ['avisosEquipo' => false];
$s->documents['users/u004']['estadoCuenta'] = 'eliminando';
unset($s->documents['dispositivos/du005']);                       // sin dispositivo
$s->documents['dispositivos/du006']['demo'] = true;              // solo demo
$s->documents['dispositivos/du007']['activo'] = false;           // inactivo
$sender = new ScriptedSender(); $job = new GlobalNotification($s, $sender);
$id = $job->create($admin, BODY, $t0)['id'];
// Entra un usuario DESPUÉS del corte: no debe recibir el aviso.
$s->documents['users/u999'] = ['nombre' => 'Nuevo', 'creadoEn' => $t0->modify('+1 minute')];
$s->documents['dispositivos/d999'] = ['userId' => 'u999', 'token' => 'tok-u999', 'actualizadoEn' => $t0];
$first = $job->processNext($t0->modify('+1 minutes'));
ok($first['estado'] === 'en_proceso' && $first['usuarios'] === 25, 'una pasada procesa a lo sumo 25 personas');
$done = drain($job, $t0->modify('+10 minutes'));
$st = $job->get($admin, $id);
ok($st['estado'] === 'completado', 'debe completar');
$c = $st['contadores'];
ok($c['usuarios'] === 61, 'recorre las 61 cuentas: ' . $c['usuarios']);
// u001 y u002 comparten token (u001 envía, u002 cuenta como duplicado); u003-u007 omitidas; u999 posterior al corte.
ok(in_array('tok-u001', $sender->sent, true) && count(array_keys($sender->sent, 'tok-u001', true)) === 1, 'un token duplicado recibe un solo envío');
ok(!in_array('tok-u003', $sender->sent, true) && !in_array('tok-u004', $sender->sent, true), 'bajas: no debe enviar a quien no admite avisos o está eliminándose');
ok(!in_array('tok-u999', $sender->sent, true), 'un usuario posterior al corte no entra');
ok($c['aceptadas'] === count($sender->sent) && $c['fallidas'] === 0 && $c['inciertas'] === 0, 'contadores coherentes con envíos: ' . json_encode($c));
ok(($c['omitidosPorMotivo']['avisos_desactivados'] ?? 0) === 1 && ($c['omitidosPorMotivo']['cuenta_no_activa'] ?? 0) === 1
    && ($c['omitidosPorMotivo']['posterior_al_corte'] ?? 0) === 1 && ($c['omitidosPorMotivo']['token_duplicado'] ?? 0) === 1
    && ($c['omitidosPorMotivo']['sin_dispositivo'] ?? 0) === 3, 'motivos de omisión: ' . json_encode($c['omitidosPorMotivo']));
$sentBefore = count($sender->sent);
$job->processNext($t0->modify('+3 hours')); $job->processNext($t0->modify('+4 hours'));
ok(count($sender->sent) === $sentBefore, 'un trabajo completado no vuelve a enviar');

// ---- cron concurrente: lease activo y conflicto de versión ----
$s = world(3); $sender = new ScriptedSender(); $job = new GlobalNotification($s, $sender);
$id = $job->create($admin, BODY, $t0)['id'];
$s->documents['notificationJobs/' . $id]['leaseHasta'] = $t0->modify('+10 minutes');
$r = $job->processNext($t0->modify('+1 minute'));
ok(($r['omitido'] ?? null) === 'lease_activo' && $sender->sent === [], 'con lease activo no procesa');
$s->documents['notificationJobs/' . $id]['leaseHasta'] = null;
$s->beforeLeasePatch = static function (string $path) use ($s): void { $s->patchDocument($path, ['actualizadoEn' => new DateTimeImmutable('now')]); }; // otro proceso toma el trabajo
$r = $job->processNext($t0->modify('+2 minutes'));
ok(($r['omitido'] ?? null) === 'otro_proceso' && $sender->sent === [], 'un conflicto de versión debe ceder el trabajo');

// ---- permisos revocados ----
$s = world(3); $sender = new ScriptedSender(); $job = new GlobalNotification($s, $sender);
$id = $job->create($admin, BODY, $t0)['id'];
$s->documents['configuracion/panel-notificaciones'] = ['administradores' => []];
$r = $job->processNext($t0->modify('+1 minute'));
ok($r['estado'] === 'detenido' && $sender->sent === [] && $job->processNext($t0->modify('+9 minutes'))['trabajo'] === null, 'revocar el permiso detiene el trabajo');
$s->documents['configuracion/panel-notificaciones'] = ['administradores' => ['admin']];
ok($job->get($admin, $id)['motivoDetencion'] === 'permiso_revocado', 'debe registrar el motivo');

// ---- resultados de FCM: token inválido, rechazo, transitorio e incierto ----
$s = world(5); $sender = new ScriptedSender(); $job = new GlobalNotification($s, $sender);
$sender->fail['tok-u001'] = new HttpException(502, 'x', errorCode: 'FCM_TOKEN_INVALID');
$sender->fail['tok-u002'] = new HttpException(502, 'x', errorCode: 'FCM_REJECTED');
$sender->fail['tok-u003'] = new HttpException(502, 'x', errorCode: 'FCM_TRANSIENT');
$sender->fail['tok-u004'] = new HttpException(503, 'x', errorCode: 'FCM_UNCERTAIN');
$id = $job->create($admin, BODY, $t0)['id'];
drain($job, $t0);
$c = $job->get($admin, $id)['contadores'];
ok($c['aceptadas'] === 1 && $c['fallidas'] === 3 && $c['inciertas'] === 1, 'resultado por tipo de error: ' . json_encode($c));
ok($s->documents['dispositivos/du001']['activo'] === false, 'un token inválido verificable desactiva el dispositivo');
ok(!isset($s->documents['dispositivos/du002']['activo']) && !isset($s->documents['dispositivos/du004']['activo']), 'rechazos y resultados inciertos NO desactivan dispositivos');
$sentTotal = count($sender->sent);
$job->processNext($t0->modify('+5 hours'));
ok(count($sender->sent) === $sentTotal, 'no se reintenta nada tras terminar');

// ---- corte entre reservar y registrar: queda incierta y NO se reenvía ----
$s = world(2); $sender = new ScriptedSender(); $job = new GlobalNotification($s, $sender);
$id = $job->create($admin, BODY, $t0)['id'];
$itemId = 'item-' . hash('sha256', $id . "\0" . hash('sha256', 'tok-u001'));
$s->documents['notificationJobItems/' . $itemId] = ['jobId' => $id, 'ownerId' => 'u001', 'estado' => 'procesando', 'tokenHash' => hash('sha256', 'tok-u001')];
drain($job, $t0);
ok(!in_array('tok-u001', $sender->sent, true) && $s->documents['notificationJobItems/' . $itemId]['estado'] === 'incierta', 'un registro a medias pasa a incierta y no se reenvía');
ok($job->get($admin, $id)['contadores']['inciertas'] === 1 && $job->get($admin, $id)['contadores']['aceptadas'] === 1, 'la otra persona sí recibe el aviso');

// ---- corte antes de guardar el cursor: se recorre de nuevo sin duplicar envíos ni contadores ----
$s = world(3); $sender = new ScriptedSender(); $job = new GlobalNotification($s, $sender);
$id = $job->create($admin, BODY, $t0)['id'];
$sender->onSend = static function (string $token): void {
    static $thrown = false;
    if ($token === 'tok-u002' && !$thrown) { $thrown = true; throw new Error('corte del proceso tras reservar'); }
};
try { $job->processNext($t0->modify('+1 minute')); } catch (Error) {}
// u001 quedó completa; u002 reservó su registro y el proceso murió.
$sender->onSend = null;
$s->documents['notificationJobs/' . $id]['leaseHasta'] = null;
drain($job, $t0->modify('+10 minutes'));
$c = $job->get($admin, $id)['contadores'];
ok($sender->sent === ['tok-u001', 'tok-u003'] && $c['aceptadas'] === 2 && $c['inciertas'] === 1 && $c['usuarios'] === 3,
    'tras un corte: sin duplicados y contadores exactos ' . json_encode([$sender->sent, $c]));

// ---- límite por cuenta explícito ----
$s = world(1);
for ($i = 1; $i <= 7; $i++) {
    $s->documents["dispositivos/x$i"] = ['userId' => 'u001', 'token' => "extra-$i", 'actualizadoEn' => new DateTimeImmutable("2026-10-0{$i}T00:00:00Z")];
}
$sender = new ScriptedSender(); $job = new GlobalNotification($s, $sender);
$id = $job->create($admin, BODY, $t0)['id']; drain($job, $t0);
$c = $job->get($admin, $id)['contadores'];
ok(count($sender->sent) === GlobalNotification::MAX_DEVICES_PER_ACCOUNT && $c['dispositivosOmitidos'] === 3
    && $c['omitidosPorMotivo']['limite_por_cuenta'] === 3, 'el tope por cuenta debe contarse como omitido, no recortarse en silencio');

// ---- baja entre el recorrido de la página y el envío ----
$s = world(3); $sender = new ScriptedSender(); $job = new GlobalNotification($s, $sender);
$id = $job->create($admin, BODY, $t0)['id'];
$sender->onSend = static function (string $token) use ($s): void {
    if ($token === 'tok-u001') $s->documents['users/u002']['preferencias'] = ['avisosEquipo' => false];
};
drain($job, $t0);
ok(!in_array('tok-u002', $sender->sent, true), 'una baja durante el trabajo se respeta antes de enviar');

echo "TODO OK: envío a todos (validación, 0/muchas páginas, duplicados, bajas, corte de audiencia, lease, permisos, resultados FCM, cortes sin duplicar)\n";
