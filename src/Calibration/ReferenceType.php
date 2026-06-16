<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Calibration;

/**
 * The kind of intrinsic reference a corpus row carries. Lives in the dataset
 * because it cannot be inferred; it drives grader selection.
 *
 * Defined in its own file so PSR-4 / the Composer classmap resolve it directly
 * (co-locating it in {@see Reference} left it unresolvable in isolation).
 */
enum ReferenceType: string
{
    /** A gold answer compared mechanically (exact match, normalizable). */
    case GoldAnswer = 'gold_answer';

    /** A rubric/criteria judged by an LLM against the response. */
    case Rubric = 'rubric';

    /** Nothing checkable (extension point; ungradeable by the MVP graders). */
    case None = 'none';
}
