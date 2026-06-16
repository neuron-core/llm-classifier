<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Tests;

use NeuronCore\Classifier\Calibration\Grader\ExactMatchGrader;
use NeuronCore\Classifier\Calibration\Reference;
use NeuronCore\Classifier\Calibration\ReferenceType;
use PHPUnit\Framework\TestCase;

final class ExactMatchGraderTest extends TestCase
{
    public function test_matches_after_normalization(): void
    {
        $grader = new ExactMatchGrader();

        self::assertTrue($grader->isCorrect(
            'q',
            '  The   Answer  ',
            Reference::goldAnswer('the answer'),
        ));
    }

    public function test_rejects_different_text(): void
    {
        $grader = new ExactMatchGrader();

        self::assertFalse($grader->isCorrect('q', 'wrong', Reference::goldAnswer('right')));
    }

    public function test_supports_only_gold_answer(): void
    {
        $grader = new ExactMatchGrader();

        self::assertTrue($grader->supports(ReferenceType::GoldAnswer));
        self::assertFalse($grader->supports(ReferenceType::Rubric));
        self::assertFalse($grader->supports(ReferenceType::None));
    }
}
