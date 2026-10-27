<?php
declare(strict_types=1);

use PawLife\Firestore\Value;

/** Doble de transporte REST, no simulación de permisos/IAM de producción. */
final class MemoryFirestoreTransport
{
    public array $records = [];
    public array $requests = [];
    public mixed $beforeCommit = null;
    public mixed $beforePatch = null;
    public mixed $beforeQuery = null;
    public bool $loseCommitResponse = false;
    private int $version = 0;

    public function put(string $path, array $data): void
    {
        $this->version++;
        $time = (new DateTimeImmutable('2026-10-20T00:00:00Z'))->modify('+' . $this->version . ' microseconds');
        $this->records[$path] = ['data' => $data, 'updateTime' => $time->format('Y-m-d\TH:i:s.u\Z')];
    }

    private function path(string $name): string
    {
        return substr($name, strpos($name, '/documents/') + strlen('/documents/'));
    }

    private function document(string $path, array $record): array
    {
        return ['name' => 'projects/test-project/databases/(default)/documents/' . $path,
            'fields' => Value::encodeFields($record['data']), 'createTime' => $record['updateTime'], 'updateTime' => $record['updateTime']];
    }

    private static function response(int $status, mixed $body): array
    {
        return ['status' => $status, 'body' => json_encode($body, JSON_THROW_ON_ERROR), 'headers' => []];
    }

    public function __invoke(string $method, string $url, ?array $body): array
    {
        $this->requests[] = [$method, $url, $body];
        if (str_ends_with($url, ':commit')) {
            if ($this->beforeCommit !== null) {
                $hook = $this->beforeCommit; $this->beforeCommit = null; $hook($this);
            }
            foreach ($body['writes'] as $write) {
                if (isset($write['verify'])) throw new RuntimeException('REST Write no admite verify.');
                $path = $this->path($write['update']['name'] ?? $write['delete']);
                $current = $this->records[$path] ?? null;
                $condition = $write['currentDocument'];
                if ((isset($condition['exists']) && $condition['exists'] === false && $current !== null)
                    || (isset($condition['updateTime']) && ($current['updateTime'] ?? null) !== $condition['updateTime'])) {
                    return self::response(400, ['error' => ['status' => 'FAILED_PRECONDITION']]);
                }
            }
            foreach ($body['writes'] as $write) {
                $path = $this->path($write['update']['name'] ?? $write['delete']);
                if (isset($write['delete'])) { unset($this->records[$path]); continue; }
                $data = Value::decodeFields($write['update']['fields']);
                $this->put($path, isset($write['updateMask']) ? array_merge($this->records[$path]['data'], $data) : $data);
            }
            if ($this->loseCommitResponse) { $this->loseCommitResponse = false; throw new RuntimeException('Respuesta de commit perdida (fixture).'); }
            return self::response(200, ['writeResults' => array_fill(0, count($body['writes']), ['updateTime' => '2026-10-20T00:00:01Z']), 'commitTime' => '2026-10-20T00:00:01Z']);
        }
        if (str_ends_with($url, ':listCollectionIds')) {
            $urlPath = parse_url($url, PHP_URL_PATH);
            $parent = trim(rawurldecode(substr($urlPath, strpos($urlPath, '/documents') + strlen('/documents'), -strlen(':listCollectionIds'))), '/');
            $ids = [];
            foreach (array_keys($this->records) as $key) {
                $parts = explode('/', $key);
                if ($parent === '' ? count($parts) >= 1 : str_starts_with($key, $parent . '/')) {
                    $offset = $parent === '' ? 0 : count(explode('/', $parent));
                    if (isset($parts[$offset]) && count($parts) > $offset + 1) $ids[$parts[$offset]] = true;
                }
            }
            $ids = array_keys($ids); sort($ids);
            return self::response(200, $ids === [] ? new stdClass() : ['collectionIds' => $ids]);
        }
        if (str_ends_with($url, ':runQuery')) {
            if ($this->beforeQuery !== null) { $hook = $this->beforeQuery; $this->beforeQuery = null; $hook($this); }
            $query = $body['structuredQuery']; $collection = $query['from'][0]['collectionId'];
            $clauses = isset($query['where']['compositeFilter']) ? $query['where']['compositeFilter']['filters'] : [$query['where']];
            $rows = [];
            foreach ($this->records as $path => $record) {
                if (!str_starts_with($path, "$collection/") || count(explode('/', $path)) !== 2) continue;
                foreach ($clauses as $clause) {
                    $f = $clause['fieldFilter'];
                    if (($record['data'][$f['field']['fieldPath']] ?? null) != Value::decode($f['value'])) continue 2;
                }
                $rows[] = ['path' => $path] + $record;
            }
            $compare = static function (array $a, array $b) use ($query): int {
                foreach ($query['orderBy'] as $sort) {
                    $field = $sort['field']['fieldPath'];
                    $left = $field === '__name__' ? $a['path'] : $a['data'][$field];
                    $right = $field === '__name__' ? $b['path'] : $b['data'][$field];
                    $result = $left <=> $right;
                    if ($result !== 0) return $sort['direction'] === 'DESCENDING' ? -$result : $result;
                }
                return 0;
            };
            usort($rows, $compare);
            if (isset($query['startAt'])) {
                $values = $query['startAt']['values'];
                $last = end($values); $after = ['path' => $this->path($last['referenceValue']), 'data' => []];
                if (count($values) > 1) $after['data'][$query['orderBy'][0]['field']['fieldPath']] = Value::decode($values[0]);
                $rows = array_values(array_filter($rows, static fn ($row): bool => $compare($row, $after) > 0));
            }
            return self::response(200, array_map(fn ($row): array => ['document' => $this->document($row['path'], $row)], array_slice($rows, 0, $query['limit'])));
        }
        $urlPath = parse_url($url, PHP_URL_PATH);
        $offset = strpos($urlPath, '/documents/') + strlen('/documents/');
        $path = implode('/', array_map('rawurldecode', explode('/', substr($urlPath, $offset))));
        if ($method === 'GET' && count(explode('/', $path)) % 2 === 1) {
            $query = []; parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            $rows = [];
            foreach ($this->records as $key => $record) {
                if (!str_starts_with($key, $path . '/')) continue;
                $id = explode('/', substr($key, strlen($path) + 1))[0];
                $documentPath = $path . '/' . $id;
                $rows[$documentPath] = isset($this->records[$documentPath]) ? $this->document($documentPath, $this->records[$documentPath])
                    : ['name' => 'projects/test-project/databases/(default)/documents/' . $documentPath];
            }
            ksort($rows); $rows = array_values($rows);
            $start = (int) ($query['pageToken'] ?? 0); $size = (int) ($query['pageSize'] ?? 20);
            return self::response(200, ['documents' => array_slice($rows, $start, $size)]
                + ($start + $size < count($rows) ? ['nextPageToken' => (string) ($start + $size)] : []));
        }
        if ($method === 'GET') return isset($this->records[$path]) ? self::response(200, $this->document($path, $this->records[$path])) : self::response(404, []);
        if ($method === 'PATCH') {
            if ($this->beforePatch !== null) { $hook = $this->beforePatch; $this->beforePatch = null; $hook($this); }
            $rawQuery = parse_url($url, PHP_URL_QUERY) ?? '';
            preg_match('/(?:^|&)currentDocument\.updateTime=([^&]+)/', $rawQuery, $match);
            if (isset($match[1]) && ($this->records[$path]['updateTime'] ?? null) !== rawurldecode($match[1])) return self::response(400, ['error' => ['status' => 'FAILED_PRECONDITION']]);
            if (!isset($this->records[$path])) return self::response(404, []);
            $this->put($path, array_merge($this->records[$path]['data'], Value::decodeFields($body['fields'])));
            return self::response(200, $this->document($path, $this->records[$path]));
        }
        throw new RuntimeException('Petición no prevista en el fixture: ' . $method);
    }
}
