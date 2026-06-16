<?php

declare(strict_types=1);

namespace NeuronCore\Classifier;

use function count;
use function exp;
use function min;

/**
 * One binary logistic regression head: a weight vector + bias for a capability.
 *
 * A head's score is read as P(hard), the difficulty score for its capability.
 */
final class Head
{
    /**
     * @param string                       $capability head label / capability name
     * @param array<int, float>            $weights    length == embedding dim
     */
    public function __construct(
        private readonly string $capability,
        private readonly array $weights,
        private readonly float $bias,
    ) {
    }

    public function capability(): string
    {
        return $this->capability;
    }

    /**
     * @return array<int, float>
     */
    public function weights(): array
    {
        return $this->weights;
    }

    public function bias(): float
    {
        return $this->bias;
    }

    /**
     * P(hard) for a mean-pooled feature vector of matching dimensionality.
     *
     * @param array<int, float> $vector length == embedding dim
     */
    public function score(array $vector): float
    {
        $z = $this->bias;
        $count = min(count($vector), count($this->weights));
        for ($i = 0; $i < $count; ++$i) {
            $z += $this->weights[$i] * $vector[$i];
        }

        return 1.0 / (1.0 + exp(-$z));
    }
}
