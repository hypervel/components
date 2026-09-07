<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Database\Migrations\MigrationRepositoryInterface;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Testbench\Contracts\TestCase as TestCaseContract;
use Hypervel\Testbench\Database\MigrateProcessor;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class MigrateProcessorTest extends TestCase
{
    #[Test]
    public function itRollsBackOnlyTheBatchCreatedByTheProcessor(): void
    {
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('repositoryExists')->twice()->andReturnTrue();
        $repository->shouldReceive('getNextBatchNumber')->twice()->andReturn(4, 5);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(4)->andReturn([]);
        $testbench = m::mock(TestCaseContract::class);
        $testbench->shouldReceive('artisan')
            ->once()
            ->with('migrate', [
                '--database' => 'secondary',
                '--path' => ['/migrations'],
                '--step' => true,
            ])
            ->andReturn(0);
        $testbench->shouldReceive('artisan')
            ->once()
            ->with('migrate:rollback', [
                '--database' => 'secondary',
                '--path' => ['/migrations'],
                '--batch' => 4,
            ])
            ->andReturn(0);
        $processor = new MigrateProcessor(
            $testbench,
            $this->migrator($repository, 'secondary'),
            [
                '--database' => 'secondary',
                '--path' => ['/migrations'],
                '--step' => true,
            ],
        );

        $processor->up()->rollback();
    }

    #[Test]
    public function itRollsBackEveryOwnedStepBatchInDescendingOrder(): void
    {
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('repositoryExists')->twice()->andReturnTrue();
        $repository->shouldReceive('getNextBatchNumber')->twice()->andReturn(1, 4);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(3)->andReturn([]);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(2)->andReturn([]);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(1)->andReturn([]);
        $testbench = m::mock(TestCaseContract::class);
        $testbench->shouldReceive('artisan')->once()->with('migrate', ['--step' => true])->andReturn(0);

        foreach ([3, 2, 1] as $batch) {
            $testbench->shouldReceive('artisan')
                ->once()
                ->with('migrate:rollback', ['--batch' => $batch])
                ->ordered()
                ->andReturn(0);
        }

        $processor = new MigrateProcessor(
            $testbench,
            $this->migrator($repository),
            ['--step' => true],
        );

        $processor->up()->rollback();
    }

    #[Test]
    public function itDoesNotRollbackWhenTheMigrationCreatedNoBatch(): void
    {
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('repositoryExists')->twice()->andReturnTrue();
        $repository->shouldReceive('getNextBatchNumber')->twice()->andReturn(4, 4);
        $testbench = m::mock(TestCaseContract::class);
        $testbench->shouldReceive('artisan')->once()->with('migrate', [])->andReturn(0);
        $testbench->shouldNotReceive('artisan')->with('migrate:rollback', m::any());
        $processor = new MigrateProcessor($testbench, $this->migrator($repository));

        $processor->up()->rollback();
    }

    #[Test]
    public function itCapturesACompletedBatchWhenAFollowingMigrationFails(): void
    {
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('repositoryExists')->twice()->andReturnTrue();
        $repository->shouldReceive('getNextBatchNumber')->twice()->andReturn(1, 2);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(1)->andReturn([]);
        $testbench = m::mock(TestCaseContract::class);
        $testbench->shouldReceive('artisan')
            ->once()
            ->with('migrate', [])
            ->andThrow(new RuntimeException('Migration failed.'));
        $testbench->shouldReceive('artisan')
            ->once()
            ->with('migrate:rollback', ['--batch' => 1])
            ->andReturn(0);
        $processor = new MigrateProcessor($testbench, $this->migrator($repository));

        try {
            $processor->up();
            $this->fail('Expected migration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Migration failed.', $exception->getMessage());
        }

        $processor->rollback();
    }

    #[Test]
    public function itRejectsANonzeroMigrationResultAndRetainsItsOwnedBatchForCleanup(): void
    {
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('repositoryExists')->twice()->andReturnTrue();
        $repository->shouldReceive('getNextBatchNumber')->twice()->andReturn(1, 2);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(1)->andReturn([]);
        $testbench = m::mock(TestCaseContract::class);
        $testbench->shouldReceive('artisan')->once()->with('migrate', [])->andReturn(1);
        $testbench->shouldReceive('artisan')
            ->once()
            ->with('migrate:rollback', ['--batch' => 1])
            ->andReturn(0);
        $processor = new MigrateProcessor($testbench, $this->migrator($repository));

        try {
            $processor->up();
            $this->fail('Expected the migration status to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to run migrations.', $exception->getMessage());
        }

        $processor->rollback();
    }

    #[Test]
    public function itContinuesAfterARollbackFailureAndVerifiesEverySuccessfulBatch(): void
    {
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('repositoryExists')->twice()->andReturnTrue();
        $repository->shouldReceive('getNextBatchNumber')->twice()->andReturn(1, 3);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(1)->andReturn([]);
        $testbench = m::mock(TestCaseContract::class);
        $testbench->shouldReceive('artisan')->once()->with('migrate', ['--step' => true])->andReturn(0);
        $testbench->shouldReceive('artisan')
            ->once()
            ->with('migrate:rollback', ['--batch' => 2])
            ->andReturn(1);
        $testbench->shouldReceive('artisan')
            ->once()
            ->with('migrate:rollback', ['--batch' => 1])
            ->andReturn(0);
        $processor = new MigrateProcessor(
            $testbench,
            $this->migrator($repository),
            ['--step' => true],
        );
        $processor->up();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to roll back migration batch [2].');

        $processor->rollback();
    }

    #[Test]
    public function itRejectsAReportedRollbackThatLeavesTheBatchRecorded(): void
    {
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('repositoryExists')->twice()->andReturnTrue();
        $repository->shouldReceive('getNextBatchNumber')->twice()->andReturn(1, 2);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(1)->andReturn(['migration']);
        $testbench = m::mock(TestCaseContract::class);
        $testbench->shouldReceive('artisan')->once()->with('migrate', [])->andReturn(0);
        $testbench->shouldReceive('artisan')
            ->once()
            ->with('migrate:rollback', ['--batch' => 1])
            ->andReturn(0);
        $processor = new MigrateProcessor($testbench, $this->migrator($repository));
        $processor->up();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migration batch [1] remains after rollback.');

        $processor->rollback();
    }

    /**
     * Create a migrator mock that executes callbacks in the requested connection scope.
     */
    private function migrator(
        MigrationRepositoryInterface $repository,
        ?string $connection = null,
    ): Migrator {
        $migrator = m::mock(Migrator::class);
        $migrator->shouldReceive('getRepository')->andReturn($repository);
        $migrator->shouldReceive('usingConnection')
            ->with($connection, m::type('callable'))
            ->andReturnUsing(static fn (?string $name, callable $callback): mixed => $callback());

        return $migrator;
    }
}
