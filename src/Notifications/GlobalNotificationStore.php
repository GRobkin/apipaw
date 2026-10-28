<?php

declare(strict_types=1);

namespace PawLife\Notifications;

/** Lo que además necesita el envío a todos: recorrer una colección por páginas estables. */
interface GlobalNotificationStore extends ReminderStore
{
    /**
     * Documentos de una colección raíz ordenados por ID, a partir de
     * [$afterId] (excluido). El orden por ID es estable aunque entren
     * documentos nuevos durante el recorrido.
     *
     * @return list<array{id: string, data: array<string, mixed>}>
     */
    public function collectionPage(string $collection, ?string $afterId, int $limit): array;
}
