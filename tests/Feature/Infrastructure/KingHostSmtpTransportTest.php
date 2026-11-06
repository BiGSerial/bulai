<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

final class KingHostSmtpTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'kinghost',
            'mail.from.address' => 'nao-responda@bulai.test',
            'services.kinghost_smtp.token' => 'token-de-teste',
        ]);
        Mail::purge('kinghost');
    }

    public function test_envia_um_pedido_por_destinatario_com_token_e_corpo_html(): void
    {
        Http::fake(['api.smtplw.com.br/*' => Http::response(['data' => ['id' => 1]], 201)]);

        Mail::html('<p>Olá, <a href="https://bulai.test">link</a></p>', function ($message): void {
            $message->to(['a@example.com', 'b@example.com'])->subject('Assunto ç');
        });

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.smtplw.com.br/v1/messages'
            && $request->hasHeader('x-auth-token', 'token-de-teste')
            && $request['from'] === 'nao-responda@bulai.test'
            && $request['to'] === 'a@example.com'
            && $request['subject'] === 'Assunto ç'
            && str_contains($request['body'], '<a href="https://bulai.test">')
            && str_contains($request['headers']['Content-Type'], 'text/html'));
    }

    public function test_resposta_de_erro_da_api_vira_excecao_sem_vazar_o_token(): void
    {
        Http::fake(['api.smtplw.com.br/*' => Http::response(['errors' => [['detail' => 'domínio não autorizado']]], 403)]);

        try {
            Mail::raw('texto', fn ($message) => $message->to('a@example.com')->subject('x'));
            $this->fail('Era esperada uma TransportException.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('HTTP 403', $exception->getMessage());
            $this->assertStringContainsString('domínio não autorizado', $exception->getMessage());
            $this->assertStringNotContainsString('token-de-teste', $exception->getMessage());
        }
    }

    public function test_sem_token_configurado_nao_chama_a_api(): void
    {
        config(['services.kinghost_smtp.token' => null]);
        Mail::purge('kinghost');
        Http::fake();

        $this->expectException(TransportException::class);

        try {
            Mail::raw('texto', fn ($message) => $message->to('a@example.com')->subject('x'));
        } finally {
            Http::assertNothingSent();
        }
    }
}
