<?php

namespace App\Notifications\Channels;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;

/**
 * Canal `database` com chave de deduplicação: notificações que expõem `dedupeKey()` gravam a chave na coluna única
 * `notifications.dedupe_key`; uma segunda gravação com a mesma chave é ignorada em silêncio (nunca duplica o aviso).
 * A existência é conferida antes de gravar (evita abortar transações abertas); a restrição única cobre a corrida.
 */
final class DedupedDatabaseChannel extends DatabaseChannel
{
    public function send(mixed $notifiable, Notification $notification): mixed
    {
        $key = $notification instanceof HasDedupeKey ? $notification->dedupeKey() : null;
        if ($key !== null && DatabaseNotification::query()->where('dedupe_key', $key)->exists()) {
            return null;
        }

        try {
            return parent::send($notifiable, $notification);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    protected function buildPayload(mixed $notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);
        if ($notification instanceof HasDedupeKey) {
            $payload['dedupe_key'] = $notification->dedupeKey();
        }

        return $payload;
    }
}
