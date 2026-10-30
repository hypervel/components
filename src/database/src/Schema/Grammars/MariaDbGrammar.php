<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema\Grammars;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Fluent;

class MariaDbGrammar extends MySqlGrammar
{
    /**
     * Create the column definition for a uuid type.
     */
    protected function typeUuid(Fluent $column): string
    {
        return 'uuid';
    }

    /**
     * Create the column definition for a spatial Geometry type.
     */
    protected function typeGeometry(Fluent $column): string
    {
        $subtype = $column->subtype ? strtolower($column->subtype) : null;

        if (! in_array($subtype, ['point', 'linestring', 'polygon', 'geometrycollection', 'multipoint', 'multilinestring', 'multipolygon'])) {
            $subtype = null;
        }

        return sprintf(
            '%s%s',
            $subtype ?? 'geometry',
            $column->srid ? ' ref_system_id=' . $column->srid : ''
        );
    }

    /**
     * Compile a vector index key command.
     */
    public function compileVectorIndex(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s add %s %s(%s) %s%s',
            $this->wrapTable($blueprint),
            'vector index',
            $this->wrap($command->index),
            $this->columnize($command->columns),
            $command->operatorClass ?? '',
            $command->lock ? ', lock=' . $command->lock : ''
        );
    }

    /**
     * Compile a drop vector index command.
     */
    public function compileDropVectorIndex(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropIndex($blueprint, $command);
    }

    /**
     * Wrap the given JSON selector.
     */
    protected function wrapJsonSelector(string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_value(' . $field . $path . ')';
    }
}
