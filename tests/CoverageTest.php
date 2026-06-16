<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Tests;

use NeuronCore\Classifier\Embeddings;
use NeuronCore\Classifier\Tokenizer;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase
{
    public function test_full_coverage_when_all_tokens_known(): void
    {
        $embeddings = new Embeddings(2, new Tokenizer(), [
            'foo' => [1.0, 0.0],
            'bar' => [0.0, 1.0],
        ]);

        self::assertSame(1.0, $embeddings->coverage('foo bar'));
    }

    public function test_partial_coverage_counts_unknown_words(): void
    {
        $embeddings = new Embeddings(2, new Tokenizer(), [
            'foo' => [1.0, 0.0],
        ]);

        // 'foo' known, 'mystery' unknown -> 1 of 2.
        self::assertSame(0.5, $embeddings->coverage('foo mystery'));
    }

    public function test_zero_coverage_for_empty_or_all_unknown(): void
    {
        $embeddings = new Embeddings(2, new Tokenizer(), []);

        self::assertSame(0.0, $embeddings->coverage('completely unknown words'));
        self::assertSame(0.0, $embeddings->coverage(''));
    }
}
