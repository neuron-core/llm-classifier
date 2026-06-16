<?php

declare(strict_types=1);

namespace NeuronCore\Classifier;

use function array_fill;
use function array_sum;
use function count;
use function exp;

/**
 * Pure-PHP full-batch gradient-descent logistic regression.
 *
 * Deterministic (no shuffling, fixed iterations) so calibration is reproducible
 * and unit-testable without an ML toolkit. Returns weights + bias baked into a
 * {@see Head}; the head's sigmoid output is read as P(hard).
 */
final class LogisticRegression
{
    /**
     * @param string            $capability head label
     * @param list<list<float>> $samples    n feature vectors, each length == $dim
     * @param list<int>         $labels     n binary labels (0|1), aligned with $samples
     * @param positive-int      $dim        feature dimensionality
     * @param float             $lr         learning rate
     * @param positive-int      $epochs     gradient-descent iterations
     * @param float             $l2         L2 regularization strength
     */
    public static function fit(
        string $capability,
        array $samples,
        array $labels,
        int $dim,
        float $lr = 0.5,
        int $epochs = 400,
        float $l2 = 1.0e-4,
    ): Head {
        $count = count($samples);

        // Degenerate single-class column: cannot separate — saturate the bias.
        $positives = (float) array_sum($labels);
        if ($positives === 0.0 || $positives === (float) $count) {
            return new Head(
                $capability,
                array_fill(0, $dim, 0.0),
                $positives === 0.0 ? -10.0 : 10.0,
            );
        }

        $weights = array_fill(0, $dim, 0.0);
        $bias = 0.0;

        for ($epoch = 0; $epoch < $epochs; ++$epoch) {
            $gradW = array_fill(0, $dim, 0.0);
            $gradB = 0.0;

            for ($i = 0; $i < $count; ++$i) {
                $sample = $samples[$i];
                $z = $bias;
                for ($d = 0; $d < $dim; ++$d) {
                    $z += $weights[$d] * $sample[$d];
                }

                $error = (1.0 / (1.0 + exp(-$z))) - (float) $labels[$i];
                for ($d = 0; $d < $dim; ++$d) {
                    $gradW[$d] += $error * $sample[$d];
                }
                $gradB += $error;
            }

            for ($d = 0; $d < $dim; ++$d) {
                $weights[$d] -= $lr * (($gradW[$d] / $count) + $l2 * $weights[$d]);
            }
            $bias -= $lr * ($gradB / $count);
        }

        return new Head($capability, $weights, $bias);
    }
}
