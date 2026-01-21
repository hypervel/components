<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema\Grammars;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Fluent;

class MariaDbGrammar extends MySqlGrammar
{
    #[\Override]
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command): array|string
    {
        if (version_compare($this->connection->getServerVersion(), '10.5.2', '<')) {
            return $this->compileLegacyRenameColumn($blueprint, $command);
        }

        return parent::compileRenameColumn($blueprint, $command);
    }

    /**
     * Create the column definition for a uuid type.
     */
    protected function typeUuid(Fluent $column): string
    {
        if (version_compare($this->connection->getServerVersion(), '10.7.0', '<')) {
            return 'char(36)';
        }

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

        return sprintf('%s%s',
            $subtype ?? 'geometry',
            $column->srid ? ' ref_system_id='.$column->srid : ''
        );
    }

    /**
     * Wrap the given JSON selector.
     */
    protected function wrapJsonSelector(string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_value('.$field.$path.')';
    }
}
