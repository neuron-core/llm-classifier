<?php

declare(strict_types=1);

namespace NeuronCore\Classifier;

use function count;
use function array_fill;

/**
 * Mean-pools a prompt's tokens over a static embedding table into one dense vector.
 *
 * Shared by calibration (over the source-derived table) and runtime (over the
 * artifact's pruned table) so the pooling logic is defined exactly once.
 */
final class Embeddings
{
    /**
     * @param positive-int                     $dim      embedding dimensionality
     * @param array<string, array<int, float>> $vectors  token => vector
     */
    public function __construct(
        private readonly int $dim,
        private readonly Tokenizer $tokenizer,
        private readonly array $vectors,
    ) {
    }

    public static function fromArtifact(Model $artifact, Tokenizer $tokenizer): self
    {
        return new self($artifact->dim(), $tokenizer, $artifact->vectors());
    }

    public function dim(): int
    {
        return $this->dim;
    }

    /**
     * Mean of the vectors of in-vocabulary tokens. Out-of-vocabulary tokens are
     * skipped; a prompt with no known tokens yields the zero vector.
     *
     * @return array<int, float>
     */
    public function meanPool(string $text): array
    {
        $sum = array_fill(0, $this->dim, 0.0);
        $known = 0;

        foreach ($this->tokenizer->tokenize($text) as $token) {
            $vector = $this->vectors[$token] ?? null;
            if ($vector === null) {
                continue;
            }

            for ($i = 0, $n = count($vector); $i < $n && $i < $this->dim; ++$i) {
                $sum[$i] += $vector[$i];
            }
            ++$known;
        }

        if ($known === 0) {
            return $sum;
        }

        for ($i = 0; $i < $this->dim; ++$i) {
            $sum[$i] /= $known;
        }

        return $sum;
    }

    /**
     * Fraction of the prompt's tokens that exist in the embedding table.
     *
     * A cheap out-of-domain signal: a prompt about something the classifier was
     * never calibrated on tends to contain many words the table doesn't hold, so
     * its coverage is low. Route low-coverage prompts to a capable model rather
     * than trusting their difficulty score.
     *
     * @return float 0.0 (no recognized tokens / empty prompt) to 1.0 (all known)
     */
    public function coverage(string $text): float
    {
        $tokens = $this->tokenizer->tokenize($text);
        $total = count($tokens);
        if ($total === 0) {
            return 0.0;
        }

        $known = 0;
        foreach ($tokens as $token) {
            if (isset($this->vectors[$token])) {
                ++$known;
            }
        }

        return $known / $total;
    }
}
