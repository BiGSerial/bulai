<?php

namespace Tests\Feature\Consulta;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Push\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Web Push sem dependência: chaves VAPID, JWT ES256 verificável, push vazio, limpeza de endpoints expirados e texto do aviso. */
final class WebPushTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    /** @var array{private_pem: string, public_key: string} */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = WebPushSender::generateKeys();
        config(['services.webpush' => ['public_key' => $this->keys['public_key'], 'private_key' => base64_encode($this->keys['private_pem']), 'subject' => 'mailto:contato@example.com']]);
    }

    private function subscribe(User $user, string $endpoint = self::ENDPOINT): PushSubscription
    {
        return PushSubscription::query()->create(['user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashFor($endpoint)]);
    }

    private function rawToDer(string $raw): string
    {
        $integer = fn (string $part): string => "\x02".chr(strlen($part = (ord($part[0]) & 0x80 ? "\0" : '').ltrim($part, "\0") ?: "\0")).$part;
        $body = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32, 32));

        return "\x30".chr(strlen($body)).$body;
    }

    public function test_the_push_is_empty_and_signed_with_a_vapid_jwt_that_verifies_with_the_public_key(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 201)]);
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);

        $this->assertTrue(app(WebPushSender::class)->send($subscription));

        Http::assertSent(function ($request) {
            $authorization = $request->header('Authorization')[0] ?? '';
            preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $authorization, $m);
            $this->assertNotEmpty($m, 'Authorization no formato VAPID');
            [, $header, $claims, $signature, $key] = $m;
            $decode = fn (string $v): string => base64_decode(strtr($v, '-_', '+/').str_repeat('=', (4 - strlen($v) % 4) % 4));
            $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode($decode($header), true));
            $payload = json_decode($decode($claims), true);
            $this->assertSame(['https://fcm.googleapis.com', 'mailto:contato@example.com'], [$payload['aud'], $payload['sub']]);
            $this->assertGreaterThan(time(), $payload['exp']);
            $this->assertSame($this->keys['public_key'], $key);
            $this->assertSame(65, strlen($decode($key)), 'chave pública P-256 não comprimida');
            $this->assertSame(64, strlen($decode($signature)), 'assinatura r‖s');
            $public = openssl_pkey_get_public(openssl_pkey_get_details(openssl_pkey_get_private($this->keys['private_pem']))['key']);
            $this->assertSame(1, openssl_verify($header.'.'.$claims, $this->rawToDer($decode($signature)), $public, OPENSSL_ALGO_SHA256), 'a assinatura confere com a chave pública');

            return $request->url() === self::ENDPOINT && $request->body() === '' && ($request->header('TTL')[0] ?? null) === '3600' && ($request->header('Urgency')[0] ?? null) === 'high';
        });
        $this->assertNotNull($subscription->fresh()->last_success_at);
    }

    public function test_expired_endpoints_are_deleted_and_other_failures_are_counted(): void
    {
        $user = User::factory()->create();
        $gone = $this->subscribe($user, 'https://push.example/gone');
        $flaky = $this->subscribe($user, 'https://push.example/flaky');
        Http::fake(['https://push.example/gone' => Http::response('', 410), 'https://push.example/flaky' => Http::response('erro', 500)]);

        $this->assertFalse(app(WebPushSender::class)->send($gone));
        $this->assertFalse(app(WebPushSender::class)->send($flaky));

        $this->assertNull(PushSubscription::query()->find($gone->id));
        $this->assertSame(1, $flaky->fresh()->failures);
    }

    public function test_the_channel_is_added_only_with_keys_and_a_subscribed_device_and_never_breaks_the_notification(): void
    {
        $user = User::factory()->create();
        $this->assertSame(['database'], WebPushChannel::appendTo($user, ['database']), 'sem aparelho inscrito');

        $this->subscribe($user);
        $this->assertSame(['database', WebPushChannel::class], WebPushChannel::appendTo($user, ['database']));

        config(['services.webpush.private_key' => null]);
        $this->assertSame(['database'], WebPushChannel::appendTo($user, ['database']), 'sem chaves VAPID');

        config(['services.webpush.private_key' => base64_encode($this->keys['private_pem'])]);
        Http::fake([self::ENDPOINT => Http::response('', 201)]);
        app(WebPushChannel::class)->send($user, new Notification);
        Http::assertSentCount(1);
    }

    public function test_the_device_subscribes_unsubscribes_and_reads_the_latest_unread_notice_of_its_own_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->postJson(route('push.store'), ['endpoint' => self::ENDPOINT])->assertOk();
        $this->actingAs($user)->postJson(route('push.store'), ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame([1, $user->id], [PushSubscription::query()->count(), PushSubscription::query()->value('user_id')]);
        $this->actingAs($user)->postJson(route('push.store'), ['endpoint' => 'http://inseguro.example/x'])->assertStatus(422);

        $make = fn (User $owner, array $data, $readAt = null) => DatabaseNotification::query()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $owner->id, 'data' => $data, 'read_at' => $readAt]);
        $make($other, ['title' => 'De outra pessoa', 'body' => 'não pode vazar']);
        $make($user, ['title' => 'Antigo lido', 'body' => 'lido'], now());
        $notice = $make($user, ['title' => 'Hora do remédio: LOSARTANA', 'body' => 'Às 08:00.', 'route' => 'consulta.hoje']);

        $this->actingAs($user)->getJson(route('push.latest'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['title' => 'Hora do remédio: LOSARTANA', 'body' => 'Às 08:00.', 'url' => '/hoje', 'tag' => $notice->id])->assertJsonMissing(['title' => 'De outra pessoa']);

        $notice->markAsRead();
        $this->actingAs($user)->getJson(route('push.latest'))->assertJson(['title' => 'Aviso do BULA-IA']);

        $this->actingAs($user)->deleteJson(route('push.destroy'), ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, PushSubscription::query()->count());
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson(route('push.store'), ['endpoint' => self::ENDPOINT])->assertUnauthorized();
    }

    public function test_the_avisos_page_offers_push_only_when_the_keys_exist_and_the_service_worker_handles_the_push(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('consulta.avisos'))->assertOk()->assertSee('Receber avisos também com o aplicativo fechado', false)->assertSee($this->keys['public_key'], false);

        config(['services.webpush.public_key' => null]);
        $this->actingAs($user)->get(route('consulta.avisos'))->assertOk()->assertDontSee($this->keys['public_key'], false);

        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("addEventListener('push'", $sw);
        $this->assertStringContainsString('/push/ultimo', $sw);
        $this->artisan('webpush:generate-keys')->expectsOutputToContain('WEBPUSH_PUBLIC_KEY=')->expectsOutputToContain('WEBPUSH_PRIVATE_KEY=')->assertSuccessful();
    }
}
