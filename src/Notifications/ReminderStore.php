<?php

declare(strict_types=1);

namespace PawLife\Notifications;

use DateTimeImmutable;

/** Operaciones de datos que necesita el programador de avisos. */
interface ReminderStore
{
    public function queryDateWindowPage(string $collection, string $field, DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 100, ?array $after = null): array;

    public function queryDocuments(string $collection, array $filters, ?string $orderBy = null, ?int $limit = null): array;

    public function getDocument(string $documentPath): ?array;

    public function getDocumentVersioned(string $documentPath): ?array;

    public function createDocument(string $collectionPath, array $data, ?string $documentId = null): array;

    public function patchDocument(string $documentPath, array $data, ?string $expectedUpdateTime = null): array;
}
