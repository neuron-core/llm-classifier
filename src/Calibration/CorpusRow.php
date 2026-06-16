<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Calibration;

/**
 * One calibration example: a prompt, the capability it exercises, its intrinsic
 * reference, an optional per-row grader override (a key registered in the
 * {@see GraderResolver}), and an optional precomputed difficulty.
 */
final class CorpusRow
{
    public function __construct(
        private readonly string $prompt,
        private readonly string $capability,
        private readonly Reference $reference,
        private readonly ?string $grader = null,
        private readonly ?float $difficulty = null,
    ) {
    }

    public function prompt(): string
    {
        return $this->prompt;
    }

    public function capability(): string
    {
        return $this->capability;
    }

    public function reference(): Reference
    {
        return $this->reference;
    }

    /**
     * Per-row grader override key (registered in the resolver), or null to fall
     * back to the row's capability.
     */
    public function grader(): ?string
    {
        return $this->grader;
    }

    /**
     * Precomputed difficulty in 0..1 (higher = harder), or null when the label
     * must be derived from the panel. When set, the row is labelled directly from
     * this value and neither the panel nor a grader is consulted — the
     * cold-start path for routing benchmarks such as RouterBench.
     */
    public function difficulty(): ?float
    {
        return $this->difficulty;
    }
}
