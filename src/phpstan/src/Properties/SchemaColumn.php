<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Hypervel\SchemaParser\ColumnType;

/** @see https://github.com/psalm/laravel-psalm-plugin/blob/master/src/SchemaColumn.php */
final class SchemaColumn
{
    /**
     * Create a new schema column instance.
     *
     * Unlike upstream's PHP type strings, the database type is kept, so the PHP types follow
     * the reading connection's driver and fetch options at lookup.
     *
     * @param null|ColumnType $type the database type, or null when it is unknown
     * @param list<string> $options the values a CHECK constraint from Blueprint's enum() allows
     */
    public function __construct(
        public string $name,
        public ?ColumnType $type,
        public bool $nullable = false,
        public array $options = [],
    ) {
    }
}
