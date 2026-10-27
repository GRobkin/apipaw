<?php
declare(strict_types=1);

namespace PawLife\Firestore;

interface AtomicStore
{
    public function getDocumentVersioned(string $documentPath): ?array;
    /** Operaciones internas tipadas; nunca exponer este formato como endpoint. */
    public function commit(array $operations): array;
}
