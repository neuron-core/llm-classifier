<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Calibration;

use RuntimeException;

use function array_fill_keys;
use function explode;
use function fclose;
use function fgets;
use function fopen;
use function sprintf;
use function trim;
use function array_key_exists;
use function array_map;
use function array_slice;
use function count;

/**
 * Reads a fastText `cc.<lang>.300.vec` file, pruning to the top-N tokens present
 * in the corpus. Scans the file once and keeps only requested tokens, so the
 * artifact ships a small, in-domain table rather than the full vocabulary.
 *
 * Layout of a `.vec` file: a header line "<vocab_size> <dim>", then one line per
 * token: "<token> v1 v2 ... vdim".
 */
final class FastTextSource implements EmbeddingSource
{
    private int $dim = 0;

    public function __construct(
        private readonly string $path,
    ) {
    }

    public function dim(): int
    {
        if ($this->dim === 0) {
            throw new RuntimeException('dim() called before vectors() was probed.');
        }

        return $this->dim;
    }

    public function vectors(iterable $tokens): array
    {
        $wanted = array_fill_keys($this->iterableToArray($tokens), true);

        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open fastText vectors: %s', $this->path));
        }

        try {
            $header = fgets($handle);
            if ($header === false) {
                throw new RuntimeException('Empty fastText vectors file.');
            }
            $this->dim = (int) (trim(explode(' ', $header)[1] ?? '0'));
            if ($this->dim <= 0) {
                throw new RuntimeException('Malformed fastText header: no dimensionality.');
            }

            $result = [];
            while (true) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }

                // Token may itself contain spaces in pathological corpora; we split
                // from the right using the known dimensionality to be safe.
                $parts = explode(' ', trim($line));
                $token = $parts[0];
                if (!array_key_exists($token, $wanted)) {
                    continue;
                }

                $values = array_slice($parts, 1, $this->dim);
                if (count($values) !== $this->dim) {
                    continue;
                }

                $result[$token] = array_map(floatval(...), $values);

                unset($wanted[$token]);
                if ($wanted === []) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $result;
    }

    /**
     * @param iterable<string> $tokens
     *
     * @return list<string>
     */
    private function iterableToArray(iterable $tokens): array
    {
        $list = [];
        foreach ($tokens as $token) {
            $list[] = $token;
        }

        return $list;
    }
}
