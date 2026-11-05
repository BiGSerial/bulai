<?php

namespace App\Notifications\Channels;

/** Notificação que não pode ser gravada duas vezes: a chave vai para `notifications.dedupe_key` (única). */
interface HasDedupeKey
{
    public function dedupeKey(): ?string;
}
