<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Database\Migrations\MigrationRepositoryInterface;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Testbench\Contracts\TestCase as TestCaseContract;
use Hypervel\Testbench\Database\MigrateProcessor;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class MigrateProcessorTest extends TestCase
{
    use VerifiesDoubles;

    #[Test]
    public function itRollsBackOnlyTheBatchCreatedByTheProcessor(): void
    {
        $repository = Double::for(MigrationRepositoryInterface::class)->strict();
        $repository->expects('repositoryExists')->times(2)->returns(true);
        $repository->expects('getNextBatchNumber')->times(2)->returns(4, 5);
        $repository->expects('getMigrationsByBatch')->with(4)->returns([]);
        $testbench = Double::for(TestCaseContract::class)->strict();
        $testbench->expects('artisan')
            ->with('migrate', [
                '--database' => 'secondary',
                '--path' => ['/migrations'],
                '--step' => true,
            ])
            ->returns(0);
        $testbench->expects('artisan')
            ->with('migrate:rollback', [
                '--database' => 'secondary',
                '--path' => ['/migrations'],
                '--batch' => 4,
            ])
            ->returns(0);
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
        $repository = Double::for(MigrationRepositoryInterface::class)->strict();
        $repository->expects('repositoryExists')->times(2)->returns(true);
        $repository->expects('getNextBatchNumber')->times(2)->returns(1, 4);
        $repository->expects('getMigrationsByBatch')->with(3)->returns([]);
        $repository->expects('getMigrationsByBatch')->with(2)->returns([]);
        $repository->expects('getMigrationsByBatch')->with(1)->returns([]);
        $testbench = Double::for(TestCaseContract::class)->strict();
        $testbench->expects('artisan')->with('migrate', ['--step' => true])->returns(0);

        foreach ([3, 2, 1] as $batch) {
            $testbench->expects('artisan')
                ->with('migrate:rollback', ['--batch' => $batch])
                ->ordered()
                ->returns(0);
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
        $repository = Double::for(MigrationRepositoryInterface::class)->strict();
        $repository->expects('repositoryExists')->times(2)->returns(true);
        $repository->expects('getNextBatchNumber')->times(2)->returns(4, 4);
        $testbench = Double::for(TestCaseContract::class)->strict();
        $testbench->expects('artisan')->with('migrate', [])->returns(0);
        $testbench->expects('artisan')->never()->with('migrate:rollback', Argument::any());
        $processor = new MigrateProcessor($testbench, $this->migrator($repository));

        $processor->up()->rollback();
    }

    #[Test]
    public function itCapturesACompletedBatchWhenAFollowingMigrationFails(): void
    {
        $repository = Double::for(MigrationRepositoryInterface::class)->strict();
        $repository->expects('repositoryExists')->times(2)->returns(true);
        $repository->expects('getNextBatchNumber')->times(2)->returns(1, 2);
        $repository->expects('getMigrationsByBatch')->with(1)->returns([]);
        $testbench = Double::for(TestCaseContract::class)->strict();
        $testbench->expects('artisan')
            ->with('migrate', [])
            ->throws(new RuntimeException('Migration failed.'));
        $testbench->expects('artisan')
            ->with('migrate:rollback', ['--batch' => 1])
            ->returns(0);
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
        $repository = Double::for(MigrationRepositoryInterface::class)->strict();
        $repository->expects('repositoryExists')->times(2)->returns(true);
        $repository->expects('getNextBatchNumber')->times(2)->returns(1, 2);
        $repository->expects('getMigrationsByBatch')->with(1)->returns([]);
        $testbench = Double::for(TestCaseContract::class)->strict();
        $testbench->expects('artisan')->with('migrate', [])->returns(1);
        $testbench->expects('artisan')
            ->with('migrate:rollback', ['--batch' => 1])
            ->returns(0);
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
        $repository = Double::for(MigrationRepositoryInterface::class)->strict();
        $repository->expects('repositoryExists')->times(2)->returns(true);
        $repository->expects('getNextBatchNumber')->times(2)->returns(1, 3);
        $repository->expects('getMigrationsByBatch')->with(1)->returns([]);
        $testbench = Double::for(TestCaseContract::class)->strict();
        $testbench->expects('artisan')->with('migrate', ['--step' => true])->returns(0);
        $testbench->expects('artisan')
            ->with('migrate:rollback', ['--batch' => 2])
            ->returns(1);
        $testbench->expects('artisan')
            ->with('migrate:rollback', ['--batch' => 1])
            ->returns(0);
        $processor = new MigrateProcessor(
            $testbench,
            $this->migrator($repository),
            ['--step' => true],
        );
        $processor->up();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to roll back migration batch [2].');

        $processor->rollback();
    }

    #[Test]
    public function itRejectsAReportedRollbackThatLeavesTheBatchRecorded(): void
    {
        $repository = Double::for(MigrationRepositoryInterface::class)->strict();
        $repository->expects('repositoryExists')->times(2)->returns(true);
        $repository->expects('getNextBatchNumber')->times(2)->returns(1, 2);
        $repository->expects('getMigrationsByBatch')->with(1)->returns(['migration']);
        $testbench = Double::for(TestCaseContract::class)->strict();
        $testbench->expects('artisan')->with('migrate', [])->returns(0);
        $testbench->expects('artisan')
            ->with('migrate:rollback', ['--batch' => 1])
            ->returns(0);
        $processor = new MigrateProcessor($testbench, $this->migrator($repository));
        $processor->up();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Migration batch [1] remains after rollback.');

        $processor->rollback();
    }

    /**
     * Create a migrator mock that executes callbacks in the requested connection scope.
     */
    private function migrator(
        MigrationRepositoryInterface $repository,
        ?string $connection = null,
    ): Migrator {
        $migrator = Double::for(Migrator::class)->strict();
        $migrator->allows('getRepository')->returns($repository);
        $migrator->allows('usingConnection')
            ->with($connection, Argument::type('callable'))
            ->resolves(static fn (?string $name, callable $callback): mixed => $callback());

        return $migrator;
    }
}
