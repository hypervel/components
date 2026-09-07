<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Composer;
use Hypervel\Support\Json;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use JsonException;
use RuntimeException;

class ComposerFileTest extends TestCase
{
    protected string $tempDirectory;

    protected string $composerFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = ParallelTesting::tempDir('SupportComposerFileTest');
        (new Filesystem)->deleteDirectory($this->tempDirectory);
        mkdir($this->tempDirectory, 0777, true);

        $this->composerFile = $this->tempDirectory . '/composer.json';
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    public function testHasPackageReadsBothDependencySections(): void
    {
        file_put_contents($this->composerFile, json_encode([
            'require' => ['vendor/runtime' => '^1.0'],
            'require-dev' => ['vendor/testing' => '^1.0'],
        ], JSON_THROW_ON_ERROR));

        $composer = new Composer(new Filesystem, $this->tempDirectory);

        $this->assertTrue($composer->hasPackage('vendor/runtime'));
        $this->assertTrue($composer->hasPackage('vendor/testing'));
        $this->assertFalse($composer->hasPackage('vendor/missing'));
    }

    public function testHasPackageRejectsMalformedJson(): void
    {
        file_put_contents($this->composerFile, '{');

        $this->expectException(JsonException::class);

        (new Composer(new Filesystem, $this->tempDirectory))->hasPackage('vendor/package');
    }

    public function testModifyReplacesComposerFileAndPreservesItsMode(): void
    {
        file_put_contents($this->composerFile, '{"name":"hypervel/app"}');
        chmod($this->composerFile, 0640);

        (new Composer(new Filesystem, $this->tempDirectory))->modify(function (array $composer): array {
            $composer['require']['vendor/package'] = '^1.0';

            return $composer;
        });

        $this->assertSame([
            'name' => 'hypervel/app',
            'require' => ['vendor/package' => '^1.0'],
        ], json_decode(file_get_contents($this->composerFile), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(0640, fileperms($this->composerFile) & 0777);
    }

    public function testModifyRejectsUnencodableCallbackOutput(): void
    {
        file_put_contents($this->composerFile, $original = '{}');
        $stream = fopen('php://memory', 'r');

        try {
            try {
                (new Composer(new Filesystem, $this->tempDirectory))->modify(
                    fn (): array => ['stream' => $stream],
                );

                $this->fail('Expected JSON encoding to fail.');
            } catch (JsonException) {
                $this->assertSame($original, file_get_contents($this->composerFile));
            }
        } finally {
            fclose($stream);
        }
    }

    public function testModifyRoundTripsTheMaximumSupportedNestingDepth(): void
    {
        file_put_contents($this->composerFile, '{}');
        $value = $this->nestedValue(Json::MAXIMUM_NESTING_DEPTH - 1);
        $composer = new Composer(new Filesystem, $this->tempDirectory);

        $composer->modify(fn (): array => ['nested' => $value]);
        $composer->modify(function (array $metadata) use ($value): array {
            $this->assertSame($value, $metadata['nested']);

            return [...$metadata, 'verified' => true];
        });

        $this->assertSame(
            ['nested' => $value, 'verified' => true],
            Json::decode(file_get_contents($this->composerFile))
        );
    }

    public function testModifyRejectsOneLevelOverTheMaximumBeforeReplacingTheFile(): void
    {
        file_put_contents($this->composerFile, $original = '{"name":"hypervel/app"}');
        $value = $this->nestedValue(Json::MAXIMUM_NESTING_DEPTH);

        try {
            (new Composer(new Filesystem, $this->tempDirectory))->modify(
                fn (): array => ['nested' => $value],
            );

            $this->fail('Expected JSON encoding to fail.');
        } catch (JsonException) {
            $this->assertSame($original, file_get_contents($this->composerFile));
        }
    }

    public function testModifyPreservesTheOriginalFileWhenReplacementFails(): void
    {
        file_put_contents($this->composerFile, $original = '{"name":"hypervel/app"}');

        $filesystem = new FailingComposerFilesystem;

        try {
            (new Composer($filesystem, $this->tempDirectory))->modify(
                fn (array $composer): array => [...$composer, 'description' => 'changed'],
            );

            $this->fail('Expected the replacement to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to replace Composer file.', $exception->getMessage());
            $this->assertSame($original, file_get_contents($this->composerFile));
        }
    }

    private function nestedValue(int $containers): array|string
    {
        $value = 'leaf';

        for ($index = 0; $index < $containers; ++$index) {
            $value = ['value' => $value];
        }

        return $value;
    }
}

class FailingComposerFilesystem extends Filesystem
{
    public function replace(string $path, string $content, ?int $mode = null): void
    {
        throw new RuntimeException('Unable to replace Composer file.');
    }
}
