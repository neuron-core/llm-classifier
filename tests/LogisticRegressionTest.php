<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Tests;

use NeuronCore\Classifier\Head;
use NeuronCore\Classifier\LogisticRegression;
use PHPUnit\Framework\TestCase;

final class LogisticRegressionTest extends TestCase
{
    public function test_separates_a_linearly_separable_column(): void
    {
        // Easy prompts cluster at x<0, hard prompts at x>0 along axis 0.
        $samples = [[-5.0], [-3.0], [-1.0], [1.0], [3.0], [5.0]];
        $labels = [0, 0, 0, 1, 1, 1];

        $head = LogisticRegression::fit('cap', $samples, $labels, dim: 1);

        self::assertInstanceOf(Head::class, $head);
        // Hard signal: positive weight points hard-side up.
        self::assertGreaterThan(0.0, $head->weights()[0]);
        self::assertLessThan(0.5, $head->score([-4.0])); // easy
        self::assertGreaterThan(0.5, $head->score([4.0])); // hard
    }

    public function test_single_class_column_saturates(): void
    {
        $head = LogisticRegression::fit('cap', [[1.0], [2.0]], [1, 1], dim: 1);

        // All-hard -> positive saturation, predicts ~1.
        self::assertSame([0.0], $head->weights());
        self::assertGreaterThan(0.99, $head->score([0.0]));
    }

    public function test_all_easy_predicts_near_zero(): void
    {
        $head = LogisticRegression::fit('cap', [[1.0], [2.0]], [0, 0], dim: 1);

        self::assertLessThan(0.01, $head->score([0.0]));
    }
}
