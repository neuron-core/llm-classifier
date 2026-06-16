<?php

declare(strict_types=1);

require_once \dirname(__DIR__) . '/vendor/autoload.php';

use NeuronCore\Classifier\Calibration\Calibrator;
use NeuronCore\Classifier\Calibration\GraderResolver;
use NeuronCore\Classifier\Calibration\SeedCorpus;

/**
 * Cold-start the classifier from a precomputed-difficulty seed CSV — for example
 * the bundled `datasets/routerbench.csv` derived from the RouterBench benchmark.
 *
 * Because every row carries a `difficulty` label, calibration makes ZERO API
 * calls: the panel is empty and nothing is graded. Only the fastText vector file
 * is read, to build the pruned embedding table baked into model.bin file.
 *
 *   php script/routerbench.php --fasttext path/to/cc.en.300.vec
 *
 * The resulting `model.bin` is loaded at runtime via `Classifier::load()` — see
 * the "Cold-starting from a routing benchmark" section of the project README.
 */

$repoRoot = \dirname(__DIR__);
$defaults = [
    'csv' => $repoRoot . '/datasets/routerbench.csv',
    'fasttext' => $repoRoot . '/datasets/cc.en.300.vec',
    'out' => $repoRoot . '/storage/model.bin',
    'language' => 'en',
    'hard-threshold' => '0.5',
];

$args = parseArgs($argv, $defaults);

if ($args['help'] || $args['fasttext'] === '') {
    printHelp($defaults);
    exit($args['help'] ? 0 : 1);
}

if (!\file_exists($args['csv']) || !\is_readable($args['csv'])) {
    fail(\sprintf('Seed CSV not found or not readable: %s', $args['csv']));
}
if (!\file_exists($args['fasttext']) || !\is_readable($args['fasttext'])) {
    fail(
        "fastText vector file not found or not readable: {$args['fasttext']}\n" .
        "Download cc.{$args['language']}.300.vec.gz from https://fasttext.cc/docs/en/crawl-vectors.html#models,\n" .
        "gunzip it, and pass its path with --fasttext (or set FASTTEXT_VEC)."
    );
}

$hardThreshold = (float) $args['hard-threshold'];

\fwrite(\STDOUT, "\nCalibrating from {$args['csv']}\n");
\fwrite(\STDOUT, "  vectors : {$args['fasttext']}\n");
\fwrite(\STDOUT, "  language: {$args['language']}\n");
\fwrite(\STDOUT, "  hard threshold: {$hardThreshold}\n");
\fwrite(\STDOUT, "\nTraining...\n");

$directory = \dirname((string) $args['out']);
if (!\file_exists($directory) && !@\mkdir($directory, 0o775, true)) {
    fail("Cannot create output directory: {$directory}");
}

$artifact = (new Calibrator(
    panel: [],                                 // no test-takers — labels are precomputed
    corpus: SeedCorpus::fromFile($args['csv']),
    graders: new GraderResolver([]),           // nothing to grade
    language: $args['language'],
    fasttext: $args['fasttext'],
    hardThreshold: $hardThreshold,
))->run();

$artifact->writeTo($args['out']);

\fwrite(\STDOUT, "\nDone.\n");
\fwrite(\STDOUT, \sprintf("  model : %s  (%s)\n\n", $args['out'], humanSize(\filesize($args['out']))));

/**
 * @param list<string> $argv
 * @param array<string,string> $defaults
 *
 * @return array<string,string|bool>
 */
function parseArgs(array $argv, array $defaults): array
{
    $parsed = $defaults + ['help' => false];
    $count = \count($argv);
    for ($i = 1; $i < $count; ++$i) {
        $arg = $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $parsed['help'] = true;
            continue;
        }
        if (\str_starts_with($arg, '--') && isset($argv[$i + 1])) {
            $key = \substr($arg, 2);
            if (\array_key_exists($key, $defaults)) {
                $parsed[$key] = $argv[++$i];
            }
        }
    }

    return $parsed;
}

function printHelp(array $defaults): void
{
    \fwrite(\STDOUT, <<<HELP
        Cold-start the classifier from a precomputed-difficulty seed CSV.

        Usage:
          php script/routerbench.php --fasttext <cc.en.300.vec> [options]

        Options:
          --csv <path>            seed CSV with a precomputed difficulty per row
                                  (default: datasets/routerbench.csv)
          --fasttext <path>       fastText cc.<lang>.300.vec file  [required]
                                  (or set FASTTEXT_VEC)
          --out <path>            output model.script path
                                  (default: storage/model.script)
          --language <code>       language tag baked into the model + expected vec file
                                  (default: en)
          --hard-threshold <f>    difficulty above which a row is labelled hard
                                  (default: 0.5)
          -h, --help              show this help

        Download the fastText vectors (one-time) from https://fasttext.cc/docs/en/crawl-vectors.html#models:
          cc.<language>.300.vec.gz  on the crawl-vectors page, then gunzip it.

        HELP);
}

function humanSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) $bytes;
    $i = 0;
    while ($size >= 1024.0 && $i < \count($units) - 1) {
        $size /= 1024.0;
        ++$i;
    }

    return \sprintf('%.1f %s', $size, $units[$i]);
}

function fail(string $message): never
{
    \fwrite(\STDERR, "Error: {$message}\n");
    exit(1);
}
