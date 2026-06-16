<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Calibration;

/**
 * Intrinsic task data carried per corpus row: a gold answer, a rubric, or nothing.
 *
 * Reference is DATA; the grader that consumes it is POLICY (owned by the resolver).
 */
final class Reference
{
    public function __construct(
        private readonly ReferenceType $type,
        private readonly ?string $value = null,
    ) {
    }

    public static function goldAnswer(string $answer): self
    {
        return new self(ReferenceType::GoldAnswer, $answer);
    }

    public static function rubric(string $rubric): self
    {
        return new self(ReferenceType::Rubric, $rubric);
    }

    public static function none(): self
    {
        return new self(ReferenceType::None);
    }

    public function type(): ReferenceType
    {
        return $this->type;
    }

    public function value(): ?string
    {
        return $this->value;
    }
}
