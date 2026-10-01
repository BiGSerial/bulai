<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\KnowledgeSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KnowledgeSourceService
{
    /** @param array{name: string, collection: string, type: string, url?: ?string, country?: ?string, description?: ?string} $attributes */
    public function create(array $attributes): KnowledgeSource
    {
        return $this->persist(new KnowledgeSource, $attributes);
    }

    /** @param array{name: string, collection: string, type: string, url?: ?string, country?: ?string, description?: ?string} $attributes */
    public function update(KnowledgeSource $source, array $attributes): KnowledgeSource
    {
        return $this->persist($source, $attributes);
    }

    public function setActive(KnowledgeSource $source, bool $active): KnowledgeSource
    {
        $source->forceFill(['active' => $active])->save();

        return $source->refresh();
    }

    /** @param array{name: string, organization?: ?string, type?: ?string, url?: ?string, description?: ?string, priority?: ?int} $attributes */
    public function findOrCreateImported(array $attributes): KnowledgeSource
    {
        $collection = Str::squish($attributes['organization'] ?? 'Importação estruturada');
        $source = KnowledgeSource::query()
            ->where('normalized_name', $this->normalize($attributes['name']))
            ->where('normalized_collection', $this->normalize($collection))
            ->first();

        if ($source !== null) {
            return $source;
        }

        $source = $this->create([
            'name' => $attributes['name'],
            'collection' => $collection,
            'type' => $attributes['type'] ?? KnowledgeSource::TYPE_OTHER,
            'url' => $attributes['url'] ?? null,
            'description' => $attributes['description'] ?? null,
        ]);
        $source->forceFill([
            'organization' => $attributes['organization'] ?? null,
            'priority' => $attributes['priority'] ?? null,
        ])->save();

        return $source->refresh();
    }

    /** @param array{name: string, collection: string, type: string, url?: ?string, country?: ?string, description?: ?string} $attributes */
    private function persist(KnowledgeSource $source, array $attributes): KnowledgeSource
    {
        $name = Str::squish($attributes['name']);
        $collection = Str::squish($attributes['collection']);
        $normalizedName = $this->normalize($name);
        $normalizedCollection = $this->normalize($collection);

        $duplicate = KnowledgeSource::query()
            ->where('normalized_name', $normalizedName)
            ->where('normalized_collection', $normalizedCollection)
            ->when($source->exists, fn ($query) => $query->whereKeyNot($source->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'Esta origem e base/coleção já estão cadastradas.',
            ]);
        }

        try {
            $source->fill([
                'name' => $name,
                'collection' => $collection,
                'normalized_name' => $normalizedName,
                'normalized_collection' => $normalizedCollection,
                'type' => $attributes['type'],
                'url' => $this->nullableText($attributes['url'] ?? null),
                'country' => $this->nullableText($attributes['country'] ?? null),
                'description' => $this->nullableText($attributes['description'] ?? null),
                'active' => $source->exists ? $source->active : true,
            ])->save();
        } catch (QueryException $exception) {
            if (in_array((string) ($exception->errorInfo[0] ?? ''), ['23505', '19'], true)) {
                throw ValidationException::withMessages([
                    'name' => 'Esta origem e base/coleção já estão cadastradas.',
                ]);
            }

            throw $exception;
        }

        return $source->refresh();
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::squish($value));
    }

    private function nullableText(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
