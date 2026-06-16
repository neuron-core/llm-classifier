<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Contract;

use NeuronCore\Classifier\Calibration\Reference;
use NeuronCore\Classifier\Calibration\ReferenceType;

/**
 * Decides whether a single panel response is correct against a row's reference.
 *
 * Kept as a one-method contract so exact-match, test runners and the LLM judge
 * all interchange behind the {@see \NeuronCore\Classifier\Calibration\GraderResolver}.
 */
interface Grader
{
    /**
     * Reference kinds this grader can evaluate. Used by pre-flight validation to
     * reject incompatible row/grader pairings before any provider is billed.
     */
    public function supports(ReferenceType $type): bool;

    /**
     * @param Reference $reference the row's intrinsic task data (gold answer, rubric, ...)
     */
    public function isCorrect(string $prompt, string $response, Reference $reference): bool;
}
