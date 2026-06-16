<?php

declare(strict_types=1);

namespace NeuronCore\Classifier;

use function mb_strtolower;
use function preg_match_all;

/**
 * Pure-PHP tokenizer used by BOTH calibration and runtime.
 *
 * Defining it once prevents train/serve skew: the exact same normalization and
 * segmentation that produced the embedding lookup keys during calibration is
 * applied at scoring time.
 */
final class Tokenizer
{
    /**
     * @param non-empty-string $pattern Unicode segmentation pattern. The default
     *                                  keeps contiguous letter/number runs as tokens.
     */
    public function __construct(
        private readonly string $pattern = '/[\p{L}\p{N}]+/u',
    ) {
    }

    /**
     * @return list<string> lowercase tokens, order preserved
     */
    public function tokenize(string $text): array
    {
        $matches = [];
        preg_match_all($this->pattern, mb_strtolower($text), $matches);

        /** @var list<string> $tokens */
        $tokens = $matches[0];

        return $tokens;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }
}
