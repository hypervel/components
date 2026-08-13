<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\ConcurrencyErrorDetector;
use Hypervel\Database\QueryException;
use Hypervel\Tests\TestCase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class ConcurrencyErrorDetectorTest extends TestCase
{
    #[DataProvider('concurrencyErrors')]
    public function testConcurrencyErrorsAreDetected(PDOException $exception): void
    {
        $this->assertTrue((new ConcurrencyErrorDetector)->causedByConcurrencyError($exception));
    }

    public static function concurrencyErrors(): array
    {
        return [
            'integer serialization failure' => [self::pdoException(40001)],
            'serialization failure' => [self::pdoException('40001')],
            'PostgreSQL deadlock' => [self::pdoException('40P01')],
            'PostgreSQL lock unavailable' => [self::pdoException('55P03')],
            'SQLite busy' => [self::pdoException('HY000', 5)],
            'SQLite locked' => [self::pdoException('HY000', 6)],
            'MySQL or MariaDB lock timeout' => [self::pdoException('HY000', 1205)],
        ];
    }

    public function testQueryExceptionPreservesDriverCodeDetection(): void
    {
        $exception = new QueryException(
            'mysql',
            'update records set value = 1',
            [],
            self::pdoException('HY000', 1205),
        );

        $this->assertTrue((new ConcurrencyErrorDetector)->causedByConcurrencyError($exception));
    }

    public function testExistingMessageFallbacksRemainSupported(): void
    {
        $exception = new RuntimeException('translated prefix: database is locked');

        $this->assertTrue((new ConcurrencyErrorDetector)->causedByConcurrencyError($exception));
    }

    public function testOrdinaryDatabaseExceptionsAreNotDetected(): void
    {
        $exception = self::pdoException('HY000');

        $this->assertFalse((new ConcurrencyErrorDetector)->causedByConcurrencyError($exception));
    }

    /**
     * Create a PDO exception with driver-provided error metadata.
     */
    private static function pdoException(int|string $code, ?int $driverCode = null): PDOException
    {
        return new class($code, $driverCode) extends PDOException {
            public function __construct(int|string $code, ?int $driverCode)
            {
                $this->code = $code;

                if ($driverCode !== null) {
                    $this->errorInfo = [(string) $code, $driverCode, 'Database error'];
                }
            }
        };
    }
}
