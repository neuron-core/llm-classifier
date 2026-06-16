<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Tests;

use NeuronCore\Classifier\Model;
use NeuronCore\Classifier\Head;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_exists;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class ArtifactTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir() . '/classifier-artifact-' . uniqid('', true) . '.script';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }
    }

    public function test_round_trip_preserves_everything(): void
    {
        $original = new Model(
            language: 'it',
            dim: 3,
            pattern: '/[\p{L}\p{N}]+/u',
            vectors: [
                'ciao' => [0.1, -0.2, 0.3],
                'mondo' => [1.5, 2.5, -3.5],
            ],
            heads: [new Head('math', [0.5, -0.25, 0.0], 0.125)],
        );

        $original->writeTo($this->tempPath);
        $loaded = Model::fromFile($this->tempPath);

        self::assertSame('it', $loaded->language());
        self::assertSame(3, $loaded->dim());
        self::assertSame('/[\p{L}\p{N}]+/u', $loaded->pattern());
        // Vectors are serialized as float32, so compare with a tolerance.
        self::assertEqualsWithDelta([0.1, -0.2, 0.3], $loaded->vector('ciao'), 1.0e-4);
        self::assertNull($loaded->vector('missing'));

        $heads = $loaded->heads();
        self::assertCount(1, $heads);
        self::assertSame('math', $heads[0]->capability());
        self::assertEqualsWithDelta([0.5, -0.25, 0.0], $heads[0]->weights(), 1.0e-4);
        self::assertEqualsWithDelta(0.125, $heads[0]->bias(), 1.0e-4);
    }

    public function test_rejects_bad_magic(): void
    {
        file_put_contents($this->tempPath, 'XXXX');

        $this->expectException(RuntimeException::class);
        Model::fromFile($this->tempPath);
    }
}
