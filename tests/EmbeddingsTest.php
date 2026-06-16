<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Tests;

use NeuronCore\Classifier\Embeddings;
use NeuronCore\Classifier\Tokenizer;
use PHPUnit\Framework\TestCase;

final class EmbeddingsTest extends TestCase
{
    public function test_mean_pool_averages_in_vocab_tokens(): void
    {
        $embeddings = new Embeddings(2, new Tokenizer(), [
            'foo' => [2.0, 4.0],
            'bar' => [4.0, 8.0],
        ]);

        // (2,4) and (4,8) averaged -> (3,6)
        self::assertSame([3.0, 6.0], $embeddings->meanPool('foo bar'));
    }

    public function test_out_of_vocab_tokens_are_skipped(): void
    {
        $embeddings = new Embeddings(2, new Tokenizer(), [
            'foo' => [2.0, 4.0],
        ]);

        // 'baz' is OOV; only 'foo' counts -> (2,4)
        self::assertSame([2.0, 4.0], $embeddings->meanPool('foo baz'));
    }

    public function test_all_oov_prompt_yields_zero_vector(): void
    {
        $embeddings = new Embeddings(2, new Tokenizer(), []);

        self::assertSame([0.0, 0.0], $embeddings->meanPool('unknown tokens'));
    }
}
