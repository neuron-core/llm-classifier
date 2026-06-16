<?php

declare(strict_types=1);

namespace NeuronCore\Classifier;

use NeuronCore\Classifier\Contract\DifficultyScorer;

use function array_values;
use function max;

/**
 * Runtime scorer: loads an {@see Model} once and classifies prompts with a
 * mean-pool + one sigmoid per capability head. No ML toolkit, no provider —
 * just PHP and ext-mbstring.
 *
 * Load once and reuse (ideal under Octane/RoadRunner/FrankenPHP long-lived workers).
 */
final class Classifier implements DifficultyScorer
{
    private readonly Tokenizer $tokenizer;
    private readonly Embeddings $embeddings;

    private function __construct(
        private readonly Model $artifact,
        ?Tokenizer             $tokenizer = null,
    ) {
        $this->tokenizer = $tokenizer ?? new Tokenizer($artifact->pattern());
        $this->embeddings = Embeddings::fromArtifact($this->artifact, $this->tokenizer);
    }

    public static function load(string $path): self
    {
        return self::fromArtifact(Model::fromFile($path));
    }

    public static function fromArtifact(Model $artifact, ?Tokenizer $tokenizer = null): self
    {
        return new self($artifact, $tokenizer);
    }

    /**
     * @return array<string, float> capability => P(hard) in [0,1]
     */
    public function classify(string $prompt): array
    {
        $vector = $this->embeddings->meanPool($prompt);

        $scores = [];
        foreach ($this->artifact->heads() as $head) {
            $scores[$head->capability()] = $head->score($vector);
        }

        return $scores;
    }

    /**
     * A single overall difficulty score: the MAX of the per-capability scores.
     *
     * Treat the prompt as hard as the hardest capability it touches. This is the
     * routing-correct aggregate — a simple mean would dilute a strong signal with
     * noise from capabilities the prompt isn't about. Compare this one value
     * against your thresholds; use {@see classify()} when you want per-capability
     * routing.
     *
     * @return float 0.0 (no heads) to 1.0
     */
    public function overall(string $prompt): float
    {
        $scores = $this->classify($prompt);

        return $scores === [] ? 0.0 : max(array_values($scores));
    }

    /**
     * Fraction of the prompt's tokens the model recognizes.
     *
     * Use as an out-of-domain guard before trusting {@see classify()}: a low
     * coverage means the prompt is far from the calibration data, so its
     * difficulty score is unreliable — route it to a capable model instead.
     *
     * @return float 0.0 (unrecognized / empty) to 1.0 (fully in vocabulary)
     */
    public function coverage(string $prompt): float
    {
        return $this->embeddings->coverage($prompt);
    }
}
