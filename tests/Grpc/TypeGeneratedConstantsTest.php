<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Symfony\Component\Process\Process;

class TypeGeneratedConstantsTest extends TestCase
{
    protected string $temporaryDirectory;

    protected Filesystem $filesystem;

    /**
     * Prepare the generated-code fixture directory.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->temporaryDirectory = ParallelTesting::tempDir('TypeGeneratedConstantsTest');
        $this->filesystem->deleteDirectory($this->temporaryDirectory);
        mkdir($this->temporaryDirectory, 0777, true);
    }

    /**
     * Remove the generated-code fixture directory.
     */
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    /**
     * Type raw protoc constants without changing already typed declarations.
     */
    public function testItTypesRawProtocConstantsIdempotently(): void
    {
        $fixturePath = $this->temporaryDirectory . '/ServingStatus.php';

        file_put_contents($fixturePath, <<<'PHP'
            <?php

            class ServingStatus
            {
                const UNKNOWN = 0;
                const NEGATIVE = -3;
                public const PUBLIC_VALUE = 7;
                protected const LEVEL = 3;
                private const INTERNAL = 4;
                final const FINAL_VALUE = 5;
                public final const PUBLIC_FINAL_VALUE = 8;
                public const int ALREADY_TYPED = 6;
            }
            PHP);

        $process = $this->runTransformer();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('Typed 7 generated protobuf constants.' . PHP_EOL, $process->getOutput());

        $expectedContents = <<<'PHP'
            <?php

            class ServingStatus
            {
                const int UNKNOWN = 0;
                const int NEGATIVE = -3;
                public const int PUBLIC_VALUE = 7;
                protected const int LEVEL = 3;
                private const int INTERNAL = 4;
                final const int FINAL_VALUE = 5;
                public final const int PUBLIC_FINAL_VALUE = 8;
                public const int ALREADY_TYPED = 6;
            }
            PHP;

        $this->assertSame($expectedContents, file_get_contents($fixturePath));

        $secondProcess = $this->runTransformer();

        $this->assertTrue($secondProcess->isSuccessful(), $secondProcess->getErrorOutput());
        $this->assertSame('Typed 0 generated protobuf constants.' . PHP_EOL, $secondProcess->getOutput());
        $this->assertSame($expectedContents, file_get_contents($fixturePath));
    }

    /**
     * Reject generated constants whose types cannot be inferred safely.
     */
    public function testItRejectsUnsupportedUntypedConstants(): void
    {
        $fixturePath = $this->temporaryDirectory . '/Unsupported.php';
        $fixtureContents = <<<'PHP'
            <?php

            class Unsupported
            {
                const NAME = 'unknown';
            }
            PHP;

        file_put_contents($fixturePath, $fixtureContents);

        $process = $this->runTransformer();

        $this->assertFalse($process->isSuccessful());
        $this->assertSame('', $process->getOutput());
        $this->assertStringContainsString(
            "Generated file [{$fixturePath}] contains a class constant without a supported type.",
            $process->getErrorOutput()
        );
        $this->assertSame($fixtureContents, file_get_contents($fixturePath));
    }

    /**
     * Run the generated constant transformer.
     */
    protected function runTransformer(): Process
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/src/grpc/resources/proto/type-generated-constants.php',
            $this->temporaryDirectory,
        ]);
        $process->run();

        return $process;
    }
}
