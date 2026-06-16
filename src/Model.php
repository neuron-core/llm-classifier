<?php

declare(strict_types=1);

namespace NeuronCore\Classifier;

use RuntimeException;

use function array_values;
use function count;
use function sprintf;
use function strlen;
use function substr;
use function file_get_contents;
use function file_put_contents;
use function pack;
use function unpack;

/**
 * The serialized model: the single contract between the calibration engine and
 * the runtime. Holds meta, the pruned embedding table and one head per capability.
 *
 * Binary layout (all little-endian, "DCRM" magic, version 1):
 *   "DCRM"
 *   uint8  version
 *   uint16 lang length + bytes
 *   uint16 dim
 *   uint16 tokenizer pattern length + bytes
 *   uint32 token count T
 *   T x (uint8 token length + bytes + dim x float32 vector)
 *   uint16 head count H
 *   H x (uint8 capability length + bytes + dim x float32 weights + float32 bias)
 *
 * The runtime needs only this file plus PHP + ext-mbstring to consume it.
 */
final class Model
{
    private const MAGIC = 'DCRM';
    private const VERSION = 1;

    /**
     * @param string                           $language  e.g. "en", "it", "fr"
     * @param positive-int                     $dim       embedding dimensionality
     * @param non-empty-string                 $pattern   tokenizer pattern baked into the artifact
     * @param array<string, array<int, float>> $vectors   pruned token => vector table
     * @param list<Head>                       $heads
     */
    public function __construct(
        private readonly string $language,
        private readonly int $dim,
        private readonly string $pattern,
        private readonly array $vectors,
        private readonly array $heads,
    ) {
    }

    public function language(): string
    {
        return $this->language;
    }

    public function dim(): int
    {
        return $this->dim;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    /**
     * @return list<Head>
     */
    public function heads(): array
    {
        return $this->heads;
    }

    /**
     * @return array<string, array<int, float>>
     */
    public function vectors(): array
    {
        return $this->vectors;
    }

    /**
     * @return array<int, float>|null
     */
    public function vector(string $token): ?array
    {
        return $this->vectors[$token] ?? null;
    }

    public function writeTo(string $path): void
    {
        file_put_contents($path, $this->encode());
    }

    public static function fromFile(string $path): self
    {
        return self::decode(file_get_contents($path) ?: '');
    }

    /**
     * @return non-empty-string
     */
    private function encode(): string
    {
        $out = self::MAGIC;
        $out .= pack('C', self::VERSION);
        $out .= $this->string16($this->language);
        $out .= pack('v', $this->dim);
        $out .= $this->string16($this->pattern);
        $out .= pack('V', count($this->vectors));

        foreach ($this->vectors as $token => $vector) {
            // Numeric tokens (e.g. years, model versions) are coerced to int array
            // keys; cast back to string so the token is written verbatim.
            $out .= $this->string8((string) $token);
            $out .= pack('g*', ...$vector);
        }

        $out .= pack('v', count($this->heads));
        foreach ($this->heads as $head) {
            $out .= $this->string8($head->capability());
            $out .= pack('g*', ...$head->weights());
            $out .= pack('g', $head->bias());
        }

        return $out;
    }

    private static function decode(string $data): self
    {
        $offset = 0;

        if (substr($data, $offset, 4) !== self::MAGIC) {
            throw new RuntimeException('Invalid artifact: bad magic header.');
        }
        $offset += 4;

        $version = self::u8($data, $offset);
        if ($version !== self::VERSION) {
            throw new RuntimeException(sprintf('Unsupported artifact version %d.', $version));
        }

        $lang = self::readString16($data, $offset);
        $dim = self::u16($data, $offset);
        $pattern = self::readString16($data, $offset);

        $tokenCount = self::u32($data, $offset);

        $vectors = [];
        for ($t = 0; $t < $tokenCount; ++$t) {
            $token = self::readString8($data, $offset);
            $vectors[$token] = self::floats($data, $offset, $dim);
        }

        $headCount = self::u16($data, $offset);

        $heads = [];
        for ($h = 0; $h < $headCount; ++$h) {
            $capability = self::readString8($data, $offset);
            $weights = self::floats($data, $offset, $dim);
            $bias = self::f32($data, $offset);
            $heads[] = new Head($capability, $weights, $bias);
        }

        return new self($lang, $dim, $pattern, $vectors, $heads);
    }

    private function string8(string $value): string
    {
        return pack('C', strlen($value)) . $value;
    }

    private function string16(string $value): string
    {
        return pack('v', strlen($value)) . $value;
    }

    private static function u8(string $data, int &$offset): int
    {
        $result = unpack('C', substr($data, $offset, 1));
        $offset += 1;

        return self::first($result);
    }

    private static function u16(string $data, int &$offset): int
    {
        $result = unpack('v', substr($data, $offset, 2));
        $offset += 2;

        return self::first($result);
    }

    private static function u32(string $data, int &$offset): int
    {
        $result = unpack('V', substr($data, $offset, 4));
        $offset += 4;

        return self::first($result);
    }

    private static function f32(string $data, int &$offset): float
    {
        $result = unpack('g', substr($data, $offset, 4));
        $offset += 4;

        return $result === false ? 0.0 : (float) ($result[1] ?? 0.0);
    }

    /**
     * @return array<int, float>
     */
    private static function floats(string $data, int &$offset, int $count): array
    {
        $result = unpack('g' . $count, substr($data, $offset, $count * 4));
        $offset += $count * 4;

        return array_values($result ?: []);
    }

    private static function readString8(string $data, int &$offset): string
    {
        $length = self::u8($data, $offset);

        return self::slice($data, $offset, $length);
    }

    private static function readString16(string $data, int &$offset): string
    {
        $length = self::u16($data, $offset);

        return self::slice($data, $offset, $length);
    }

    private static function slice(string $data, int &$offset, int $length): string
    {
        $value = substr($data, $offset, $length);
        $offset += $length;

        return $value;
    }

    /**
     * @param array<int, int>|false $result
     */
    private static function first(array|false $result): int
    {
        return $result === false ? 0 : ($result[1] ?? 0);
    }
}
