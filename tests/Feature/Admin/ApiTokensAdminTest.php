<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ApiTokens\Index;
use App\Models\Knowledge\IngestionToken;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Admin › Acesso à API: emite o token uma única vez (o banco guarda só o hash) e revoga na hora. */
final class ApiTokensAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_is_admin_only(): void
    {
        $this->get('/admin/api')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/api')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/admin/api')->assertOk()->assertSee('Emitir token');
    }

    public function test_admin_issues_a_token_shown_once_and_revokes_it(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        IngestionToken::issue('ingestão', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());

        $component = Livewire::actingAs($admin)->test(Index::class)
            ->set('nome', 'agente de avaliação')
            ->set('escopo', IngestionToken::SCOPE_API_EVALUATE)
            ->set('validade', 7)
            ->set('unidade', 'dias')
            ->call('emitir')
            ->assertHasNoErrors()
            ->assertDontSee('ingestão');
        $plain = $component->get('tokenNovo');
        $token = IngestionToken::query()->where('name', 'agente de avaliação')->firstOrFail();

        $this->assertSame(64, strlen($plain));
        $this->assertSame(hash('sha256', $plain), $token->token_hash, 'só o hash fica no banco');
        $this->assertSame(IngestionToken::SCOPE_API_EVALUATE, $token->scope);
        $this->assertTrue($token->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

        $component->call('revogar', $token->id)->assertSet('tokenNovo', null)->assertSee('revogado');

        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame([UserAuditEvent::EVENT_API_TOKEN_ISSUED, UserAuditEvent::EVENT_API_TOKEN_REVOKED], UserAuditEvent::query()->where('actor_id', $admin->id)->orderBy('id')->pluck('event')->all());
    }

    public function test_a_token_lasts_exactly_the_minutes_or_hours_chosen_and_then_stops_working(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->freezeSecond();

        $plain = Livewire::actingAs($admin)->test(Index::class)
            ->set('nome', 'avaliação de hoje')->set('validade', 90)->set('unidade', 'minutos')
            ->call('emitir')
            ->assertHasNoErrors()
            ->get('tokenNovo');
        $hours = Livewire::actingAs($admin)->test(Index::class)
            ->set('nome', 'tarde')->set('validade', 3)->set('unidade', 'horas')
            ->call('emitir')
            ->get('tokenNovo');

        $this->assertTrue(IngestionToken::query()->where('name', 'avaliação de hoje')->value('expires_at')->equalTo(now()->addMinutes(90)));
        $this->assertTrue(IngestionToken::query()->where('name', 'tarde')->value('expires_at')->equalTo(now()->addHours(3)));
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

        $this->travel(91)->minutes();

        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($hours)->getJson('/api/v1/me')->assertOk();
    }

    public function test_only_api_scopes_and_offered_validities_are_accepted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$ingest] = IngestionToken::issue('ingestão', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());

        Livewire::actingAs($admin)->test(Index::class)
            ->set('nome', 'x')->set('escopo', IngestionToken::SCOPE_KNOWLEDGE_INGEST)->set('validade', 91)->set('unidade', 'dias')
            ->call('emitir')
            ->assertHasErrors(['escopo', 'validade'])
            ->set('escopo', IngestionToken::SCOPE_API_READ)->set('validade', 0)->set('unidade', 'minutos')
            ->call('emitir')
            ->assertHasErrors(['validade'])
            ->set('validade', 2)->set('unidade', 'semanas')
            ->call('emitir')
            ->assertHasErrors(['unidade'])
            ->call('revogar', $ingest->id);

        $this->assertNull($ingest->fresh()->revoked_at, 'a tela não mexe nos tokens de ingestão');
    }
}
