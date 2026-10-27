<?php
declare(strict_types=1);
namespace PawLife\Firestore;
use RuntimeException;
use stdClass;

final class BackupExport
{
public static function run(FirestoreClient $db, string $project, callable $emit): void
{
    if (preg_match('/^[a-z][a-z0-9-]{3,62}$/D', $project) !== 1) throw new RuntimeException();
    $prefix = "projects/$project/databases/(default)/documents/";
    $emit(['type' => 'begin', 'format' => 1, 'project' => $project, 'startedAt' => gmdate('c')]);
    $count = 0; $collections = 0; $visited = []; $pages = 0;
    $walk = static function (string $parent, int $depth = 0) use (&$walk, $db, $prefix, $emit, &$count, &$collections, &$visited, &$pages): void {
        if ($depth > 100) throw new RuntimeException();
        $token = null; $seen = [];
        do {
            if (++$pages > 100000) throw new RuntimeException();
            $page = $db->exportPage($parent, null, $token);
            if (!isset($page['collectionIds'])) $page['collectionIds'] = [];
            if (!is_array($page['collectionIds'])) throw new RuntimeException();
            foreach ($page['collectionIds'] as $id) {
                \PawLife\Support\ResourceId::validate($id);
                $collection = ltrim("$parent/$id", '/');
                if (isset($visited[$collection])) throw new RuntimeException();
                $visited[$collection] = true; $collections++;
                $emit(['type' => 'collection', 'path' => $collection]);
                $docToken = null; $docSeen = [];
                do {
                    if (++$pages > 100000) throw new RuntimeException();
                    $docs = $db->exportPage($parent, $id, $docToken);
                    foreach ($docs['documents'] ?? [] as $doc) {
                        if (!is_array($doc) || !is_string($doc['name'] ?? null) || !str_starts_with($doc['name'], $prefix . $collection . '/')) throw new RuntimeException();
                        $path = substr($doc['name'], strlen($prefix));
                        if (count(explode('/', $path)) !== count(explode('/', $collection)) + 1) throw new RuntimeException();
                        if (isset($doc['createTime'])) {
                            $emit(['type' => 'document', 'path' => $path, 'fields' => $doc['fields'] ?? new stdClass()]); $count++;
                        }
                        $walk($path, $depth + 1);
                    }
                    $docToken = $docs['nextPageToken'] ?? null;
                    if ($docToken !== null && (!is_string($docToken) || $docToken === '' || isset($docSeen[$docToken]))) throw new RuntimeException();
                    if ($docToken !== null) $docSeen[$docToken] = true;
                } while ($docToken !== null);
            }
            $token = $page['nextPageToken'] ?? null;
            if ($token !== null && (!is_string($token) || $token === '' || isset($seen[$token]))) throw new RuntimeException();
            if ($token !== null) $seen[$token] = true;
        } while ($token !== null);
    };
    $walk('');
    $emit(['type' => 'end', 'documents' => $count, 'collections' => $collections, 'finishedAt' => gmdate('c')]);
}
}
