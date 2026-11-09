<?php

namespace Tests\Unit\Services\Ai;

use App\Data\Ai\TokenUsage;
use App\Services\Ai\AiCostEstimator;
use PHPUnit\Framework\TestCase;

final class AiCostEstimatorTest extends TestCase
{
    public function test_anthropic_usage_counts_cache_in_the_input_and_prices_each_part(): void
    {
        $raw = ['input_tokens' => 200, 'cache_read_input_tokens' => 5000, 'cache_creation_input_tokens' => 1000, 'output_tokens' => 300, 'service_tier' => 'standard'];
        $usage = TokenUsage::fromAnthropic($raw);

        $this->assertSame([6200, 300, 5000, 1000, null], [$usage->inputTokens, $usage->outputTokens, $usage->cacheReadTokens, $usage->cacheWriteTokens, $usage->reasoningTokens]);
        $this->assertSame($raw, $usage->raw);

        $pricing = ['models' => ['claude-sonnet-5-5' => ['input' => 2.0, 'cache_write' => 2.5, 'cache_read' => 0.2, 'output' => 10.0]]];
        // 200 × 2 + 5.000 × 0,2 + 1.000 × 2,5 + 300 × 10 = 6.900 por milhão.
        $this->assertSame(0.0069, AiCostEstimator::estimate($pricing, 'claude-sonnet-5-5', $usage));
    }

    public function test_openai_usage_keeps_cached_and_reasoning_tokens(): void
    {
        $raw = ['prompt_tokens' => 1200, 'completion_tokens' => 400, 'total_tokens' => 1600, 'prompt_tokens_details' => ['cached_tokens' => 1000], 'completion_tokens_details' => ['reasoning_tokens' => 150]];
        $usage = TokenUsage::fromOpenAi($raw);

        $this->assertSame([1200, 400, 1000, null, 150], [$usage->inputTokens, $usage->outputTokens, $usage->cacheReadTokens, $usage->cacheWriteTokens, $usage->reasoningTokens]);
        // 200 × 1 + 1.000 × 0,1 + 400 × 4 = 1.900 por milhão.
        $this->assertSame(0.0019, AiCostEstimator::estimate(['models' => ['gpt-x' => ['input' => 1.0, 'cache_read' => 0.1, 'output' => 4.0]]], 'gpt-x', $usage));
    }

    public function test_cost_is_unknown_when_a_used_part_has_no_price(): void
    {
        $withCache = TokenUsage::fromAnthropic(['input_tokens' => 10, 'cache_read_input_tokens' => 90, 'output_tokens' => 5]);
        $inputAndOutputOnly = ['models' => ['m' => ['input' => 2.0, 'output' => 10.0]]];

        $this->assertNull(AiCostEstimator::estimate($inputAndOutputOnly, 'm', $withCache));
        $this->assertSame(0.00007, AiCostEstimator::estimate($inputAndOutputOnly, 'm', new TokenUsage(10, 5)));
        $this->assertNull(AiCostEstimator::estimate($inputAndOutputOnly, 'outro-modelo', new TokenUsage(10, 5)));
    }
}
