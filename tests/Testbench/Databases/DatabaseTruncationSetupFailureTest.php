<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase as TestbenchTestCase;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\SkippedWithMessageException;
use RuntimeException;
use Throwable;

use function Hypervel\Testbench\workbench_path;

class DatabaseTruncationSetupFailureTest extends TestCase
{
    #[DataProvider('setupFailures')]
    public function testClassCleanupReleasesRetainedSchemaAfterSetupAborts(bool $skip): void
    {
        $fixture = new DatabaseTruncationSetupFailureFixture('testPlaceholder');
        $fixture->skipSetup = $skip;
        $failure = null;

        ResetRefreshDatabaseState::run();
        DatabaseTruncationSetupFailureFixture::setUpBeforeClass();

        try {
            try {
                try {
                    $fixture->start();
                } catch (Throwable $throwable) {
                    $failure = $throwable;
                } finally {
                    $fixture->finish();
                }

                $this->assertInstanceOf($skip ? SkippedWithMessageException::class : RuntimeException::class, $failure);
                $this->assertSame('Intentional setup interruption.', $failure->getMessage());
                // Per-method teardown still retains the database for truncation reuse.
                $this->assertTrue(RefreshDatabaseState::$migrated);
                $this->assertArrayHasKey('testing', RefreshDatabaseState::$inMemoryConnections);
            } finally {
                DatabaseTruncationSetupFailureFixture::tearDownAfterClass();
            }

            $this->assertFalse(RefreshDatabaseState::$migrated);
            $this->assertSame([], RefreshDatabaseState::$inMemoryConnections);
        } finally {
            ResetRefreshDatabaseState::run();
        }
    }

    public static function setupFailures(): array
    {
        return [
            'skipped service setup' => [true],
            'failed setup' => [false],
        ];
    }
}

#[WithConfig('database.default', 'testing')]
class DatabaseTruncationSetupFailureFixture extends TestbenchTestCase
{
    use DatabaseTruncation;
    use InterruptsDatabaseSetup;

    public function start(): void
    {
        $this->setUp();
    }

    public function finish(): void
    {
        $this->tearDown();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(workbench_path('database/migrations'));
    }

    public function testPlaceholder(): void
    {
        $this->fail('Setup must abort before the test body.');
    }
}

/**
 * @phpstan-require-extends TestbenchTestCase
 */
trait InterruptsDatabaseSetup
{
    public bool $skipSetup = false;

    protected function setUpInterruptsDatabaseSetup(): void
    {
        if ($this->skipSetup) {
            $this->markTestSkipped('Intentional setup interruption.');
        }

        throw new RuntimeException('Intentional setup interruption.');
    }
}
