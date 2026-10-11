<?php

declare(strict_types=1);

namespace PawLife\Notifications;

interface PushSender
{
    public function send(string $deviceToken, string $reminderId, string $petId, string $type, string $message): void;
}
