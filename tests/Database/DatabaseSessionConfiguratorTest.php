<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseSessionConfiguratorTest;

use Closure;
use Exception;
use Hypervel\Database\Connection;
use Hypervel\Database\DeadlockException;
use Hypervel\Database\LostConnectionException;
use Hypervel\Database\QueryException;
use Hypervel\Database\SessionConfigurator;
use Hypervel\Tests\TestCase;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class DatabaseSessionConfiguratorTest extends TestCase
{
    public function testUnconfiguredConnectionsDoNotAllocateOrSynchronizeSessionState(): void
    {
        $writeResolutions = 0;
        $readResolutions = 0;
        $writePdo = $this->pdo();
        $readPdo = $this->pdo();
        $connection = $this->connection(function () use (&$writeResolutions, $writePdo): PDO {
            ++$writeResolutions;

            return $writePdo;
        });
        $connection->setReadPdo(function () use (&$readResolutions, $readPdo): PDO {
            ++$readResolutions;

            return $readPdo;
        });

        $this->assertNull(TestSessionConnection::physicalSessionStateCount());
        $this->assertSame($writePdo, $connection->getPdo());
        $this->assertSame($writePdo, $connection->getPdo());
        $this->assertSame($readPdo, $connection->getReadPdo());
        $this->assertSame($readPdo, $connection->getReadPdo());
        $this->assertSame(1, $writeResolutions);
        $this->assertSame(1, $readResolutions);
        $this->assertNull(TestSessionConnection::physicalSessionStateCount());
    }

    public function testConfiguratorsRunInRegistrationOrderWithoutDeduplication(): void
    {
        $calls = [];
        $first = $this->configurator('first');
        $second = $this->configurator('second');
        $first->applyCallback = static function () use (&$calls): void {
            $calls[] = 'first';
        };
        $second->applyCallback = static function () use (&$calls): void {
            $calls[] = 'second';
        };

        Connection::configureSessionUsing($first);
        Connection::configureSessionUsing($second);
        Connection::configureSessionUsing($first);

        $this->connection()->getPdo();

        $this->assertSame(['first', 'second', 'first'], $calls);
        $this->assertSame(2, $first->stateCalls);
        $this->assertSame(2, $first->applyCalls);
        $this->assertSame(1, $second->stateCalls);
        $this->assertSame(1, $second->applyCalls);
    }

    public function testFlushStateRemovesConfiguratorsAndPhysicalState(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection();

        $connection->getPdo();

        $this->assertSame(1, TestSessionConnection::physicalSessionStateCount());

        Connection::flushState();

        $this->assertNull(TestSessionConnection::physicalSessionStateCount());
        $connection->getPdo();
        $this->assertSame(1, $configurator->stateCalls);
        $this->assertSame(1, $configurator->applyCalls);
        $this->assertNull(TestSessionConnection::physicalSessionStateCount());
    }

    public function testNullSkipsAndEmptyStringIsMemoizedAsARealState(): void
    {
        $skipped = $this->configurator(null);
        $empty = $this->configurator('');
        Connection::configureSessionUsing($skipped);
        Connection::configureSessionUsing($empty);
        $connection = $this->connection();

        $connection->getPdo();
        $connection->getPdo();

        $this->assertSame(2, $skipped->stateCalls);
        $this->assertSame(0, $skipped->applyCalls);
        $this->assertSame(2, $empty->stateCalls);
        $this->assertSame(1, $empty->applyCalls);
        $this->assertSame([''], $empty->appliedStates);
    }

    public function testMatchingStateSkipsApplyAndChangedStateReplacesTheMemo(): void
    {
        $configurator = $this->configurator('first');
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection();

        $connection->getPdo();
        $connection->getPdo();
        $configurator->desiredState = 'second';
        $connection->getPdo();
        $connection->getPdo();

        $this->assertSame(4, $configurator->stateCalls);
        $this->assertSame(2, $configurator->applyCalls);
        $this->assertSame(['first', 'second'], $configurator->appliedStates);
    }

    public function testMultipleConfiguratorsMemoizeTheirStatesIndependently(): void
    {
        $first = $this->configurator('first');
        $second = $this->configurator('second');
        Connection::configureSessionUsing($first);
        Connection::configureSessionUsing($second);
        $connection = $this->connection();

        $connection->getPdo();
        $second->desiredState = 'changed';
        $connection->getPdo();

        $this->assertSame(1, $first->applyCalls);
        $this->assertSame(2, $second->applyCalls);
        $this->assertSame(['second', 'changed'], $second->appliedStates);
    }

    public function testReadAndWritePdosAreMemoizedIndependently(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $writePdo = $this->pdo();
        $readPdo = $this->pdo();
        $connection = $this->connection($writePdo);
        $connection->setReadPdo($readPdo);

        $this->assertSame($writePdo, $connection->getPdo());
        $this->assertSame($readPdo, $connection->getReadPdo());
        $connection->getPdo();
        $connection->getReadPdo();

        $this->assertSame(4, $configurator->stateCalls);
        $this->assertSame(2, $configurator->applyCalls);
        $this->assertSame(2, TestSessionConnection::physicalSessionStateCount());
    }

    public function testReadFallbackAndMultipleWrappersShareThePhysicalMemo(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $pdo = $this->pdo();
        $connection = $this->connection($pdo);
        $secondConnection = $this->connection($pdo);

        $this->assertSame($pdo, $connection->getReadPdo());
        $this->assertSame($pdo, $secondConnection->getPdo());

        $clonedConnection = clone $connection;
        $this->assertSame($pdo, $clonedConnection->getPdo());
        $this->assertSame(3, $configurator->stateCalls);
        $this->assertSame(1, $configurator->applyCalls);
        $this->assertSame(1, TestSessionConnection::physicalSessionStateCount());
    }

    public function testWeakMapReleasesStateWithThePhysicalPdo(): void
    {
        Connection::configureSessionUsing($this->configurator());
        $pdo = $this->pdo();
        $connection = $this->connection($pdo);
        $connection->getPdo();

        $this->assertSame(1, TestSessionConnection::physicalSessionStateCount());

        unset($connection, $pdo);
        gc_collect_cycles();

        $this->assertSame(0, TestSessionConnection::physicalSessionStateCount());
    }

    public function testRawAccessAndInternalResolutionDoNotSynchronize(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $writeResolutions = 0;
        $readResolutions = 0;
        $writePdo = $this->pdo();
        $readPdo = $this->pdo();
        $connection = $this->connection(function () use (&$writeResolutions, $writePdo): PDO {
            ++$writeResolutions;

            return $writePdo;
        });
        $connection->setReadPdo(function () use (&$readResolutions, $readPdo): PDO {
            ++$readResolutions;

            return $readPdo;
        });

        $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
        $this->assertInstanceOf(Closure::class, $connection->getRawReadPdo());
        $this->assertSame($writePdo, $connection->resolveWritePdo());
        $this->assertSame($readPdo, $connection->resolveReadPdoForTest());
        $this->assertSame(1, $writeResolutions);
        $this->assertSame(1, $readResolutions);
        $this->assertSame(0, $configurator->stateCalls);
        $this->assertNull(TestSessionConnection::physicalSessionStateCount());

        $connection->getPdo();
        $connection->getReadPdo();

        $this->assertSame(2, $configurator->applyCalls);
    }

    public function testRetainedPdoIsAnExplicitUnsynchronizedEscapeHatch(): void
    {
        $configurator = $this->configurator('first');
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection();
        $retainedPdo = $connection->getPdo();

        $configurator->desiredState = 'second';
        $retainedPdo->query('select 1')?->closeCursor();

        $this->assertSame(1, $configurator->applyCalls);

        $connection->getPdo();

        $this->assertSame(2, $configurator->applyCalls);
    }

    public function testStateExceptionDoesNotTaintThePhysicalSession(): void
    {
        $configurator = $this->configurator();
        $exception = new Exception('State failed.');
        $configurator->stateCallback = static fn () => throw $exception;
        Connection::configureSessionUsing($configurator);
        $pdo = $this->pdo();
        $connection = $this->connection($pdo);

        try {
            $connection->getPdo();
            $this->fail('Expected state exception was not thrown.');
        } catch (Exception $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertFalse(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
        $configurator->stateCallback = null;
        $this->assertSame($pdo, $connection->getPdo());
        $this->assertSame(1, $configurator->applyCalls);
    }

    public function testApplyFailureClearsAllMemosAndTaintsThePhysicalSession(): void
    {
        $first = $this->configurator('first');
        $second = $this->configurator('second');
        $exception = new Exception('Apply failed.');
        $second->applyCallback = static fn () => throw $exception;
        Connection::configureSessionUsing($first);
        Connection::configureSessionUsing($second);
        $pdo = $this->pdo();
        $connection = $this->connection($pdo);

        try {
            $connection->getPdo();
            $this->fail('Expected apply exception was not thrown.');
        } catch (Exception $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(1, $first->applyCalls);
        $this->assertSame(1, $second->applyCalls);
        $this->assertSame([], TestSessionConnection::appliedStatesForTest($pdo));
        $this->assertTrue(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
    }

    public function testReentrantConfigurationFailsClosedForTheSameConnection(): void
    {
        $configurator = $this->configurator();
        $configurator->applyCallback = static function (PDO $pdo, string $state, Connection $connection): void {
            $connection->getPdo();
        };
        Connection::configureSessionUsing($configurator);
        $pdo = $this->pdo();
        $connection = $this->connection($pdo);

        try {
            $connection->getPdo();
            $this->fail('Expected reentry exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Reentrant database session configuration is not allowed.', $exception->getMessage());
        }

        $this->assertTrue(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
    }

    public function testReentrantConfigurationFailsClosedAcrossWrappersSharingAPdo(): void
    {
        $pdo = $this->pdo();
        $otherConnection = $this->connection($pdo);
        $configurator = $this->configurator();
        $configurator->applyCallback = static function () use ($otherConnection): void {
            $otherConnection->getPdo();
        };
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reentrant database session configuration is not allowed.');

        try {
            $connection->getPdo();
        } finally {
            $this->assertTrue(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
        }
    }

    public function testUnknownWriteSessionIsReplacedOnceAndTheReplacementIsConfigured(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $oldPdo = $this->pdo();
        $newPdo = $this->pdo();
        $connection = $this->connection($oldPdo);
        $connection->getPdo();
        $connection->markSessionStateUnknownForTest($oldPdo);
        $reconnects = 0;
        $connection->setReconnector(static function (Connection $connection) use (&$reconnects, $newPdo): void {
            ++$reconnects;
            $connection->setPdo($newPdo);
        });

        $this->assertSame($newPdo, $connection->getPdo());
        $this->assertSame(1, $reconnects);
        $this->assertSame(2, $configurator->applyCalls);
        $this->assertFalse(TestSessionConnection::sessionStateIsUnknownForTest($newPdo));
    }

    public function testUnknownReadSessionRecoveryKeepsTheReadRoute(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $writePdo = $this->pdo();
        $oldReadPdo = $this->pdo();
        $newReadPdo = $this->pdo();
        $connection = $this->connection($writePdo);
        $connection->setReadPdo($oldReadPdo);
        $connection->getReadPdo();
        $connection->markSessionStateUnknownForTest($oldReadPdo);
        $connection->setReconnector(static function (Connection $connection) use ($newReadPdo): void {
            $connection->setReadPdo($newReadPdo);
        });

        $this->assertSame($newReadPdo, $connection->getReadPdo());
        $this->assertSame($writePdo, $connection->getRawPdo());
        $this->assertSame(2, $configurator->applyCalls);
    }

    public function testUnknownReadFallbackRecoveryUsesTheReplacementWritePdo(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $oldPdo = $this->pdo();
        $newPdo = $this->pdo();
        $connection = $this->connection($oldPdo);
        $connection->getReadPdo();
        $connection->markSessionStateUnknownForTest($oldPdo);
        $connection->setReconnector(static function (Connection $connection) use ($newPdo): void {
            $connection->setPdo($newPdo);
        });

        $this->assertSame($newPdo, $connection->getReadPdo());
        $this->assertSame($newPdo, $connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
    }

    public function testUnknownSessionThatSurvivesReconnectFailsAfterOneAttempt(): void
    {
        Connection::configureSessionUsing($this->configurator());
        $pdo = $this->pdo();
        $connection = $this->connection($pdo);
        $connection->getPdo();
        $connection->markSessionStateUnknownForTest($pdo);
        $reconnects = 0;
        $connection->setReconnector(static function () use (&$reconnects): void {
            ++$reconnects;
        });

        try {
            $connection->getPdo();
            $this->fail('Expected unknown session exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Database session state remains unknown after reconnecting.', $exception->getMessage());
        }

        $this->assertSame(1, $reconnects);
    }

    public function testReentrantReconnectorCannotRecursivelyReplaceAnUnknownSession(): void
    {
        Connection::configureSessionUsing($this->configurator());
        $pdo = $this->pdo();
        $connection = $this->connection($pdo);
        $connection->getPdo();
        $connection->markSessionStateUnknownForTest($pdo);
        $reconnects = 0;
        $connection->setReconnector(static function (Connection $connection) use (&$reconnects): void {
            ++$reconnects;
            $connection->getPdo();
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reentrant database session configuration is not allowed.');

        try {
            $connection->getPdo();
        } finally {
            $this->assertSame(1, $reconnects);
            $this->assertTrue(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
        }
    }

    public function testUnknownSessionInsideTransactionFailsWithoutReconnect(): void
    {
        Connection::configureSessionUsing($this->configurator());
        $pdo = $this->pdo();
        $connection = $this->connection($pdo);
        $connection->beginTransaction();
        $connection->markSessionStateUnknownForTest($pdo);
        $reconnects = 0;
        $connection->setReconnector(static function () use (&$reconnects): void {
            ++$reconnects;
        });

        try {
            $connection->getPdo();
            $this->fail('Expected active transaction exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Database session state is unknown within an active transaction.', $exception->getMessage());
        } finally {
            $connection->rollBack();
        }

        $this->assertSame(0, $reconnects);
    }

    public function testUnknownSessionWithoutAReconnectorPreservesTheExistingFailure(): void
    {
        Connection::configureSessionUsing($this->configurator());
        $pdo = $this->pdo();
        $connection = $this->connection($pdo);
        $connection->getPdo();
        $connection->markSessionStateUnknownForTest($pdo);

        $this->expectException(LostConnectionException::class);
        $this->expectExceptionMessage('Lost connection and no reconnector available.');

        $connection->getPdo();
    }

    public function testConfigurationFailureIsWrappedForTheApplicationQuery(): void
    {
        $configurator = $this->configurator();
        $configurationException = new Exception('Configuration failed.');
        $configurator->applyCallback = static fn () => throw $configurationException;
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection();

        try {
            $connection->statement('select ?', [1]);
            $this->fail('Expected query exception was not thrown.');
        } catch (QueryException $exception) {
            $this->assertSame('select ?', $exception->getSql());
            $this->assertSame([1], $exception->getBindings());
            $this->assertSame($configurationException, $exception->getPrevious());
        }

        $this->assertSame(1, $connection->getErrorCount());
        $this->assertSame([], $connection->getQueryLog());
    }

    public function testDirectGetterFailureIsUnwrappedAndDoesNotIncrementQueryErrors(): void
    {
        $configurator = $this->configurator();
        $configurationException = new Exception('Configuration failed.');
        $configurator->applyCallback = static fn () => throw $configurationException;
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection();

        try {
            $connection->getPdo();
            $this->fail('Expected configuration exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($configurationException, $exception);
        }

        $this->assertSame(0, $connection->getErrorCount());
    }

    public function testLostConnectionDuringConfigurationUsesTheExistingQueryRetry(): void
    {
        $configurator = $this->configurator();
        $configurator->applyCallback = static function () use ($configurator): void {
            if ($configurator->applyCalls === 1) {
                throw new PDOException('server has gone away');
            }
        };
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection($this->pdo());
        $replacement = $this->pdo();
        $reconnects = 0;
        $connection->setReconnector(static function (Connection $connection) use (&$reconnects, $replacement): void {
            ++$reconnects;
            $connection->setPdo($replacement);
        });

        $this->assertTrue($connection->statement('select 1'));
        $this->assertSame(1, $reconnects);
        $this->assertSame(2, $configurator->stateCalls);
        $this->assertSame(2, $configurator->applyCalls);
        $this->assertSame(1, $connection->getErrorCount());
    }

    public function testLostConnectionDuringConfigurationIsNotRetriedInsideATransaction(): void
    {
        $configurator = $this->configurator('first');
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection();
        $connection->beginTransaction();
        $configurator->desiredState = 'second';
        $configurator->applyCallback = static fn () => throw new PDOException('server has gone away');
        $reconnects = 0;
        $connection->setReconnector(static function () use (&$reconnects): void {
            ++$reconnects;
        });

        try {
            $connection->statement('select 1');
            $this->fail('Expected query exception was not thrown.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(PDOException::class, $exception->getPrevious());
        } finally {
            $connection->rollBack();
        }

        $this->assertSame(0, $reconnects);
    }

    public function testLostConnectionDuringConfigurationUsesTheExistingBeginRetry(): void
    {
        $configurator = $this->configurator();
        $configurator->applyCallback = static function () use ($configurator): void {
            if ($configurator->applyCalls === 1) {
                throw new PDOException('server has gone away');
            }
        };
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection($this->pdo());
        $replacement = $this->pdo();
        $reconnects = 0;
        $connection->setReconnector(static function (Connection $connection) use (&$reconnects, $replacement): void {
            ++$reconnects;
            $connection->setPdo($replacement);
        });

        $connection->beginTransaction();

        $this->assertSame(1, $reconnects);
        $this->assertSame(2, $configurator->applyCalls);
        $this->assertTrue($replacement->inTransaction());
        $connection->rollBack();
    }

    public function testDirectGetterDoesNotRetryItsCurrentConfigurationFailure(): void
    {
        $configurator = $this->configurator();
        $configurator->applyCallback = static fn () => throw new PDOException('server has gone away');
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection();
        $reconnects = 0;
        $connection->setReconnector(static function () use (&$reconnects): void {
            ++$reconnects;
        });

        try {
            $connection->getPdo();
            $this->fail('Expected PDO exception was not thrown.');
        } catch (PDOException $exception) {
            $this->assertSame('server has gone away', $exception->getMessage());
        }

        $this->assertSame(0, $reconnects);
    }

    public function testSuccessfulCommitPreservesThePhysicalMemo(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection();

        $connection->beginTransaction();
        $stateCallsBeforeCommit = $configurator->stateCalls;
        $connection->commit();
        $connection->getPdo();

        $this->assertSame($stateCallsBeforeCommit, $configurator->stateCalls - 1);
        $this->assertSame(1, $configurator->applyCalls);
    }

    public function testSuccessfulTransactionCallbackCommitPreservesThePhysicalMemo(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection();

        $connection->transaction(static fn () => null);
        $stateCallsAfterCommit = $configurator->stateCalls;
        $connection->getPdo();

        $this->assertSame($stateCallsAfterCommit + 1, $configurator->stateCalls);
        $this->assertSame(1, $configurator->applyCalls);
    }

    public function testFullAndSavepointRollbackInvalidateThePhysicalMemo(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $connection = $this->connection();

        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->assertSame(1, $configurator->stateCalls);
        $connection->rollBack(1);
        $this->assertSame(1, $configurator->stateCalls);
        $connection->getPdo();
        $this->assertSame(2, $configurator->applyCalls);
        $connection->rollBack();
        $this->assertSame(2, $configurator->stateCalls);
        $connection->getPdo();
        $this->assertSame(3, $configurator->applyCalls);
    }

    public function testInvalidRollbackLevelDoesNotResolveOrSynchronizeAPdo(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $resolutions = 0;
        $pdo = $this->pdo();
        $connection = $this->connection(function () use (&$resolutions, $pdo): PDO {
            ++$resolutions;

            return $pdo;
        });

        $connection->rollBack();
        $connection->rollBack(1);

        $this->assertSame(0, $resolutions);
        $this->assertSame(0, $configurator->stateCalls);
        $this->assertNull(TestSessionConnection::physicalSessionStateCount());
    }

    public function testConcurrencyCommitFailureInvalidatesWithoutTaintingBeforeRetry(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $pdo = new CommitRetryPdo;
        $connection = $this->connection($pdo);

        $connection->transaction(static fn () => null, 2);

        $this->assertSame(2, $pdo->beginCalls);
        $this->assertSame(2, $pdo->commitCalls);
        $this->assertSame(2, $configurator->applyCalls);
        $this->assertFalse(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
    }

    public function testNestedDriverOwnedRollbackInvalidatesWithoutIssuingAnotherRollback(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $pdo = new TrackingPdo;
        $connection = $this->connection($pdo);
        $connection->getPdo();
        $connection->setTransactionLevelForTest(2);
        $exception = new QueryException('test', 'select 1', [], new Exception('Deadlock found when trying to get lock'));

        try {
            $connection->handleTransactionExceptionForTest($exception);
            $this->fail('Expected deadlock exception was not thrown.');
        } catch (DeadlockException $caught) {
            $this->assertSame($exception, $caught->getPrevious());
        }

        $this->assertSame(0, $pdo->rollbackCalls);
        $this->assertSame([], TestSessionConnection::appliedStatesForTest($pdo));
        $this->assertFalse(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
    }

    public function testNonLostCommitFailureMarksThePhysicalSessionUnknown(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $pdo = new FailingCommitPdo;
        $connection = $this->connection($pdo);
        $connection->beginTransaction();

        try {
            $connection->commit();
            $this->fail('Expected commit exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame(FailingCommitPdo::$exception, $exception);
        }

        $this->assertSame([], TestSessionConnection::appliedStatesForTest($pdo));
        $this->assertTrue(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
    }

    public function testLostCommitFailureInvalidatesWithoutTaintingTheDeadPdo(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $pdo = new LostCommitPdo;
        $connection = $this->connection($pdo);
        $connection->beginTransaction();

        try {
            $connection->commit();
            $this->fail('Expected commit exception was not thrown.');
        } catch (PDOException $exception) {
            $this->assertSame('server has gone away', $exception->getMessage());
        }

        $this->assertSame([], TestSessionConnection::appliedStatesForTest($pdo));
        $this->assertFalse(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
    }

    public function testNonLostRollbackFailureInvalidatesAndTaintsThePhysicalSession(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $pdo = new FailingRollbackPdo;
        $connection = $this->connection($pdo);
        $connection->beginTransaction();

        try {
            $connection->rollBack();
            $this->fail('Expected rollback exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame(FailingRollbackPdo::$exception, $exception);
        }

        $this->assertSame([], TestSessionConnection::appliedStatesForTest($pdo));
        $this->assertTrue(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
        $this->assertSame(1, $connection->transactionLevel());
    }

    public function testLostRollbackFailureInvalidatesWithoutTaintingTheDeadPdo(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $pdo = new LostRollbackPdo;
        $connection = $this->connection($pdo);
        $connection->beginTransaction();

        try {
            $connection->rollBack();
            $this->fail('Expected rollback exception was not thrown.');
        } catch (PDOException $exception) {
            $this->assertSame('server has gone away', $exception->getMessage());
        }

        $this->assertSame([], TestSessionConnection::appliedStatesForTest($pdo));
        $this->assertFalse(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
        $this->assertSame(0, $connection->transactionLevel());
    }

    public function testDisconnectDoesNotResolveLazyPdosWithoutATransaction(): void
    {
        $writeResolutions = 0;
        $readResolutions = 0;
        $connection = $this->connection(function () use (&$writeResolutions): PDO {
            ++$writeResolutions;

            return $this->pdo();
        });
        $connection->setReadPdo(function () use (&$readResolutions): PDO {
            ++$readResolutions;

            return $this->pdo();
        });

        $connection->disconnect();

        $this->assertSame(0, $writeResolutions);
        $this->assertSame(0, $readResolutions);
        $this->assertNull($connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
    }

    public function testDisconnectRollbackInvalidatesStateRetainedByAnotherWrapper(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $pdo = $this->pdo();
        $connection = $this->connection($pdo);
        $otherConnection = $this->connection($pdo);
        $connection->beginTransaction();

        $connection->disconnect();
        $otherConnection->getPdo();

        $this->assertSame(2, $configurator->applyCalls);
        $this->assertNull($connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
    }

    public function testFailedDisconnectRollbackTaintsStateAndDropsWrapperReferences(): void
    {
        $configurator = $this->configurator();
        Connection::configureSessionUsing($configurator);
        $pdo = new FailingRollbackPdo;
        $connection = $this->connection($pdo);
        $connection->beginTransaction();
        $readPdo = $this->pdo();
        $connection->setReadPdo($readPdo);

        try {
            $connection->disconnect();
            $this->fail('Expected rollback exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame(FailingRollbackPdo::$exception, $exception);
        }

        $this->assertTrue(TestSessionConnection::sessionStateIsUnknownForTest($pdo));
        $this->assertSame([], TestSessionConnection::appliedStatesForTest($pdo));
        $this->assertNull($connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
    }

    private function connection(PDO|Closure|null $pdo = null): TestSessionConnection
    {
        return new TestSessionConnection(
            $pdo ?? $this->pdo(),
            'test_database',
            '',
            ['name' => 'test', 'driver' => 'sqlite']
        );
    }

    private function configurator(?string $state = 'state'): RecordingSessionConfigurator
    {
        return new RecordingSessionConfigurator($state);
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:');
    }
}

class RecordingSessionConfigurator implements SessionConfigurator
{
    public int $stateCalls = 0;

    public int $applyCalls = 0;

    /**
     * @var string[]
     */
    public array $appliedStates = [];

    public ?Closure $stateCallback = null;

    public ?Closure $applyCallback = null;

    public function __construct(
        public ?string $desiredState,
    ) {
    }

    public function state(Connection $connection): ?string
    {
        ++$this->stateCalls;

        return $this->stateCallback instanceof Closure
            ? ($this->stateCallback)($connection)
            : $this->desiredState;
    }

    public function apply(PDO $pdo, string $state, Connection $connection): void
    {
        ++$this->applyCalls;
        $this->appliedStates[] = $state;

        if ($this->applyCallback instanceof Closure) {
            ($this->applyCallback)($pdo, $state, $connection);
        }
    }
}

class TestSessionConnection extends Connection
{
    public function resolveWritePdo(): PDO
    {
        return $this->resolvePdo();
    }

    public function resolveReadPdoForTest(): PDO
    {
        return $this->resolveReadPdo();
    }

    public function markSessionStateUnknownForTest(PDO $pdo): void
    {
        $this->markSessionStateUnknown($pdo);
    }

    public static function physicalSessionStateCount(): ?int
    {
        return static::$physicalSessionStates === null
            ? null
            : count(static::$physicalSessionStates);
    }

    public static function sessionStateIsUnknownForTest(PDO $pdo): bool
    {
        return static::sessionStateIsUnknown($pdo);
    }

    /**
     * @return array<int, string>
     */
    public static function appliedStatesForTest(PDO $pdo): array
    {
        return static::$physicalSessionStates[$pdo]->appliedStates ?? [];
    }

    public function setTransactionLevelForTest(int $level): void
    {
        $this->transactions = $level;
    }

    public function handleTransactionExceptionForTest(Throwable $exception): void
    {
        $this->handleTransactionException($exception, 1, 1);
    }
}

class CommitRetryPdo extends PDO
{
    public int $beginCalls = 0;

    public int $commitCalls = 0;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        ++$this->beginCalls;

        return true;
    }

    public function commit(): bool
    {
        ++$this->commitCalls;

        if ($this->commitCalls === 1) {
            throw new StringCodePdoException('Serialization failure', '40001');
        }

        return true;
    }
}

class TrackingPdo extends PDO
{
    public int $rollbackCalls = 0;

    public function __construct()
    {
    }

    public function rollBack(): bool
    {
        ++$this->rollbackCalls;

        return true;
    }
}

class FailingCommitPdo extends PDO
{
    public static Exception $exception;

    public function __construct()
    {
        static::$exception = new Exception('Commit failed.');
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        throw static::$exception;
    }
}

class FailingRollbackPdo extends PDO
{
    public static Exception $exception;

    public function __construct()
    {
        static::$exception = new Exception('Rollback failed.');
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        throw static::$exception;
    }
}

class LostCommitPdo extends PDO
{
    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        throw new PDOException('server has gone away');
    }
}

class LostRollbackPdo extends PDO
{
    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        throw new PDOException('server has gone away');
    }
}

class StringCodePdoException extends PDOException
{
    public function __construct(string $message, string $code)
    {
        $this->message = $message;
        $this->code = $code;
    }
}
