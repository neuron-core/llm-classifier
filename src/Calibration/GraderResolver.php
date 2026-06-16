<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Calibration;

use NeuronCore\Classifier\Contract\Grader;
use RuntimeException;

use function array_key_exists;
use function sprintf;

/**
 * The single seam for grader selection.
 *
 * Maps each capability to a Grader, with an optional per-row override (a key in
 * the same registry). Keeping selection in policy — not in the data — lets the
 * same corpus be re-graded under a stricter checker with no data edits.
 */
final class GraderResolver
{
    /**
     * @param array<string, Grader> $graders keys are capability names and/or
     *                                       override names registered for per-row use
     */
    public function __construct(
        private readonly array $graders,
    ) {
    }

    /**
     * @throws RuntimeException if neither the per-row override nor the capability is registered
     */
    public function resolve(CorpusRow $row): Grader
    {
        if ($row->grader() !== null && array_key_exists($row->grader(), $this->graders)) {
            return $this->graders[$row->grader()];
        }

        if (array_key_exists($row->capability(), $this->graders)) {
            return $this->graders[$row->capability()];
        }

        throw new RuntimeException(
            sprintf('No grader registered for capability "%s" (or override "%s").', $row->capability(), $row->grader() ?? ''),
        );
    }

    /**
     * Pre-flight: every row that the panel will grade must resolve to a grader
     * that supports its reference type. Rows carrying a precomputed difficulty are
     * labelled directly and never graded, so they are exempt.
     *
     * @throws RuntimeException on the first incompatible row, before any provider is billed.
     */
    public function validate(iterable $rows): void
    {
        foreach ($rows as $row) {
            if ($row->difficulty() !== null) {
                continue;
            }

            $grader = $this->resolve($row);
            if (!$grader->supports($row->reference()->type())) {
                throw new RuntimeException(
                    sprintf(
                        'Grader "%s" does not support reference "%s" for capability "%s".',
                        $row->grader() ?? $row->capability(),
                        $row->reference()->type()->value,
                        $row->capability(),
                    ),
                );
            }
        }
    }
}
