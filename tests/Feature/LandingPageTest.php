<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Página pública (landing): estrutura comercial, SEO, acessibilidade básica, vocabulário do produto e nenhum segredo. */
final class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitors_see_a_complete_landing_with_seo_metadata_and_calls_to_action(): void
    {
        $response = $this->get('/')->assertOk();
        $html = $response->getContent();

        $response->assertSee('<title>BULA-IA · Entenda seus remédios em linguagem simples</title>', false)
            ->assertSee('<meta name="description"', false)
            ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
            ->assertSee('property="og:title"', false)
            ->assertSee('property="og:url" content="'.url('/').'"', false)
            ->assertSee('name="twitter:card"', false)
            ->assertSee('<html lang="pt-BR"', false);

        // CTAs levam ao login; o rótulo do hero e do CTA final é "Entrar no aplicativo".
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'href="'.route('login').'"'));
        $response->assertSee('Entrar no aplicativo')->assertDontSee('Abrir o aplicativo');

        // Seções e âncoras do menu existem e apontam para ids presentes.
        foreach (['inicio', 'como-funciona', 'recursos', 'para-quem', 'seguranca', 'instalar', 'perguntas'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "seção #$id");
        }
        foreach (['#como-funciona', '#recursos', '#seguranca', '#perguntas'] as $anchor) {
            $this->assertStringContainsString('href="'.$anchor.'"', $html, "âncora $anchor");
        }

        // Conteúdo das seções pedidas.
        $response->assertSee('Entenda seus remédios.')->assertSee('Em linguagem simples.')
            ->assertSee('Baseado nas bulas oficiais da ANVISA')
            ->assertSee('Catálogo ANVISA')->assertSee('Interações DDInter')
            ->assertSee('Fotografe a receita')->assertSee('Confira os horários')->assertSee('Receba lembretes e tire dúvidas')
            ->assertSee('Bula humanizada')->assertSee('Remédios de hoje')->assertSee('Foto da receita')->assertSee('Interações e alergias')
            ->assertSee('Para você')->assertSee('Para quem cuida')->assertSee('Para profissionais')
            ->assertSee('Adicionar à Tela de Início')->assertSee('Instalar aplicativo')
            ->assertSee('Projeto acadêmico');
        $this->assertSame(6, substr_count($html, '<details class="group'), 'seis perguntas frequentes');

        // Hierarquia: um único h1.
        $this->assertSame(1, preg_match_all('/<h1\b/', $html));

        // Dados estruturados válidos.
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $matches));
        $jsonLd = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['SoftwareApplication', 'BULA-IA', url('/')], [$jsonLd['@type'], $jsonLd['name'], $jsonLd['url']]);
    }

    public function test_the_landing_keeps_the_product_guidelines_and_exposes_no_jargon_or_secrets(): void
    {
        config(['ai.text.providers.anthropic.api_key' => 'sk-ant-landing-secret', 'ai.vision.providers.anthropic.api_key' => 'sk-ant-landing-secret']);
        $response = $this->get('/')->assertOk();
        $html = $response->getContent();

        $response->assertSee('Limites desta ferramenta')
            ->assertSee(config('bulai.guidelines.disclaimer'))
            ->assertSee('não</strong> significa', false)
            ->assertSee('Consentimento explícito')
            ->assertDontSee('sk-ant-landing-secret')->assertDontSee(config('app.key'));

        foreach (['chunk', 'embedding', 'llm', 'prompt', 'token', 'rag', 'ocr'] as $jargon) {
            $this->assertDoesNotMatchRegularExpression('/\b'.$jargon.'s?\b/iu', strip_tags($html), "jargão “{$jargon}” exposto ao visitante");
        }
        // Nunca promete segurança nem conduta clínica.
        $this->assertDoesNotMatchRegularExpression('/combina[çc][ãa]o (é )?segura(?!\.)/iu', strip_tags($html));
    }

    public function test_the_academic_prototype_notice_is_prominent_outside_the_faq_and_links_to_the_registration(): void
    {
        $html = $this->get('/')->assertOk()
            ->assertSee('O BULA-IA é um protótipo de Trabalho de Conclusão de Curso')
            ->assertSee('Protótipo acadêmico (TCC).')
            ->getContent();

        // O aviso vem antes das seções de produto (logo após o hero), não só nas perguntas frequentes.
        $notice = strpos($html, 'id="projeto-academico"');
        $this->assertNotFalse($notice);
        $this->assertLessThan(strpos($html, 'id="como-funciona"'), $notice);
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'href="'.route('register').'"'));
    }

    public function test_authenticated_users_are_redirected_away_from_the_landing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirectToRoute($user->homeRouteName());
    }
}
