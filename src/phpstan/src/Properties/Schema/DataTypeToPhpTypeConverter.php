<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties\Schema;

use Hypervel\PHPStan\Properties\SchemaConnection;
use Hypervel\SchemaParser\ColumnType;
use Hypervel\SchemaParser\Domain;
use Hypervel\SchemaParser\EnumType;
use PDO;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ResourceType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;

use function array_key_exists;
use function array_map;
use function in_array;
use function str_contains;

/**
 * Maps database column types to the PHP types PDO fetches and accepts, for each driver.
 *
 * The dump, raw SQL and Blueprint paths all describe columns with schema-parser types, so one
 * mapping serves them. Explicit casts, dates and accessors take precedence over these types.
 */
#[AutowiredService]
class DataTypeToPhpTypeConverter
{
    /** PostgreSQL built-in types fetched as text, by pg_catalog name. */
    private const array POSTGRES_TEXT_TYPES = [
        'bit', 'bpchar', 'box', 'char', 'cidr', 'circle', 'date', 'daterange', 'inet', 'int4range', 'int8range',
        'interval', 'json', 'jsonb', 'line', 'lseg', 'macaddr', 'macaddr8', 'money', 'name', 'numrange', 'path',
        'point', 'polygon', 'text', 'time', 'timestamp', 'timestamptz', 'timetz', 'tsquery', 'tsrange',
        'tstzrange', 'tsvector', 'uuid', 'varbit', 'varchar', 'xml',
    ];

    /**
     * Get the types a column reads and accepts, before nullability and fetch options.
     *
     * @param list<string> $options the values a CHECK constraint from Blueprint's enum() allows
     * @param list<string> $searchPath the schemas an unqualified PostgreSQL type name resolves through
     *
     * @return array{readable: Type, writable: Type}
     */
    public function convert(string $driver, ColumnType $type, array $options, SchemaConnection $schema, array $searchPath): array
    {
        if ($options !== []) {
            $labels = $this->labels($options);

            return ['readable' => $labels, 'writable' => $labels];
        }

        return match ($driver) {
            'mysql', 'mariadb' => $this->convertMySqlType($type),
            'pgsql' => $this->convertPostgresType($type, $schema, $searchPath),
            'sqlite' => $this->convertSQLiteType($type),
            default => $this->unknown(),
        };
    }

    /**
     * Apply the connection's fetch options to a fetched type, as the union over its distinct option sets.
     *
     * @param list<array<int, mixed>> $fetchOptions
     */
    public function applyFetchOptions(Type $type, string $driver, array $fetchOptions): Type
    {
        $types = [];

        foreach ($fetchOptions as $options) {
            $fetched = $type;

            if (($options[PDO::ATTR_STRINGIFY_FETCHES] ?? false) === true) {
                $fetched = $this->stringify($fetched, $driver);
            }

            $fetched = match ($options[PDO::ATTR_ORACLE_NULLS] ?? PDO::NULL_NATURAL) {
                // remove() also subtracts null from mixed, which removeNull() leaves alone.
                PDO::NULL_TO_STRING => $fetched->isNull()->no()
                    ? $fetched
                    : TypeCombinator::union(TypeCombinator::remove($fetched, new NullType), new ConstantStringType('')),
                PDO::NULL_EMPTY_STRING => $fetched->isSuperTypeOf(new ConstantStringType(''))->no()
                    ? $fetched
                    : TypeCombinator::addNull($fetched),
                default => $fetched,
            };

            $types[] = $fetched;
        }

        return $types === [] ? $type : TypeCombinator::union(...$types);
    }

    /**
     * Get the types of a MySQL or MariaDB column.
     *
     * @return array{readable: Type, writable: Type}
     */
    private function convertMySqlType(ColumnType $type): array
    {
        $unsignedInteger = IntegerRangeType::fromInterval(0, null);
        $unsignedBigInteger = TypeCombinator::union($unsignedInteger, $this->numericString());

        $readable = match ($type->name) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer' => $type->unsigned ? $unsignedInteger : new IntegerType,
            // Values above PHP_INT_MAX fetch as strings.
            'bigint' => $type->unsigned ? $unsignedBigInteger : new IntegerType,
            // Unlike upstream's string type, BIT columns fetch as integers.
            'bit' => ($type->modifiers[0] ?? '1') === '64' ? $unsignedBigInteger : $unsignedInteger,
            'bool', 'boolean', 'year' => new IntegerType,
            // Unlike upstream's float type, decimals fetch as strings to keep their precision.
            'decimal', 'dec', 'numeric', 'fixed' => $this->numericString(),
            'float', 'double', 'double precision', 'real' => new FloatType,
            'enum' => $this->labels($type->values),
            // Unlike upstream's labels, a set holds a comma-joined combination of them.
            'char', 'varchar', 'character', 'character varying', 'nchar', 'nvarchar', 'tinytext', 'text',
            'mediumtext', 'longtext', 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'json',
            'date', 'datetime', 'timestamp', 'time', 'set', 'uuid', 'inet4', 'inet6' => new StringType,
            default => new MixedType,
        };

        return [
            'readable' => $readable,
            'writable' => match (true) {
                in_array($type->name, ['decimal', 'dec', 'numeric', 'fixed'], true) => $this->decimalInput($this->numericString()),
                $this->isBooleanColumn($type) => TypeCombinator::union(new BooleanType, new IntegerType),
                default => $readable,
            },
        ];
    }

    /**
     * Get the types of a PostgreSQL column, resolving user enums and domains.
     *
     * A domain resolves through the connection's type facts, not the base the parser embedded,
     * so a definition the facts hold as uncertain is never bypassed.
     *
     * @param list<string> $searchPath
     *
     * @return array{readable: Type, writable: Type}
     */
    private function convertPostgresType(ColumnType $type, SchemaConnection $schema, array $searchPath): array
    {
        // PDO fetches arrays, including arrays of a domain, as their text representation.
        if ($type->isArray()) {
            return ['readable' => new StringType, 'writable' => new StringType];
        }

        // pg_catalog is searched first unless the search path names it explicitly.
        $schemas = $type->schema !== null
            ? [$type->schema]
            : (in_array('pg_catalog', $searchPath, true) ? $searchPath : ['pg_catalog', ...$searchPath]);

        // Raw migration SQL can define a type without a schema, in whichever path schema existed first.
        $unqualifiedKey = $schema->key(null, $type->name);
        $hasUnqualified = $type->schema === null && array_key_exists($unqualifiedKey, $schema->types);

        foreach ($schemas as $candidate) {
            if ($candidate === 'pg_catalog' && ($builtIn = $this->convertPostgresBuiltInType($type->name)) !== null) {
                return $builtIn;
            }

            $key = $schema->key($candidate, $type->name);

            // An uncertain definition ends the search, since the type may exist in this schema.
            if (array_key_exists($key, $schema->types)) {
                $userType = $schema->types[$key];

                return $userType === null || $hasUnqualified ? $this->unknown() : $this->convertPostgresUserType($userType, $schema, $searchPath);
            }
        }

        $unqualified = $hasUnqualified ? $schema->types[$unqualifiedKey] : null;

        return $unqualified === null ? $this->unknown() : $this->convertPostgresUserType($unqualified, $schema, $searchPath);
    }

    /**
     * Get the types of a PostgreSQL enum or domain.
     *
     * @param list<string> $searchPath
     *
     * @return array{readable: Type, writable: Type}
     */
    private function convertPostgresUserType(Domain|EnumType $userType, SchemaConnection $schema, array $searchPath): array
    {
        if ($userType instanceof EnumType) {
            $labels = $this->labels($userType->values);

            return ['readable' => $labels, 'writable' => $labels];
        }

        // A domain's NOT NULL does not stop outer joins from reading null, so only its base type is used.
        return $this->convertPostgresType($userType->type, $schema, $searchPath);
    }

    /**
     * Get the types of a PostgreSQL built-in type, or null when it is not one.
     *
     * @return null|array{readable: Type, writable: Type}
     */
    private function convertPostgresBuiltInType(string $name): ?array
    {
        $readable = match (true) {
            $name === 'bool' => new BooleanType,
            in_array($name, ['int2', 'int4', 'int8'], true) => new IntegerType,
            in_array($name, ['float4', 'float8'], true) => new FloatType,
            // Unlike upstream's float type, numeric fetches as a string, which can hold NaN and Infinity.
            $name === 'numeric' => new StringType,
            // Unlike upstream's string type, PDO fetches bytea as a stream.
            $name === 'bytea' => new ResourceType,
            in_array($name, self::POSTGRES_TEXT_TYPES, true) => new StringType,
            default => null,
        };

        return $readable === null ? null : [
            'readable' => $readable,
            'writable' => match ($name) {
                'numeric' => $this->decimalInput(new StringType),
                'bytea' => new StringType,
                default => $readable,
            },
        ];
    }

    /**
     * Get the types of a SQLite column from its declared type name.
     *
     * The types describe the values the framework writes, since SQLite stores any value in
     * a column unless the table is STRICT.
     *
     * @return array{readable: Type, writable: Type}
     */
    private function convertSQLiteType(ColumnType $type): array
    {
        $name = $type->name;

        // Type names follow SQLite's affinity rules, except those whose framework writes are strings or integers.
        $readable = match (true) {
            in_array($name, ['date', 'datetime', 'time', 'timestamp'], true) => new StringType,
            // Numeric affinity stores a JSON number as an integer or a real, which fetches as a PHP number.
            in_array($name, ['json', 'jsonb'], true) => TypeCombinator::union(new FloatType, new IntegerType, new StringType),
            in_array($name, ['bool', 'boolean'], true) || str_contains($name, 'int') => new IntegerType,
            str_contains($name, 'char') || str_contains($name, 'clob') || str_contains($name, 'text') => new StringType,
            str_contains($name, 'blob') => new StringType,
            str_contains($name, 'real') || str_contains($name, 'floa') || str_contains($name, 'doub') => new FloatType,
            // Unlike upstream's float type, numeric affinity stores whole numbers as integers.
            in_array($name, ['numeric', 'decimal'], true) => null,
            // Other names, and a column without a type, keep whatever value was written.
            default => new MixedType,
        };

        if ($readable === null) {
            return [
                'readable' => TypeCombinator::union(new FloatType, new IntegerType),
                'writable' => $this->decimalInput($this->numericString()),
            ];
        }

        return [
            'readable' => $readable,
            'writable' => $this->isBooleanColumn($type) ? TypeCombinator::union(new BooleanType, new IntegerType) : $readable,
        ];
    }

    /**
     * Determine whether an integer column holds a boolean, as Blueprint's boolean() declares it.
     */
    private function isBooleanColumn(ColumnType $type): bool
    {
        return in_array($type->name, ['bool', 'boolean'], true)
            || ($type->name === 'tinyint' && $type->modifiers === ['1']);
    }

    /**
     * Convert a fetched type to the strings ATTR_STRINGIFY_FETCHES returns.
     */
    private function stringify(Type $type, string $driver): Type
    {
        return TypeTraverser::map($type, function (Type $type, callable $traverse) use ($driver): Type {
            if ($type instanceof UnionType) {
                return $traverse($type);
            }

            return match (true) {
                $type->isInteger()->yes() => $this->numericString(),
                // MySQL doubles cannot hold NaN or infinity, unlike PostgreSQL and SQLite floats.
                $type->isFloat()->yes() => in_array($driver, ['mysql', 'mariadb'], true) ? $this->numericString() : new StringType,
                $type->isBoolean()->yes() => TypeCombinator::union(new ConstantStringType('0'), new ConstantStringType('1')),
                default => $type,
            };
        });
    }

    /**
     * Get the literal union of a column's allowed values.
     *
     * @param list<string> $values
     */
    private function labels(array $values): Type
    {
        return $values === []
            ? new StringType
            : TypeCombinator::union(...array_map(static fn (string $value): Type => new ConstantStringType($value), $values));
    }

    /**
     * Get the types a decimal column accepts besides the string it fetches.
     */
    private function decimalInput(Type $string): Type
    {
        return TypeCombinator::union($string, new IntegerType, new FloatType);
    }

    /**
     * Get the types of a column whose values are unknown.
     *
     * @return array{readable: Type, writable: Type}
     */
    private function unknown(): array
    {
        return ['readable' => new MixedType, 'writable' => new MixedType];
    }

    /**
     * Get the numeric-string type.
     */
    private function numericString(): Type
    {
        return new IntersectionType([new StringType, new AccessoryNumericStringType]);
    }
}
