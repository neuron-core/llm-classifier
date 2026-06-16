<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Calibration;

use RuntimeException;

use function fclose;
use function fgetcsv;
use function fopen;
use function str_getcsv;
use function trim;
use function sprintf;

/**
 * The calibration dataset: a flat list of {@see CorpusRow}s.
 *
 * Loaded from CSV (header: prompt,capability,reference_type,reference,grader,
 * difficulty) or built directly in memory. The trailing columns are optional.
 * The CSV keeps the corpus portable across graders.
 */
final class SeedCorpus
{
    /**
     * @param list<CorpusRow> $rows
     */
    public function __construct(
        private readonly array $rows,
    ) {
    }

    /**
     * @return list<CorpusRow>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @param list<CorpusRow> $rows
     */
    public static function fromArray(array $rows): self
    {
        return new self($rows);
    }

    public static function fromFile(string $path): self
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open corpus file: %s', $path));
        }

        try {
            $header = fgetcsv($handle);
            while (true) {
                $record = fgetcsv($handle);
                if ($record === false) {
                    break;
                }
                $rows[] = self::parseRecord($record);
            }
        } finally {
            fclose($handle);
        }

        unset($header);

        return new self($rows);
    }

    public static function fromCsvLine(string $line): self
    {
        $record = str_getcsv($line);

        return new self([self::parseRecord($record)]);
    }

    /**
     * @param list<string> $record
     */
    private static function parseRecord(array $record): CorpusRow
    {
        return new CorpusRow(
            trim($record[0] ?? ''),
            trim($record[1] ?? ''),
            self::reference($record[2] ?? '', $record[3] ?? ''),
            isset($record[4]) && $record[4] !== '' ? trim($record[4]) : null,
            self::difficulty($record[5] ?? ''),
        );
    }

    private static function reference(string $type, string $value): Reference
    {
        return match (ReferenceType::tryFrom($type)) {
            ReferenceType::GoldAnswer => Reference::goldAnswer($value),
            ReferenceType::Rubric => Reference::rubric($value),
            default => Reference::none(),
        };
    }

    private static function difficulty(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return (float) $value;
    }
}
