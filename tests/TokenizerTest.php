<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Tests;

use NeuronCore\Classifier\Tokenizer;
use PHPUnit\Framework\TestCase;

final class TokenizerTest extends TestCase
{
    public function test_lowercases_and_segments_alphanumerics(): void
    {
        $tokens = (new Tokenizer())->tokenize('Hello, WORLD! 42 Caffè');

        self::assertSame(['hello', 'world', '42', 'caffè'], $tokens);
    }

    public function test_ignores_punctuation_and_whitespace(): void
    {
        $tokens = (new Tokenizer())->tokenize("  ... \n\t foo-bar_baz ...  ");

        self::assertSame(['foo', 'bar', 'baz'], $tokens);
    }

    public function test_empty_string_yields_no_tokens(): void
    {
        self::assertSame([], (new Tokenizer())->tokenize(''));
    }
}
