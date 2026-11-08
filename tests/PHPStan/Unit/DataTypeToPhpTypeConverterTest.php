<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\PHPStan\Properties\Schema\DataTypeToPhpTypeConverter;
use Hypervel\PHPStan\Properties\SchemaConnection;
use Hypervel\SchemaParser\ColumnType;
use PDO;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\BooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class DataTypeToPhpTypeConverterTest extends PHPStanTestCase
{
    #[Test]
    #[DataProvider('columnTypes')]
    public function itMapsColumnTypesToTheValuesPdoFetchesAndAccepts(string $driver, ColumnType $type, string $readable, string $writable): void
    {
        $types = self::getContainer()->getByType(DataTypeToPhpTypeConverter::class)
            ->convert($driver, $type, [], new SchemaConnection($driver, $driver), ['public']);

        $this->assertSame([$readable, $writable], [
            $types['readable']->describe(VerbosityLevel::precise()),
            $types['writable']->describe(VerbosityLevel::precise()),
        ]);
    }

    /** @return iterable<string, array{string, ColumnType, string, string}> */
    public static function columnTypes(): iterable
    {
        yield 'PostgreSQL bytea, fetched as a stream' => ['pgsql', new ColumnType('bytea'), 'resource', 'string'];
        yield 'MySQL unsigned bigint, above PHP_INT_MAX as a string' => ['mysql', new ColumnType('bigint', unsigned: true), 'int<0, max>|numeric-string', 'int<0, max>|numeric-string'];
        yield 'MySQL bit(64)' => ['mysql', new ColumnType('bit', modifiers: ['64']), 'int<0, max>|numeric-string', 'int<0, max>|numeric-string'];
        yield 'MySQL bit' => ['mysql', new ColumnType('bit'), 'int<0, max>', 'int<0, max>'];
    }

    /**
     * @param list<array<int, mixed>> $fetchOptions
     */
    #[Test]
    #[DataProvider('fetchOptions')]
    public function itAppliesFetchOptions(string $driver, Type $type, array $fetchOptions, string $expected): void
    {
        $this->assertSame(
            $expected,
            self::getContainer()->getByType(DataTypeToPhpTypeConverter::class)
                ->applyFetchOptions($type, $driver, $fetchOptions)
                ->describe(VerbosityLevel::precise()),
        );
    }

    /** @return iterable<string, array{string, Type, list<array<int, mixed>>, string}> */
    public static function fetchOptions(): iterable
    {
        $stringify = [[PDO::ATTR_STRINGIFY_FETCHES => true]];

        yield 'stringified integer' => ['pgsql', new IntegerType, $stringify, 'numeric-string'];
        yield 'stringified MySQL double, never NaN' => ['mysql', new FloatType, $stringify, 'numeric-string'];
        yield 'stringified PostgreSQL float' => ['pgsql', new FloatType, $stringify, 'string'];
        yield 'stringified boolean' => ['pgsql', new BooleanType, $stringify, "'0'|'1'"];
        yield 'nulls fetched as empty strings' => ['mysql', TypeCombinator::addNull(new IntegerType), [[PDO::ATTR_ORACLE_NULLS => PDO::NULL_TO_STRING]], "''|int"];
        yield 'empty strings fetched as nulls' => ['mysql', new StringType, [[PDO::ATTR_ORACLE_NULLS => PDO::NULL_EMPTY_STRING]], 'string|null'];
        yield 'empty strings fetched as nulls, for a column without them' => ['mysql', new IntegerType, [[PDO::ATTR_ORACLE_NULLS => PDO::NULL_EMPTY_STRING]], 'int'];
        yield 'each option set a pool can select' => ['mysql', new IntegerType, [[PDO::ATTR_STRINGIFY_FETCHES => false], ...$stringify], 'int|numeric-string'];
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
