<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Calibration;

/**
 * Source of static token vectors during calibration. The default implementation
 * reads a pruned fastText `.vec`; tests inject an in-memory source so the
 * calibration loop is exercised without downloading 300-dim vectors.
 */
interface EmbeddingSource
{
    /**
     * Dimensionality of every vector this source yields.
     *
     * @return positive-int
     */
    public function dim(): int;

    /**
     * Vectors for the requested tokens that exist in the source. Tokens not
     * present are simply absent from the result; callers treat them as OOV.
     *
     * @param iterable<string> $tokens
     *
     * @return array<string, array<int, float>>
     */
    public function vectors(iterable $tokens): array;
}
