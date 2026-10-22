<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\OutputStyle;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Migrations\DatabaseMigrationRepository;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

class MigratorCoroutineIsolationTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testConcurrentRunsKeepConnectionsOutputAndRestorationIsolated(): void
    {
        [$migrator, $repository] = $this->migrator();
        $migrator->path('/shared/migrations');

        run(function () use ($migrator, $repository): void {
            $tasks = [];

            foreach (['first', 'second'] as $name) {
                $tasks[] = function () use ($migrator, $repository, $name): string {
                    $output = new BufferedOutput;
                    $migrator->setOutput(new OutputStyle(new ArrayInput([]), $output));
                    $migrator->setConnection('before-' . $name);
                    CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'ambient-' . $name);
                    $failure = new RuntimeException('Migration failed.');

                    try {
                        $migrator->usingConnection($name, function () use ($migrator, $repository, $name, $failure): void {
                            usleep(5000);

                            $this->assertSame($name, $migrator->getConnection());
                            $this->assertSame($name, $migrator->resolveConnection(null)->getName());
                            $this->assertSame($name, $repository->getConnection()->getName());
                            $this->assertSame($name, CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY));
                            $this->assertSame(['/shared/migrations'], $migrator->paths());
                            $migrator->runPending([]);

                            if ($name === 'second') {
                                throw $failure;
                            }
                        });

                        if ($name === 'second') {
                            $this->fail('The migration failure must propagate.');
                        }
                    } catch (RuntimeException $exception) {
                        $this->assertSame($failure, $exception);
                    }

                    $this->assertSame('before-' . $name, $migrator->getConnection());
                    $this->assertSame('before-' . $name, $repository->getConnection()->getName());
                    $this->assertSame('ambient-' . $name, CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY));

                    return $output->fetch();
                };
            }

            foreach (parallel($tasks) as $output) {
                $this->assertSame(1, substr_count($output, 'Nothing to migrate'));
            }

            $this->assertNull($migrator->getConnection());
            $this->assertSame('default', $repository->getConnection()->getName());
        });
    }

    public function testForkedRunsInheritStateWithoutChangingParentAssignments(): void
    {
        [$migrator, $repository] = $this->migrator();

        run(function () use ($migrator, $repository): void {
            $parentOutput = new BufferedOutput;
            $migrator->setConnection('parent');
            $migrator->setOutput(new OutputStyle(new ArrayInput([]), $parentOutput));

            [$childOutput] = parallel([
                function () use ($migrator, $repository): string {
                    $this->assertSame('parent', $migrator->getConnection());
                    $this->assertSame('parent', $repository->getConnection()->getName());
                    $migrator->runPending([]);

                    $output = new BufferedOutput;
                    $migrator->setOutput(new OutputStyle(new ArrayInput([]), $output));
                    $migrator->setConnection('child');
                    usleep(5000);
                    $migrator->runPending([]);
                    $this->assertSame('child', $repository->getConnection()->getName());

                    return $output->fetch();
                },
            ], copyContext: true);

            $this->assertSame('parent', $migrator->getConnection());
            $this->assertSame('parent', $repository->getConnection()->getName());
            $this->assertSame('parent', CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY));
            $migrator->runPending([]);
            $this->assertSame(2, substr_count($parentOutput->fetch(), 'Nothing to migrate'));
            $this->assertSame(1, substr_count($childOutput, 'Nothing to migrate'));
        });
    }

    public function testLiveInstancesKeepTheirOwnSelectedConnections(): void
    {
        [$first, $firstRepository] = $this->migrator();
        [$second, $secondRepository] = $this->migrator();

        run(function () use ($first, $second, $firstRepository, $secondRepository): void {
            $first->setConnection('first');
            $second->setConnection('second');

            $this->assertSame('first', $first->getConnection());
            $this->assertSame('first', $firstRepository->getConnection()->getName());
            $this->assertSame('second', $second->getConnection());
            $this->assertSame('second', $secondRepository->getConnection()->getName());

            $firstRepository->setSource('install');
            $this->assertSame('install', $firstRepository->getConnection()->getName());
            $this->assertSame('second', $secondRepository->getConnection()->getName());
        });
    }

    public function testNonCoroutineStateIsPreservedAcrossIndependentCoroutineRuns(): void
    {
        [$migrator, $repository] = $this->migrator();
        $output = new BufferedOutput;
        $migrator->setConnection('outside');
        $migrator->setOutput(new OutputStyle(new ArrayInput([]), $output));

        run(function () use ($migrator, $repository): void {
            $this->assertNull($migrator->getConnection());
            $this->assertSame('default', $repository->getConnection()->getName());
            $migrator->setConnection('inside');
            $migrator->runPending([]);
        });

        $this->assertSame('outside', $migrator->getConnection());
        $this->assertSame('outside', $repository->getConnection()->getName());
        $this->assertSame('', $output->fetch());
        $migrator->runPending([]);
        $this->assertStringContainsString('Nothing to migrate', $output->fetch());
    }

    public function testReplacementMigratorDoesNotInheritReleasedInstanceState(): void
    {
        run(function (): void {
            $resolver = m::mock(ConnectionResolverInterface::class);
            $repository = new DatabaseMigrationRepository($resolver, 'migrations');
            $files = new Filesystem;
            $output = new BufferedOutput;
            $migrator = new Migrator($repository, $resolver, $files);
            $migrator->setConnection('previous');
            $migrator->setOutput(new OutputStyle(new ArrayInput([]), $output));
            unset($migrator);

            $replacement = new Migrator($repository, $resolver, $files);

            $this->assertNull($replacement->getConnection());
            $replacement->runPending([]);
            $this->assertSame('', $output->fetch());
            $replacement->setConnection('replacement');
            $this->assertSame('replacement', $replacement->getConnection());
        });
    }

    public function testReplacementRepositoryDoesNotInheritReleasedInstanceConnection(): void
    {
        run(function (): void {
            $connection = m::mock(Connection::class);
            $resolver = m::mock(ConnectionResolverInterface::class);
            $resolver->expects('connection')->with(null)->andReturn($connection);
            $repository = new DatabaseMigrationRepository($resolver, 'migrations');
            $repository->setSource('previous');
            unset($repository);

            $replacement = new DatabaseMigrationRepository($resolver, 'migrations');

            $this->assertSame($connection, $replacement->getConnection());
        });
    }

    /**
     * Build a real migrator and repository with named connection doubles.
     *
     * @return array{Migrator, DatabaseMigrationRepository}
     */
    private function migrator(): array
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->allows('connection')->andReturnUsing(static function (?string $name): Connection {
            $connection = m::mock(Connection::class);
            $connection->allows('getName')->andReturn($name ?? 'default');

            return $connection;
        });

        $repository = new DatabaseMigrationRepository($resolver, 'migrations');

        return [new Migrator($repository, $resolver, new Filesystem), $repository];
    }
}
