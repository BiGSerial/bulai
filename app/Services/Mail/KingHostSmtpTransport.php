<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * Transport do Laravel para a API do SMTP Transacional da KingHost (POST /v1/messages).
 *
 * Envia um pedido por destinatário (cada um consome 1 crédito) e usa o corpo HTML quando existir.
 * Anexos não são suportados. O token nunca entra em mensagens de erro nem em logs.
 */
final class KingHostSmtpTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = 'https://api.smtplw.com.br/v1',
        private readonly int $timeoutSeconds = 15,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->token === '') {
            throw new TransportException('KingHost SMTP Transacional: token da API não configurado (KINGHOST_SMTP_TOKEN).');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());

        if ($email->getAttachments() !== []) {
            throw new TransportException('KingHost SMTP Transacional: anexos não são suportados por este transport.');
        }

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $isHtml = is_string($html) && $html !== '';
        $body = $isHtml ? $html : (is_string($text) ? $text : '');

        $headers = ['Content-Type' => ($isHtml ? 'text/html' : 'text/plain').'; charset=UTF-8'];

        if ($email->getReplyTo() !== []) {
            $headers['Reply-To'] = $email->getReplyTo()[0]->toString();
        }

        $envelope = $message->getEnvelope();

        foreach ($envelope->getRecipients() as $recipient) {
            $this->post([
                'from' => $envelope->getSender()->getAddress(),
                'to' => $recipient->getAddress(),
                'subject' => (string) $email->getSubject(),
                'body' => $body,
                'headers' => $headers,
            ]);
        }
    }

    /**
     * @param  array{from: string, to: string, subject: string, body: string, headers: array<string, string>}  $payload
     */
    private function post(array $payload): void
    {
        try {
            $response = Http::withHeaders(['x-auth-token' => $this->token])
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeoutSeconds)
                ->post(rtrim($this->baseUrl, '/').'/messages', $payload);
        } catch (ConnectionException $exception) {
            throw new TransportException('KingHost SMTP Transacional: falha de conexão com a API.', 0, $exception);
        }

        if ($response->status() !== 201) {
            $detail = $response->json('errors.0.detail');

            throw new TransportException(sprintf(
                'KingHost SMTP Transacional recusou o envio (HTTP %d): %s',
                $response->status(),
                is_string($detail) ? $detail : 'sem detalhe',
            ));
        }
    }

    public function __toString(): string
    {
        return 'kinghost';
    }
}
