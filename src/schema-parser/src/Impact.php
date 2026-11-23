<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

/**
 * How an input's statements affect tables, views and user types that existed before it.
 *
 * A schema snapshot only describes the definitions an input contains. When the
 * input is a script applied to an existing schema, such as a raw migration
 * statement, the impact tells its consumer which earlier relations may no
 * longer exist or keep their columns, and which user types may no longer exist
 * or keep their definitions. It does not list index or comment changes.
 */
readonly class Impact
{
    /**
     * Create the impact of one input.
     *
     * @param list<TableReference> $relations the tables and views the input alters, drops or renames, including both names of a rename, whether or not it also defines them
     * @param bool $unknown whether a statement may have changed relations or types without naming them, such as a cascading drop, a type rename or a function call, or changed how later statements resolve names
     * @param list<TypeReference> $types the PostgreSQL enums and domains the input alters or drops without renaming them, whether or not it also defines them
     */
    public function __construct(
        public array $relations = [],
        public bool $unknown = false,
        public array $types = [],
    ) {
    }
}
