<?php

namespace App;

use Illuminate\Support\Str;

/**
 * Áreas da administração, em grupos, na ordem do menu lateral.
 *
 * Fonte única do menu do shell (rótulo, ícone e dica) e do resumo de áreas mostrado ao ADMIN na tela de
 * primeiro acesso: mudar um item aqui atualiza os dois lugares.
 */
final class AdminNavigation
{
    /**
     * Desenho de cada ícone (atributo "d" do path SVG, 24×24, contorno).
     */
    private const ICONS = [
        'home' => 'm2.25 12 8.954-8.955a1.126 1.126 0 0 1 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
        'plus' => 'M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'document' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
        'inbox' => 'M9 8.25H7.5a2.25 2.25 0 0 0-2.25 2.25v9a2.25 2.25 0 0 0 2.25 2.25h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25H15M9 12l3 3m0 0 3-3m-3 3V2.25',
        'alert' => 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z',
        'flag' => 'M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5',
        'library' => 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z',
        'beaker' => 'M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5',
        'link' => 'M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244',
        'queue' => 'M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z',
        'chart' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z',
        'report' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M9 16.5v.75m3-3v3M15 12v4.5m2.25-5.25v.008M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
        'help' => 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z',
        'search' => 'm21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z',
        'users' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
    ];

    /**
     * Grupos do menu lateral, cada um com seus itens (rota, rótulo, padrão de rota ativa, ícone e dica).
     *
     * `hint` é a frase completa (dica e ajuda); `description` é a versão curta mostrada como segunda linha do menu.
     *
     * @return list<array{label: string, items: list<array{route: string, label: string, pattern: string, icon: string, hint: string, description: string}>}>
     */
    public static function groups(): array
    {
        return [
            ['label' => 'Base de conhecimento', 'items' => [
                self::item('admin.knowledge.dashboard', 'Visão geral', 'admin.knowledge.dashboard', 'home', 'Resumo de tudo o que está na base, da operação e do uso do aplicativo', 'O que pede decisão hoje'),
                self::item('admin.knowledge.upload', 'Adicionar documentos', 'admin.knowledge.upload', 'plus', 'Enviar PDFs de bulas para a base', 'Enviar PDFs de bulas'),
                self::item('admin.knowledge.batches.index', 'Importações', 'admin.knowledge.batches.*', 'inbox', 'Acompanhar cada envio e o que aconteceu com os arquivos', 'Histórico dos envios'),
                self::item('admin.knowledge.pending.index', 'Pendências', 'admin.knowledge.pending.*', 'alert', 'Arquivos que precisam da sua decisão antes de entrar na base', 'Esperam a sua decisão'),
                self::item('admin.knowledge.unmatched.index', 'Remédios não encontrados', 'admin.knowledge.unmatched.*', 'flag', 'Remédios que pacientes cadastraram e o catálogo não reconheceu: ligar ao medicamento certo', 'Ligar ao catálogo ANVISA'),
                self::item('admin.knowledge.unanswered.index', 'Perguntas sem resposta', 'admin.knowledge.unanswered.*', 'alert', 'Perguntas que ficaram sem resposta mesmo com a bula inteira lida: adquirir a fonte que falta', 'Fontes que faltam'),
                self::item('admin.knowledge.documents.index', 'Documentos', 'admin.*documents.*', 'document', 'Documentos já incorporados à base', 'Bulas prontas na base'),
                self::item('admin.knowledge.sources.index', 'Origens', 'admin.knowledge.sources.*', 'library', 'De onde vêm os documentos (ex.: ANVISA)', 'Procedência dos documentos'),
                self::item('admin.knowledge.catalog.index', 'Catálogo ANVISA', 'admin.knowledge.catalog.*', 'beaker', 'Lista oficial de medicamentos usada para validar bulas', 'Lista oficial de medicamentos'),
                self::item('admin.knowledge.interactions.index', 'Interações', 'admin.knowledge.interactions.*', 'link', 'Interações medicamentosas (DDInter) usadas como informação complementar', 'Base DDInter de interações'),
            ]],
            ['label' => 'Operação', 'items' => [
                self::item('admin.knowledge.queue', 'Fila e serviços', 'admin.knowledge.queue', 'queue', 'Fila de análises, OCR e IA local: ver o que está rodando e reiniciar se travar', 'O que está rodando agora'),
            ]],
            ['label' => 'Análises', 'items' => [
                self::item('admin.analytics.index', 'Analytics', 'admin.analytics.*', 'chart', 'Métricas das respostas da IA, evidências, custos e auditoria', 'Qualidade, custos e auditoria'),
                self::item('admin.reports.index', 'Relatórios', 'admin.reports.*', 'report', 'Gerar e baixar relatórios da base, do uso, dos custos e da auditoria', 'Gerar e baixar arquivos'),
                self::item('admin.api-tokens.index', 'Acesso à API', 'admin.api-tokens.*', 'link', 'Tokens revogáveis para ler estatísticas e avaliar as respostas pela API', 'Tokens da API'),
            ]],
            ['label' => 'Pessoas', 'items' => [
                self::item('admin.users.index', 'Usuários', 'admin.users.*', 'users', 'Quem acessa o BULAI e com qual perfil', 'Acessos e perfis'),
            ]],
        ];
    }

    /**
     * Todos os itens, sem os grupos (primeiro acesso e compatibilidade).
     *
     * @return list<array{route: string, label: string, pattern: string, icon: string, hint: string, description: string}>
     */
    public static function items(): array
    {
        return array_merge(...array_column(self::groups(), 'items'));
    }

    /**
     * Grupo e item do menu que cobrem uma rota (trilha "Grupo › Tela" do shell); null fora do menu.
     *
     * @return array{group: string, item: array{route: string, label: string, pattern: string, icon: string, hint: string, description: string}}|null
     */
    public static function find(?string $routeName): ?array
    {
        if ($routeName === null) {
            return null;
        }
        foreach (self::groups() as $group) {
            foreach ($group['items'] as $item) {
                if (Str::is($item['pattern'], $routeName)) {
                    return ['group' => $group['label'], 'item' => $item];
                }
            }
        }

        return null;
    }

    /** Desenho de um ícone pelo nome (para componentes que precisam de ícones fora do menu). */
    public static function icon(string $name): string
    {
        return self::ICONS[$name];
    }

    /**
     * @return array{route: string, label: string, pattern: string, icon: string, hint: string, description: string}
     */
    private static function item(string $route, string $label, string $pattern, string $icon, string $hint, string $description): array
    {
        return ['route' => $route, 'label' => $label, 'pattern' => $pattern, 'icon' => self::ICONS[$icon], 'hint' => $hint, 'description' => $description];
    }
}
