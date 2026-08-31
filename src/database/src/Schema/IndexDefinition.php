<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Support\Fluent;
use LogicException;

/**
 * @method $this algorithm(string $algorithm) Specify an algorithm for the index (MySQL/PostgreSQL)
 * @method $this deferrable(bool $value = true) Specify that the unique index is deferrable (PostgreSQL)
 * @method $this initiallyImmediate(bool $value = true) Specify the default time to check the unique index constraint (PostgreSQL)
 * @method $this language(string $language) Specify a language for the full text index (PostgreSQL)
 * @method $this lock(string $value) Specify the DDL lock mode for the index operation (MySQL)
 * @method $this nullsNotDistinct(bool $value = true) Specify that the null values should not be treated as distinct (PostgreSQL)
 * @method $this online(bool $value = true) Specify that index creation should not lock the table (PostgreSQL/SqlServer)
 */
class IndexDefinition extends Fluent
{
    /**
     * Limit the index to rows where the given column is not null.
     */
    public function whereNotNull(string $column): static
    {
        // Fluent stores the command type under name and the physical index name under index.
        if ($this->get('name') !== 'index') {
            throw new LogicException(
                'The [whereNotNull] modifier is only available for ordinary indexes.',
            );
        }

        return $this->set('whereNotNull', $column);
    }
}
