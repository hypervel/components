<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hyperf\Database\PgSQL\Query\Grammars\PostgresGrammar as BasePostgresGrammar;
use Hyperf\Database\Query\Builder;

class PostgresGrammar extends BasePostgresGrammar
{
    /**
     * Compile a "where like" clause.
     *
     * @param array<string, mixed> $where
     */
    protected function whereLike(Builder $query, array $where): string
    {
        $where['operator'] = $where['not'] ? 'not ' : '';
        $where['operator'] .= $where['caseSensitive'] ? 'like' : 'ilike';

        return $this->whereBasic($query, $where);
    }
}
