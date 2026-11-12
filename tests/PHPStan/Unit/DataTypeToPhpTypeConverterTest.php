<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\PHPStan\Properties\Schema\DataTypeToPhpTypeConverter;
use Hypervel\PHPStan\Properties\SchemaConnection;
use Hypervel\SchemaParser\ColumnType;
use Hypervel\SchemaParser\Domain;
use Hypervel\SchemaParser\EnumType;
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
        // PdoConnection::bindValues() binds a stream as a large object, so binary columns accept one.
        yield 'PostgreSQL bytea, fetched as a stream' => ['pgsql', new ColumnType('bytea'), 'resource', 'resource|string'];
        yield 'MySQL blob' => ['mysql', new ColumnType('mediumblob'), 'string', 'resource|string'];
        yield 'SQLite blob' => ['sqlite', new ColumnType('blob'), 'string', 'resource|string'];
        yield 'SQLite integer affinity before blob' => ['sqlite', new ColumnType('blobint'), 'int', 'int'];
        yield 'MySQL unsigned bigint, above PHP_INT_MAX as a string' => ['mysql', new ColumnType('bigint', unsigned: true), 'int<0, max>|numeric-string', 'int<0, max>|numeric-string'];
        yield 'MySQL bit(64)' => ['mysql', new ColumnType('bit', modifiers: ['64']), 'int<0, max>|numeric-string', 'int<0, max>|numeric-string'];
        yield 'MySQL bit' => ['mysql', new ColumnType('bit'), 'int<0, max>', 'int<0, max>'];
    }

    /**
     * A domain's unqualified base named a type when the domain was created, which the final search path may
     * no longer find.
     *
     * @param list<Domain|EnumType> $types
     * @param list<string> $searchPath
     */
    #[Test]
    #[DataProvider('domainBases')]
    public function itReadsADomainBaseOnlyWhenTheSearchPathStillFindsIt(array $types, ColumnType $type, array $searchPath, string $readable): void
    {
        $schema = new SchemaConnection('pgsql', 'pgsql');

        foreach ($types as $userType) {
            $schema->types[$schema->key($userType->schema, $userType->name)] = $userType;
        }

        $this->assertSame($readable, self::getContainer()->getByType(DataTypeToPhpTypeConverter::class)
            ->convert('pgsql', $type, [], $schema, $searchPath)['readable']
            ->describe(VerbosityLevel::precise()));
    }

    /** @return iterable<string, array{list<Domain|EnumType>, ColumnType, list<string>, string}> */
    public static function domainBases(): iterable
    {
        yield 'a later type in an earlier schema' => [
            [new Domain('code', new ColumnType('text'), 'a'), new Domain('label', new ColumnType('code'), 'b'), new Domain('code', new ColumnType('int4', 'pg_catalog'), 'b')],
            new ColumnType('label', 'b'),
            ['b', 'a'],
            'mixed',
        ];
        yield 'one schema' => [
            [new EnumType('status', ['draft', 'sent'], 'public'), new Domain('label', new ColumnType('status'), 'public')],
            new ColumnType('label'),
            ['public'],
            "'draft'|'sent'",
        ];
        yield 'a user type before a later pg_catalog' => [
            [new Domain('label', new ColumnType('text'), 'public'), new Domain('text', new ColumnType('int4', 'pg_catalog'), 'public')],
            new ColumnType('label'),
            ['public', 'pg_catalog'],
            'mixed',
        ];
        yield 'a built-in type after a later pg_catalog' => [
            [new Domain('label', new ColumnType('text'), 'public')],
            new ColumnType('label'),
            ['public', 'pg_catalog'],
            'string',
        ];
        yield 'a qualified base' => [
            [new Domain('code', new ColumnType('text'), 'a'), new Domain('label', new ColumnType('code', 'a'), 'b'), new Domain('code', new ColumnType('int4', 'pg_catalog'), 'b')],
            new ColumnType('label', 'b'),
            ['b', 'a'],
            'string',
        ];
        yield 'a cycle' => [
            [new Domain('first', new ColumnType('second'), 'public'), new Domain('second', new ColumnType('first'), 'public')],
            new ColumnType('first'),
            ['public'],
            'mixed',
        ];
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
