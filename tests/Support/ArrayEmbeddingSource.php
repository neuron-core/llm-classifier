<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Tests\Support;

use NeuronCore\Classifier\Calibration\EmbeddingSource;

/**
 * In-memory embedding source for tests: yields a fixed vector per token from a
 * map, letting the calibration loop run without a fastText file.
 */
final class ArrayEmbeddingSource implements EmbeddingSource
{
    /**
     * @param positive-int                     $dim
     * @param array<string, array<int, float>> $vectors
     */
    public function __construct(
        private readonly int $dim,
        private readonly array $vectors,
    ) {
    }

    public function dim(): int
    {
        return $this->dim;
    }

    public function vectors(iterable $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (isset($this->vectors[$token])) {
                $out[$token] = $this->vectors[$token];
            }
        }

        return $out;
    }
}
