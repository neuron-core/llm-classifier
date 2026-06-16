<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Calibration\Grader;

use NeuronCore\Classifier\Calibration\Reference;
use NeuronCore\Classifier\Calibration\ReferenceType;
use NeuronCore\Classifier\Contract\Grader;

use function mb_strtolower;
use function preg_replace;
use function trim;

/**
 * Default mechanical grader: normalizes both sides and compares for equality.
 *
 * Good for math/QA where the gold answer is exact and normalizable.
 */
final class ExactMatchGrader implements Grader
{
    public function supports(ReferenceType $type): bool
    {
        return $type === ReferenceType::GoldAnswer;
    }

    public function isCorrect(string $prompt, string $response, Reference $reference): bool
    {
        $expected = $reference->value();

        return $expected !== null
            && self::normalize($response) === self::normalize($expected);
    }

    protected static function normalize(string $value): string
    {
        $trimmed = trim($value);
        $collapsed = preg_replace('/\s+/u', ' ', $trimmed);

        return mb_strtolower($collapsed ?? $trimmed);
    }
}
