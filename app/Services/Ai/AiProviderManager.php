<?php

namespace App\Services\Ai;

use App\Services\Ai\Fake\FakeDocumentClassificationProvider;
use App\Services\Ai\Fake\FakeEmbeddingProvider;
use App\Services\Ai\Fake\FakeTextGenerationProvider;
use App\Services\Ai\Fake\FakeVisionProvider;
use App\Services\Ai\OpenAiCompatible\OpenAiCompatibleClient;
use App\Services\Ai\OpenAiCompatible\OpenAiCompatibleDocumentClassificationProvider;
use App\Services\Ai\OpenAiCompatible\OpenAiCompatibleEmbeddingProvider;
use App\Services\Ai\OpenAiCompatible\OpenAiCompatibleTextGenerationProvider;
use App\Services\Ai\OpenAiCompatible\OpenAiCompatibleVisionProvider;
use Closure;

/**
 * Resolve o provider de cada capacidade (text, embedding, vision) a partir de config('ai.<capacidade>.default').
 * Novos drivers entram via extend() sem alterar quem consome os contratos.
 */
final class AiProviderManager
{
    public const TEXT = 'text';

    public const EMBEDDING = 'embedding';

    public const VISION = 'vision';

    public const CLASSIFIER = 'classifier';

    /** @var array<string, array<string, Closure(array<string, mixed>, string): object>> */
    private array $creators = [];

    /** @var array<string, object> */
    private array $resolved = [];

    public function __construct()
    {
        $client = fn (array $config, string $capability): OpenAiCompatibleClient => new OpenAiCompatibleClient('openai_compatible', $capability, $config);
        $this->extend(self::TEXT, 'openai_compatible', fn (array $config) => new OpenAiCompatibleTextGenerationProvider($client($config, self::TEXT)));
        $this->extend(self::EMBEDDING, 'openai_compatible', fn (array $config) => new OpenAiCompatibleEmbeddingProvider($client($config, self::EMBEDDING)));
        $this->extend(self::VISION, 'openai_compatible', fn (array $config) => new OpenAiCompatibleVisionProvider($client($config, self::VISION)));
        // llamafile expõe API compatível com OpenAI; o driver é um alias com configuração própria (ai.classifier.drivers.llamafile).
        $this->extend(self::CLASSIFIER, 'llamafile', fn (array $config) => new OpenAiCompatibleDocumentClassificationProvider(new OpenAiCompatibleClient('llamafile', self::CLASSIFIER, $config)));
        $this->extend(self::CLASSIFIER, 'openai_compatible', fn (array $config) => new OpenAiCompatibleDocumentClassificationProvider($client($config, self::CLASSIFIER)));
        $this->extend(self::CLASSIFIER, 'fake', fn () => new FakeDocumentClassificationProvider);
        $this->extend(self::TEXT, 'fake', fn () => new FakeTextGenerationProvider);
        $this->extend(self::EMBEDDING, 'fake', fn () => new FakeEmbeddingProvider);
        $this->extend(self::VISION, 'fake', fn () => new FakeVisionProvider);
    }

    /** @param Closure(array<string, mixed>, string): object $creator */
    public function extend(string $capability, string $driver, Closure $creator): void
    {
        $this->creators[$capability][$driver] = $creator;
        unset($this->resolved[$capability.':'.$driver]);
    }

    public function text(?string $driver = null): TextGenerationProvider
    {
        return $this->resolve(self::TEXT, $driver, TextGenerationProvider::class);
    }

    public function embedding(?string $driver = null): EmbeddingProvider
    {
        return $this->resolve(self::EMBEDDING, $driver, EmbeddingProvider::class);
    }

    public function vision(?string $driver = null): VisionProvider
    {
        return $this->resolve(self::VISION, $driver, VisionProvider::class);
    }

    public function classifier(?string $driver = null): DocumentClassificationProvider
    {
        return $this->resolve(self::CLASSIFIER, $driver, DocumentClassificationProvider::class);
    }

    public function isConfigured(string $capability): bool
    {
        $driver = config("ai.{$capability}.default");

        return filled($driver) && isset($this->creators[$capability][$driver]);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private function resolve(string $capability, ?string $driver, string $contract): object
    {
        $driver ??= config("ai.{$capability}.default");
        if (blank($driver) || ! isset($this->creators[$capability][$driver])) {
            throw new AiProviderException(AiProviderException::NOT_CONFIGURED);
        }

        $key = $capability.':'.$driver;
        $this->resolved[$key] ??= $this->creators[$capability][$driver](config("ai.{$capability}.drivers.{$driver}") ?? [], $capability);

        if (! $this->resolved[$key] instanceof $contract) {
            throw new AiProviderException(AiProviderException::NOT_CONFIGURED);
        }

        return $this->resolved[$key];
    }
}
