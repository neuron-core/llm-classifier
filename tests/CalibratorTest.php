<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Tests;

use NeuronCore\Classifier\Calibration\Calibrator;
use NeuronCore\Classifier\Calibration\CorpusRow;
use NeuronCore\Classifier\Calibration\Grader\ExactMatchGrader;
use NeuronCore\Classifier\Calibration\Grader\LlmJudgeGrader;
use NeuronCore\Classifier\Calibration\GraderResolver;
use NeuronCore\Classifier\Calibration\Reference;
use NeuronCore\Classifier\Calibration\SeedCorpus;
use NeuronCore\Classifier\Classifier;
use NeuronCore\Classifier\Tests\Support\ArrayEmbeddingSource;
use NeuronCore\Classifier\Tests\Support\FakeProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CalibratorTest extends TestCase
{
    public function test_fits_heads_and_classifier_ranks_hard_above_easy(): void
    {
        // Strong provider always answers correctly; weak fails only on the hard row.
        $strong = new FakeProvider('strong', static fn (string $p): string => $p === 'hard' ? '7' : '4');
        $weak = new FakeProvider('weak', static fn (string $p): string => $p === 'hard' ? '0' : '4');

        // easy row -> both pass -> difficulty 0; hard row -> 1/2 pass -> difficulty 0.5.
        $corpus = SeedCorpus::fromArray([
            new CorpusRow('easy', 'qa', Reference::goldAnswer('4')),
            new CorpusRow('hard', 'qa', Reference::goldAnswer('7')),
        ]);

        // Single-token prompts map 1:1 to vectors along axis 0.
        $source = new ArrayEmbeddingSource(2, [
            'easy' => [-1.0, 0.0],
            'hard' => [1.0, 0.0],
        ]);

        $artifact = (new Calibrator(
            panel: [$strong, $weak],
            corpus: $corpus,
            graders: new GraderResolver(['qa' => new ExactMatchGrader()]),
            language: 'it',
            fasttext: '',
            embeddingSource: $source,
        ))->run();

        $scorer = Classifier::fromArtifact($artifact);
        $scores = $scorer->classify('hard');

        self::assertArrayHasKey('qa', $scores);
        self::assertGreaterThan(0.5, $scores['qa'], 'hard prompt should score as hard');
        self::assertLessThan(0.5, $scorer->classify('easy')['qa'], 'easy prompt should score as easy');
        self::assertLessThanOrEqual(1.0, $scores['qa']);
        self::assertGreaterThanOrEqual(0.0, $scores['qa']);
    }

    public function test_exercises_judge_and_per_row_override(): void
    {
        // Judge accepts any writing response.
        $judge = new FakeProvider('judge', static fn (string $p): string => 'YES');
        $strong = new FakeProvider('strong', static fn (string $p): string => 'a fine essay');

        $corpus = SeedCorpus::fromArray([
            new CorpusRow('write a sonnet', 'writing', Reference::rubric('14 lines, iambic')),
        ]);

        $artifact = (new Calibrator(
            panel: [$strong],
            corpus: $corpus,
            graders: new GraderResolver([
                'writing' => new LlmJudgeGrader($judge),
            ]),
            language: 'it',
            fasttext: '',
            embeddingSource: new ArrayEmbeddingSource(2, ['write' => [1.0, 0.0], 'a' => [0.0, 1.0]]),
        ))->run();

        // Single row, judged correct -> difficulty 0 -> degenerate easy head.
        $scorer = Classifier::fromArtifact($artifact);
        self::assertArrayHasKey('writing', $scorer->classify('write a sonnet'));
    }

    public function test_preflight_blocks_incompatible_grader(): void
    {
        $corpus = SeedCorpus::fromArray([
            new CorpusRow('explain gravity', 'conceptual', Reference::rubric('mentions mass')),
        ]);

        $this->expectException(RuntimeException::class);
        (new Calibrator(
            panel: [new FakeProvider('p', static fn (string $p): string => 'x')],
            corpus: $corpus,
            graders: new GraderResolver(['conceptual' => new ExactMatchGrader()]),
            language: 'it',
            fasttext: '',
            embeddingSource: new ArrayEmbeddingSource(2, []),
        ))->run();
    }

    public function test_calibrates_from_precomputed_labels_without_a_panel(): void
    {
        // RouterBench-style cold start: no providers, no graders. Each row carries
        // its own difficulty, so calibration makes zero API calls.
        $corpus = SeedCorpus::fromArray([
            new CorpusRow('easy', 'qa', Reference::none(), null, 0.0),
            new CorpusRow('hard', 'qa', Reference::none(), null, 1.0),
        ]);

        $source = new ArrayEmbeddingSource(2, [
            'easy' => [-1.0, 0.0],
            'hard' => [1.0, 0.0],
        ]);

        $artifact = (new Calibrator(
            panel: [],
            corpus: $corpus,
            graders: new GraderResolver([]),
            language: 'it',
            fasttext: '',
            embeddingSource: $source,
        ))->run();

        $scorer = Classifier::fromArtifact($artifact);

        self::assertGreaterThan(0.5, $scorer->classify('hard')['qa'], 'precomputed hard label should score as hard');
        self::assertLessThan(0.5, $scorer->classify('easy')['qa'], 'precomputed easy label should score as easy');
    }

    public function test_mixed_corpus_uses_panel_only_for_unlabelled_rows(): void
    {
        // One row precomputed (hard), one row left to the panel (both pass -> easy).
        $strong = new FakeProvider('strong', static fn (string $p): string => '4');
        $weak = new FakeProvider('weak', static fn (string $p): string => '4');

        $corpus = SeedCorpus::fromArray([
            new CorpusRow('hard', 'qa', Reference::none(), null, 1.0),
            new CorpusRow('easy', 'qa', Reference::goldAnswer('4')),
        ]);

        $artifact = (new Calibrator(
            panel: [$strong, $weak],
            corpus: $corpus,
            graders: new GraderResolver(['qa' => new ExactMatchGrader()]),
            language: 'it',
            fasttext: '',
            embeddingSource: new ArrayEmbeddingSource(2, [
                'easy' => [-1.0, 0.0],
                'hard' => [1.0, 0.0],
            ]),
        ))->run();

        $scorer = Classifier::fromArtifact($artifact);

        self::assertGreaterThan(0.5, $scorer->classify('hard')['qa']);
        self::assertLessThan(0.5, $scorer->classify('easy')['qa']);
    }
}
