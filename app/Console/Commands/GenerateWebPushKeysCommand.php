<?php

namespace App\Console\Commands;

use App\Services\Push\WebPushSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('webpush:generate-keys {--subject=mailto:contato@envios.vixforge.com.br : Contato do responsável (mailto: ou https://) exigido pelo VAPID}')]
#[Description('Gera o par de chaves VAPID do Web Push e imprime as linhas para o app.env (a chave privada é um segredo)')]
class GenerateWebPushKeysCommand extends Command
{
    public function handle(): int
    {
        $keys = WebPushSender::generateKeys();
        $this->line('WEBPUSH_PUBLIC_KEY='.$keys['public_key']);
        $this->line('WEBPUSH_PRIVATE_KEY='.base64_encode($keys['private_pem']));
        $this->line('WEBPUSH_SUBJECT='.$this->option('subject'));
        $this->components->warn('Coloque as três linhas só em /srv/bulai/shared/env/app.env (WEBPUSH_PRIVATE_KEY é segredo) e rode `sudo bulai-deploy activate`. Trocar a chave pública invalida as inscrições existentes: os aparelhos precisam reativar os avisos.');

        return self::SUCCESS;
    }
}
