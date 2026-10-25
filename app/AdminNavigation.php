<?php

namespace App;

/**
 * Áreas da administração, na ordem do menu.
 *
 * Fonte única do menu do shell (rótulo, ícone e dica ao passar o mouse) e do resumo de áreas
 * mostrado ao ADMIN na tela de primeiro acesso: mudar um item aqui atualiza os dois lugares.
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
        'library' => 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z',
        'beaker' => 'M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5',
        'users' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
    ];

    /**
     * Itens do menu: rota, rótulo, padrão de rota ativa, desenho do ícone e explicação (dica ao passar o mouse).
     *
     * @return list<array{route: string, label: string, pattern: string, icon: string, hint: string}>
     */
    public static function items(): array
    {
        return [
            self::item('admin.knowledge.dashboard', 'Visão geral', 'admin.knowledge.dashboard', 'home', 'Resumo do que está na base e do que precisa de atenção'),
            self::item('admin.knowledge.upload', 'Adicionar documentos', 'admin.knowledge.upload', 'plus', 'Enviar PDFs de bulas para a base'),
            self::item('admin.knowledge.batches.index', 'Importações', 'admin.knowledge.batches.*', 'inbox', 'Acompanhar cada envio e o que aconteceu com os arquivos'),
            self::item('admin.knowledge.pending.index', 'Pendências', 'admin.knowledge.pending.*', 'alert', 'Arquivos que precisam da sua decisão antes de entrar na base'),
            self::item('admin.knowledge.documents.index', 'Documentos', 'admin.*documents.*', 'document', 'Documentos já incorporados à base'),
            self::item('admin.knowledge.sources.index', 'Origens', 'admin.knowledge.sources.*', 'library', 'De onde vêm os documentos (ex.: ANVISA)'),
            self::item('admin.knowledge.catalog.index', 'Catálogo ANVISA', 'admin.knowledge.catalog.*', 'beaker', 'Lista oficial de medicamentos usada para validar bulas'),
            self::item('admin.users.index', 'Usuários', 'admin.users.*', 'users', 'Quem acessa o BULAI e com qual perfil'),
        ];
    }

    /**
     * @return array{route: string, label: string, pattern: string, icon: string, hint: string}
     */
    private static function item(string $route, string $label, string $pattern, string $icon, string $hint): array
    {
        return ['route' => $route, 'label' => $label, 'pattern' => $pattern, 'icon' => self::ICONS[$icon], 'hint' => $hint];
    }
}
