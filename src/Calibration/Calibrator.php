<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Calibration;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronCore\Classifier\Model;
use NeuronCore\Classifier\Embeddings;
use NeuronCore\Classifier\Head;
use NeuronCore\Classifier\LogisticRegression;
use NeuronCore\Classifier\Tokenizer;
use RuntimeException;

use function array_merge;
use function count;
use function array_unique;
use function array_values;

/**
 * The calibration engine.
 *
 * Runs each seed prompt through a panel of plain-text providers, grades each
 * response, derives a hard/easy label from the panel's failure rate, mean-pools
 * each prompt into a feature vector, fits one logistic head per capability and
 * serializes the result into a portable {@see Model}.
 *
 * The only class that touches {@see AIProviderInterface}; the resulting artifact
 * is model-agnostic.
 */
final class Calibrator
{
    private readonly Tokenizer $tokenizer;

    /**
     * @param array<AIProviderInterface> $panel        the test-takers; may be empty when every corpus row carries a precomputed difficulty
     * @param SeedCorpus                 $corpus       prompt, capability, reference, [grader]
     * @param GraderResolver             $graders      capability -> Grader (per-row override honoured)
     * @param string                     $language     target language, e.g. "it"
     * @param string                     $fasttext     path to a cc.<lang>.300.vec file
     * @param float                      $hardThreshold difficulty above which a prompt is labelled hard
     * @param EmbeddingSource|null       $embeddingSource optional injected source (tests, RouterBench grid, ...)
     */
    public function __construct(
        private readonly array $panel,
        private readonly SeedCorpus $corpus,
        private readonly GraderResolver $graders,
        private readonly string $language,
        private readonly string $fasttext,
        private readonly float $hardThreshold = 0.5,
        private readonly ?EmbeddingSource $embeddingSource = null,
        ?Tokenizer $tokenizer = null,
    ) {
        $this->tokenizer = $tokenizer ?? new Tokenizer();
    }

    public function run(): Model
    {
        $rows = $this->corpus->rows();

        if (count($rows) === 0) {
            throw new RuntimeException('Cannot calibrate on an empty corpus.');
        }

        // The panel is only needed for rows whose difficulty is not precomputed.
        $needsPanel = false;
        foreach ($rows as $row) {
            if ($row->difficulty() === null) {
                $needsPanel = true;
                break;
            }
        }
        if ($needsPanel && count($this->panel) === 0) {
            throw new RuntimeException('Cannot calibrate without a provider panel.');
        }

        // Pre-flight: fail loudly on incompatible row/grader pairings before billing.
        $this->graders->validate($rows);

        // Build the pruned embedding table from the corpus vocabulary.
        $source = $this->embeddingSource ?? new FastTextSource($this->fasttext);
        $tokens = $this->corpusVocabulary($rows);
        $vectors = $source->vectors($tokens);
        $dim = $source->dim();

        $embeddings = new Embeddings($dim, $this->tokenizer, $vectors);

        // Label + featurize every row. A precomputed difficulty is used directly;
        // otherwise the label comes from the panel's failure rate for that row.
        $labeled = []; // capability => list of {vector, label}
        foreach ($rows as $row) {
            $vector = $embeddings->meanPool($row->prompt());
            $difficulty = $row->difficulty() ?? (1.0 - ($this->passRate($row) ?? 0.0));
            $label = $difficulty >= $this->hardThreshold ? 1 : 0;
            $labeled[$row->capability()][] = ['vector' => $vector, 'label' => $label];
        }

        // Fit one logistic head per capability.
        $heads = [];
        foreach ($labeled as $capability => $examples) {
            $samples = [];
            $labels = [];
            foreach ($examples as $example) {
                $samples[] = $example['vector'];
                $labels[] = $example['label'];
            }
            $heads[] = LogisticRegression::fit($capability, $samples, $labels, $dim);
        }

        return new Model($this->language, $dim, $this->tokenizer->pattern(), $vectors, $heads);
    }

    /**
     * Fraction of the panel that produced a correct response for a row.
     * Returns null if the row's grader cannot run (e.g. ungradeable reference);
     * such rows contribute difficulty 0 (treated as easy) rather than blocking.
     */
    private function passRate(CorpusRow $row): ?float
    {
        $grader = $this->graders->resolve($row);
        if (!$grader->supports($row->reference()->type())) {
            return null;
        }

        $passes = 0;
        foreach ($this->panel as $provider) {
            $response = $provider->chat(new UserMessage($row->prompt()))->getContent() ?? '';
            if ($grader->isCorrect($row->prompt(), $response, $row->reference())) {
                ++$passes;
            }
        }

        return $passes / count($this->panel);
    }

    /**
     * @param list<CorpusRow> $rows
     *
     * @return list<string> unique corpus tokens
     */
    private function corpusVocabulary(array $rows): array
    {
        $tokens = [];
        foreach ($rows as $row) {
            $tokens = array_merge($tokens, $this->tokenizer->tokenize($row->prompt()));
        }

        return array_values(array_unique($tokens));
    }
}
