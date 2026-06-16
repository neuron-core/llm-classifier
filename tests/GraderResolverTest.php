<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Tests;

use NeuronCore\Classifier\Calibration\CorpusRow;
use NeuronCore\Classifier\Calibration\Grader\ExactMatchGrader;
use NeuronCore\Classifier\Calibration\GraderResolver;
use NeuronCore\Classifier\Calibration\Reference;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GraderResolverTest extends TestCase
{
    public function test_resolves_by_capability_by_default(): void
    {
        $exact = new ExactMatchGrader();
        $resolver = new GraderResolver(['math' => $exact]);

        self::assertSame($exact, $resolver->resolve(new CorpusRow('2+2', 'math', Reference::goldAnswer('4'))));
    }

    public function test_per_row_override_takes_precedence(): void
    {
        $exact = new ExactMatchGrader();
        $override = new ExactMatchGrader();
        $resolver = new GraderResolver(['math' => $exact, 'strict' => $override]);

        $resolved = $resolver->resolve(new CorpusRow('q', 'math', Reference::goldAnswer('a'), 'strict'));

        self::assertSame($override, $resolved);
    }

    public function test_throws_when_capability_unmapped(): void
    {
        $resolver = new GraderResolver([]);

        $this->expectException(RuntimeException::class);
        $resolver->resolve(new CorpusRow('q', 'unknown', Reference::goldAnswer('a')));
    }

    public function test_validate_rejects_incompatible_reference_type(): void
    {
        $resolver = new GraderResolver(['writing' => new ExactMatchGrader()]);

        $this->expectException(RuntimeException::class);
        $resolver->validate([
            new CorpusRow('write an essay', 'writing', Reference::rubric('must have 3 paragraphs')),
        ]);
    }
}
