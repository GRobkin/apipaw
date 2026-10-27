<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use PawLife\Firestore\BackupExport;
use PawLife\Firestore\FirestoreClient;

function backupCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$fields = ['int64' => ['integerValue' => '9223372036854775807'], 'bytes' => ['bytesValue' => 'YWJj'],
    'ref' => ['referenceValue' => 'projects/test-project/databases/(default)/documents/pets/a'],
    'emptyMap' => ['mapValue' => ['fields' => new stdClass()]]];
$calls = [];
$transport = static function ($method, $url, $body) use (&$calls, $fields): array {
    $calls[] = [$method, $url, $body];
    $path = parse_url($url, PHP_URL_PATH); $query = []; parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    $tail = explode('/documents', $path, 2)[1];
    $response = [];
    if ($tail === ':listCollectionIds') $response = isset($body['pageToken']) ? ['collectionIds' => ['empty']] : ['collectionIds' => ['pets'], 'nextPageToken' => 'collections-2'];
    elseif ($tail === '/pets/missing:listCollectionIds') $response = ['collectionIds' => ['notes']];
    elseif ($tail === '/pets') {
        backupCheck($query['showMissing'] === 'true', 'No enumera padres faltantes');
        $response = isset($query['pageToken']) ? ['documents' => [['name' => 'projects/test-project/databases/(default)/documents/pets/missing']]]
            : ['documents' => [['name' => 'projects/test-project/databases/(default)/documents/pets/a', 'createTime' => '2026-10-20T00:00:00Z', 'fields' => $fields]], 'nextPageToken' => 'docs-2'];
    } elseif ($tail === '/pets/missing/notes') $response = ['documents' => [['name' => 'projects/test-project/databases/(default)/documents/pets/missing/notes/n', 'createTime' => '2026-10-20T00:00:00Z', 'fields' => new stdClass()]]];
    return ['status' => 200, 'body' => json_encode($response, JSON_THROW_ON_ERROR), 'headers' => []];
};
$records = [];
BackupExport::run(new FirestoreClient('test-project', null, $transport), 'test-project', static function ($r) use (&$records): void { $records[] = $r; });
$documents = array_values(array_filter($records, static fn ($r): bool => $r['type'] === 'document'));
backupCheck(count($documents) === 2, 'Omitió documentos o convirtió padre faltante en documento');
backupCheck($documents[0]['fields']['int64']['integerValue'] === '9223372036854775807', 'Perdió precisión REST');
backupCheck($documents[0]['fields']['bytes']['bytesValue'] === 'YWJj', 'Perdió bytes');
$end = $records[array_key_last($records)];
backupCheck($end['documents'] === 2 && $end['collections'] === 3, 'Conteos parciales');
backupCheck(count(array_filter($calls, static fn ($c): bool => $c[0] !== 'GET' && !str_ends_with($c[1], ':listCollectionIds'))) === 0, 'Exportación realizó escrituras');
$loop = new FirestoreClient('test-project', null, static fn (): array => ['status' => 200, 'body' => '{"collectionIds":[],"nextPageToken":"same"}', 'headers' => []]);
$seenEnd = false;
try {
    BackupExport::run($loop, 'test-project', static function ($r) use (&$seenEnd): void { if ($r['type'] === 'end') $seenEnd = true; });
    throw new LogicException('Aceptó bucle');
} catch (RuntimeException) {}
backupCheck(!$seenEnd, 'Una copia incompleta tiene marcador de éxito');
echo "TODO OK: exportación paginada, padres faltantes, subcolecciones, tipos REST y fallo sin cierre falso\n";
