<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support/MemoryFirestoreTransport.php';

use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Notifications\InactivityReminderService;
use PawLife\Notifications\ReminderStore;
use PawLife\Privacy\PrivacyService;

function ok(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function bad(callable $action, int $status): void
{
    try { $action(); } catch (HttpException $e) { ok($e->status() === $status, 'estado ' . $e->status()); return; }
    throw new RuntimeException('Debía rechazarse');
}

/** Almacén en memoria con filtros de igualdad (incluye rutas con puntos), orden y límite. */
final class InactivityStore implements ReminderStore
{
    /** @var array<string, array<string, mixed>> */
    public array $documents = [];
    /** Se ejecuta una vez justo después de la primera consulta de paseos. */
    public ?Closure $afterFirstWalkQuery = null;
    public int $creates = 0;

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
            foreach ($filters as $field => $value) {
                if (self::field($data, (string) $field) !== $value) continue 2;
            }
            $rows[] = ['id' => substr($path, strlen($collection) + 1), 'data' => $data];
        }
        if ($orderBy !== null) {
            [$field, $dir] = array_pad(explode(' ', $orderBy), 2, 'asc');
            usort($rows, static fn ($a, $b) => ($a['data'][$field] <=> $b['data'][$field]) * ($dir === 'desc' ? -1 : 1));
        }
        $rows = array_slice($rows, 0, $limit ?? PHP_INT_MAX);
        if ($collection === 'paseos' && $this->afterFirstWalkQuery !== null) {
            $hook = $this->afterFirstWalkQuery;
            $this->afterFirstWalkQuery = null;
            $hook();
        }
        return $rows;
    }

    public function queryDateWindowPage(string $collection, string $field, DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 100, ?array $after = null): array
    {
        return [];
    }

    public function getDocument(string $documentPath): ?array { return $this->documents[$documentPath] ?? null; }

    public function getDocumentVersioned(string $documentPath): ?array
    {
        $data = $this->getDocument($documentPath);
        return $data === null ? null : ['data' => $data, 'updateTime' => 'v'];
    }

    public function createDocument(string $collectionPath, array $data, ?string $documentId = null): array
    {
        $path = $collectionPath . '/' . $documentId;
        if (isset($this->documents[$path])) throw new HttpException(409, 'duplicado');
        $this->documents[$path] = $data;
        $this->creates++;
        return ['id' => (string) $documentId, 'data' => $data];
    }

    public function patchDocument(string $documentPath, array $data, ?string $expectedUpdateTime = null): array
    {
        ok(isset($this->documents[$documentPath]), 'PATCH sobre documento inexistente');
        $this->documents[$documentPath] = array_merge($this->documents[$documentPath], $data);
        return ['id' => basename($documentPath), 'data' => $this->documents[$documentPath]];
    }

    public function reminders(): array
    {
        return array_filter(
            $this->documents,
            static fn (array $data): bool => ($data['tipo'] ?? null) === 'inactividad',
        );
    }
}

$t0 = new DateTimeImmutable('2026-10-20T10:00:00Z');
$on = ['recordatoriosCuidados' => true, 'inactividad' => ['habilitada' => true, 'intervaloHoras' => 48]];

function seed(InactivityStore $s, array $prefs, ?DateTimeImmutable $lastWalkEnd, array $userExtra = []): void
{
    $s->documents['users/ana'] = ['nombre' => 'Ana', 'preferencias' => $prefs] + $userExtra;
    $s->documents['mascotas/luna'] = ['userId' => 'ana', 'nombre' => 'Luna', 'creadoEn' => new DateTimeImmutable('2026-10-01T00:00:00Z')];
    if ($lastWalkEnd !== null) {
        $s->documents['paseos/p1'] = ['userId' => 'ana', 'mascotaId' => 'luna',
            'fechaInicio' => $lastWalkEnd->modify('-30 minutes'), 'fechaFin' => $lastWalkEnd];
    }
}

// Antes del umbral: nada.
$s = new InactivityStore(); seed($s, $on, $t0);
$svc = new InactivityReminderService($s);
$r = $svc->run($t0->modify('+47 hours'));
ok($r['creados'] === 0 && $s->reminders() === [], 'antes del umbral no debe avisar');

// En el umbral: uno; repetir el cron no duplica.
$r = $svc->run($t0->modify('+48 hours'));
ok($r['creados'] === 1 && count($s->reminders()) === 1, 'en el umbral debe crear un aviso');
$id = InactivityReminderService::reminderId('ana', 'luna', $t0);
$rem = $s->documents['recordatorios/' . $id] ?? null;
ok($rem !== null && $rem['tipo'] === 'inactividad' && $rem['userId'] === 'ana' && $rem['mascotaId'] === 'luna'
    && $rem['completado'] === false && $rem['entidadId'] === 'p1' && str_contains($rem['mensaje'], 'Luna'), 'aviso mal formado');
for ($i = 1; $i <= 5; $i++) $svc->run($t0->modify("+{$i}0 hours")->modify('+48 hours'));
ok(count($s->reminders()) === 1 && $s->creates === 1, 'el cron repetido no debe duplicar el episodio');

// Paseo nuevo: completa el aviso viejo y abre otro episodio.
$s->documents['paseos/p2'] = ['userId' => 'ana', 'mascotaId' => 'luna',
    'fechaInicio' => $t0->modify('+60 hours'), 'fechaFin' => $t0->modify('+61 hours')];
$r = $svc->run($t0->modify('+62 hours'));
ok($r['completados'] === 1 && $s->documents['recordatorios/' . $id]['completado'] === true, 'un paseo nuevo debe completar el aviso previo');
ok(count($s->reminders()) === 1, 'tras el paseo aún no corresponde otro aviso');
$r = $svc->run($t0->modify('+61 hours')->modify('+48 hours'));
ok($r['creados'] === 1 && count($s->reminders()) === 2, 'el nuevo episodio genera su propio aviso');

// Paseo que aparece mientras se prepara el aviso: no se crea.
$s = new InactivityStore(); seed($s, $on, $t0);
$s->afterFirstWalkQuery = static function () use ($s, $t0): void {
    $s->documents['paseos/p9'] = ['userId' => 'ana', 'mascotaId' => 'luna',
        'fechaInicio' => $t0->modify('+49 hours'), 'fechaFin' => $t0->modify('+50 hours')];
};
$r = (new InactivityReminderService($s))->run($t0->modify('+50 hours'));
ok($s->creates === 0, 'un paseo concurrente debe impedir el aviso');

// Preferencias: apagado, recordatorios apagados, cuenta no activa.
foreach ([
    ['preferencias apagadas', ['inactividad' => ['habilitada' => false, 'intervaloHoras' => 48]], []],
    ['cuidados apagados', ['recordatoriosCuidados' => false] + $on, []],
    ['cuenta no activa', $on, ['estadoCuenta' => 'eliminando']],
] as [$label, $prefs, $extra]) {
    $s = new InactivityStore(); seed($s, $prefs, $t0, $extra);
    (new InactivityReminderService($s))->run($t0->modify('+200 hours'));
    ok($s->creates === 0, 'no debe avisar con ' . $label);
}

// Intervalo inválido guardado: se usa el predeterminado (72 h), no el valor roto.
$s = new InactivityStore(); seed($s, ['inactividad' => ['habilitada' => true, 'intervaloHoras' => 1]], $t0);
$svc = new InactivityReminderService($s);
$svc->run($t0->modify('+71 hours')); ok($s->creates === 0, 'intervalo inválido: antes de 72 h no avisa');
$svc->run($t0->modify('+72 hours')); ok($s->creates === 1, 'intervalo inválido: a las 72 h avisa');

// Mascota sin paseos: solo si la persona lo pidió.
$s = new InactivityStore(); seed($s, $on, null);
(new InactivityReminderService($s))->run($t0->modify('+90 days'));
ok($s->creates === 0, 'sin paseos y sin opt-in no avisa');
$s = new InactivityStore(); seed($s, ['recordatoriosCuidados' => true, 'inactividad' => ['habilitada' => true, 'intervaloHoras' => 48, 'incluirSinPaseos' => true]], null);
(new InactivityReminderService($s))->run($t0->modify('+90 days'));
ok($s->creates === 1, 'sin paseos y con opt-in avisa desde el alta');

// Mascota ajena o borrada: sin mascotas no hay avisos.
$s = new InactivityStore(); seed($s, $on, $t0);
$s->documents['mascotas/luna']['userId'] = 'otra';
(new InactivityReminderService($s))->run($t0->modify('+200 hours'));
ok($s->creates === 0, 'una mascota de otra cuenta no genera avisos');
$s = new InactivityStore(); seed($s, $on, $t0); unset($s->documents['mascotas/luna']);
(new InactivityReminderService($s))->run($t0->modify('+200 hours'));
ok($s->creates === 0, 'sin mascota no hay aviso');

// dryRun no escribe.
$s = new InactivityStore(); seed($s, $on, $t0);
$r = (new InactivityReminderService($s))->run($t0->modify('+100 hours'), dryRun: true);
ok($r['creados'] === 1 && $s->creates === 0, 'dryRun cuenta pero no escribe');

// Preferencias vía PrivacyService.
$memory = new MemoryFirestoreTransport();
$db = new FirestoreClient('test-project', null, $memory);
$memory->put('users/a', ['nombre' => 'A']);
$privacy = new PrivacyService($db);
$p = $privacy->info('a')['preferencias'];
ok($p['inactividad'] === ['habilitada' => false, 'intervaloHoras' => 72, 'incluirSinPaseos' => false], 'la inactividad debe estar apagada por defecto');
bad(fn () => $privacy->updatePreferences('a', ['inactividad' => true]), 400);
bad(fn () => $privacy->updatePreferences('a', ['inactividad' => []]), 400);
bad(fn () => $privacy->updatePreferences('a', ['inactividad' => ['habilitada' => 'si']]), 400);
bad(fn () => $privacy->updatePreferences('a', ['inactividad' => ['intervaloHoras' => 5]]), 400);
bad(fn () => $privacy->updatePreferences('a', ['inactividad' => ['intervaloHoras' => 721]]), 400);
bad(fn () => $privacy->updatePreferences('a', ['inactividad' => ['intervaloHoras' => '48']]), 400);
bad(fn () => $privacy->updatePreferences('a', ['inactividad' => ['otro' => 1]]), 400);
$after = $privacy->updatePreferences('a', ['inactividad' => ['habilitada' => true, 'intervaloHoras' => 96]])['preferencias'];
ok($after['inactividad']['habilitada'] === true && $after['inactividad']['intervaloHoras'] === 96 && $after['recordatoriosCuidados'] === true, 'no se guardó la inactividad');
$after = $privacy->updatePreferences('a', ['inactividad' => ['incluirSinPaseos' => true]])['preferencias'];
ok($after['inactividad'] === ['habilitada' => true, 'intervaloHoras' => 96, 'incluirSinPaseos' => true], 'la actualización parcial debe conservar lo demás');

echo "TODO OK: inactividad (umbral, episodios sin duplicados, paseo nuevo, carrera, preferencias, opt-in, dryRun)\n";
