<?php

namespace App\Livewire\Admin;

use App\AdminHelp;
use App\AdminNavigation;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\User;
use App\Services\Interactions\MedicationSearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Busca global do cabeçalho da administração (Ctrl/⌘+K): telas, documentos, catálogo ANVISA e usuários,
 * no máximo cinco por grupo. Só administradores; limite de taxa por usuário.
 */
class GlobalSearch extends Component
{
    public const PER_GROUP = 5;

    public const RATE_LIMIT_PER_MINUTE = 120;

    public string $q = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin() === true, 403);
    }

    /**
     * @return list<array{label: string, items: list<array{title: string, support: string|null, href: string}>}>
     */
    public function results(MedicationSearchService $catalog): array
    {
        $term = trim($this->q);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $key = 'admin-search:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT_PER_MINUTE)) {
            return [['label' => 'Aguarde', 'items' => [['title' => 'Muitas buscas em um minuto. Tente de novo em instantes.', 'support' => null, 'href' => route('admin.knowledge.dashboard')]]]];
        }
        RateLimiter::hit($key, 60);

        $groups = [
            ['label' => 'Telas', 'items' => $this->pages($term)],
            ['label' => 'Documentos', 'items' => $this->documents($term)],
            ['label' => 'Catálogo ANVISA', 'items' => $this->medicines($term, $catalog)],
            ['label' => 'Usuários', 'items' => $this->users($term)],
        ];

        return array_values(array_filter($groups, fn (array $group): bool => $group['items'] !== []));
    }

    /** @return list<array{title: string, support: string|null, href: string}> */
    private function pages(string $term): array
    {
        $needle = Str::of($term)->ascii()->lower()->toString();
        $items = [];
        foreach (AdminNavigation::groups() as $group) {
            foreach ($group['items'] as $item) {
                $help = AdminHelp::for($item['route']);
                $haystack = Str::of($item['label'].' '.$item['description'].' '.$item['hint'].' '.($help['purpose'] ?? ''))->ascii()->lower()->toString();
                if (str_contains($haystack, $needle)) {
                    $items[] = ['title' => $item['label'], 'support' => $group['label'].' · '.$item['description'], 'href' => route($item['route'])];
                }
            }
        }

        return array_slice($items, 0, self::PER_GROUP);
    }

    /** @return list<array{title: string, support: string|null, href: string}> */
    private function documents(string $term): array
    {
        return KnowledgeDocument::query()->with('source:id,name')
            ->where(fn ($query) => $query->where('title', 'ilike', '%'.$term.'%')->orWhere('external_key', 'ilike', '%'.$term.'%'))
            ->orderBy('title')->limit(self::PER_GROUP)->get()
            ->map(fn (KnowledgeDocument $document): array => [
                'title' => (string) $document->title,
                'support' => $document->documentTypeLabel().' · '.($document->source?->name ?? ''),
                'href' => route('admin.knowledge.documents.show', $document),
            ])->all();
    }

    /** @return list<array{title: string, support: string|null, href: string}> */
    private function medicines(string $term, MedicationSearchService $catalog): array
    {
        return array_map(fn (array $row): array => [
            'title' => $row['name'].($row['fuzzy'] ? ' (parecido com "'.$row['matched'].'")' : ''),
            'support' => $row['ingredients'].($row['holder'] !== '' ? ' · '.$row['holder'] : ''),
            'href' => route('admin.knowledge.catalog.index'),
        ], $catalog->search($term, self::PER_GROUP));
    }

    /** @return list<array{title: string, support: string|null, href: string}> */
    private function users(string $term): array
    {
        return User::query()
            ->where(fn ($query) => $query->where('name', 'ilike', '%'.$term.'%')->orWhere('email', 'ilike', '%'.$term.'%'))
            ->orderBy('name')->limit(self::PER_GROUP)->get()
            ->map(fn (User $user): array => [
                'title' => (string) $user->name,
                'support' => $user->email.' · '.($user->isAdmin() ? 'Administrador' : 'Usuário').($user->active ? '' : ' · inativo'),
                'href' => route('admin.users.show', $user),
            ])->all();
    }

    public function render(MedicationSearchService $catalog): View
    {
        return view('livewire.shared.global-search', ['groups' => $this->results($catalog)]);
    }
}
