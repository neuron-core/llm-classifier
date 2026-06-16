<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Tests;

use NeuronCore\Classifier\Model;
use NeuronCore\Classifier\Classifier;
use NeuronCore\Classifier\Head;
use PHPUnit\Framework\TestCase;

use function array_keys;

final class OverallTest extends TestCase
{
    public function test_overall_returns_the_max_per_capability_score(): void
    {
        // Two heads; on a zero-vector (all-OOV) prompt each score is sigmoid(bias).
        $artifact = new Model(
            language: 'en',
            dim: 2,
            pattern: '/[\p{L}\p{N}]+/u',
            vectors: [],
            heads: [
                new Head('easy-cap', [0.0, 0.0], -2.0), // sigmoid(-2) ≈ 0.119
                new Head('hard-cap', [0.0, 0.0], 2.0),  // sigmoid(2)  ≈ 0.881
            ],
        );

        $scorer = Classifier::fromArtifact($artifact);

        $scores = $scorer->classify('completely unknown prompt');
        self::assertSame(['easy-cap', 'hard-cap'], array_keys($scores));

        // overall == max, not the mean (mean ≈ 0.5, which would mis-route).
        self::assertSame($scores['hard-cap'], $scorer->overall('completely unknown prompt'));
        self::assertGreaterThan(0.8, $scorer->overall('completely unknown prompt'));
    }

    public function test_overall_is_zero_when_there_are_no_heads(): void
    {
        $artifact = new Model('en', 2, '/[\p{L}\p{N}]+/u', [], []);

        self::assertSame(0.0, Classifier::fromArtifact($artifact)->overall('anything'));
    }
}
