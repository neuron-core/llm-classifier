<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Contract;

/**
 * Runtime scoring contract consumed by any agentic framework's router.
 *
 * Returns one difficulty score in [0,1] per capability head. The caller
 * reduces the vector to a routing decision with its own thresholds.
 */
interface DifficultyScorer
{
    /**
     * @return array<string, float> map of capability name => P(hard) in [0,1]
     */
    public function classify(string $prompt): array;
}
