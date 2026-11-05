<?php

namespace Tests\Unit\Evaluation;

use App\Services\Evaluation\Metrics;
use App\Services\Evaluation\Sampling;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    public function test_confusion_matrix_counts_every_gold_predicted_pair_and_ignores_unknown_labels(): void
    {
        $matrix = Metrics::confusionMatrix([
            ['gold' => 'major', 'predicted' => 'major'], ['gold' => 'major', 'predicted' => 'minor'],
            ['gold' => 'minor', 'predicted' => 'minor'], ['gold' => 'minor', 'predicted' => 'inexistente'],
        ], ['major', 'minor']);

        $this->assertSame(['major' => ['major' => 1, 'minor' => 1], 'minor' => ['major' => 0, 'minor' => 1]], $matrix);
    }

    public function test_precision_recall_f1_and_undefined_cases(): void
    {
        $this->assertEqualsWithDelta(0.8, Metrics::prf(8, 2, 2)['precision'], 1e-9);
        $this->assertEqualsWithDelta(0.8, Metrics::prf(8, 2, 2)['f1'], 1e-9);
        $this->assertSame(['precision' => null, 'recall' => null, 'f1' => null], Metrics::prf(0, 0, 0));
        $this->assertSame(0.0, Metrics::prf(0, 3, 3)['f1'] ?? 0.0);
        $this->assertNull(Metrics::prf(0, 0, 4)['precision']);
    }

    public function test_retrieval_metrics(): void
    {
        $ranked = ['a', 'b', 'c', 'd'];
        $relevant = ['b', 'd', 'x'];

        $this->assertEqualsWithDelta(1 / 3, Metrics::precisionAtK($ranked, $relevant, 3), 1e-9);
        $this->assertEqualsWithDelta(1 / 3, Metrics::recallAtK($ranked, $relevant, 3), 1e-9);
        $this->assertEqualsWithDelta(2 / 3, Metrics::recallAtK($ranked, $relevant, 4), 1e-9);
        $this->assertSame(0.5, Metrics::reciprocalRank($ranked, $relevant));
        $this->assertSame(0.0, Metrics::reciprocalRank(['z'], $relevant));
        $this->assertNull(Metrics::recallAtK($ranked, [], 3));
        $this->assertSame(0.0, Metrics::precisionAtK([], $relevant, 0));
    }

    public function test_mean_ignores_nulls_and_rounding_keeps_null(): void
    {
        $this->assertSame(0.5, Metrics::mean([0.25, null, 0.75]));
        $this->assertNull(Metrics::mean([null]));
        $this->assertNull(Metrics::round(null));
        $this->assertSame(0.3333, Metrics::round(1 / 3));
    }

    public function test_seeded_sampling_is_deterministic_and_seed_dependent(): void
    {
        $items = range(1, 50);

        $this->assertSame(Sampling::seeded($items, 10, 42), Sampling::seeded($items, 10, 42));
        $this->assertNotSame(Sampling::seeded($items, 10, 42), Sampling::seeded($items, 10, 7));
        $this->assertCount(10, array_unique(Sampling::seeded($items, 10, 42)));
        $this->assertSame($items, Sampling::seeded($items, null, 42));
        $this->assertSame($items, Sampling::seeded($items, 99, 42));
    }
}
