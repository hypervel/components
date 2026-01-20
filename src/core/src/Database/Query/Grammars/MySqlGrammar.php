<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hyperf\Database\Query\Builder;
use Hyperf\Database\Query\Grammars\MySqlGrammar as BaseMySqlGrammar;
use Hypervel\Database\Concerns\CompilesJsonPaths;

class MySqlGrammar extends BaseMySqlGrammar
{
    use CompilesJsonPaths;

    /**
     * Compile a "where like" clause.
     *
     * @param array<string, mixed> $where
     */
    protected function whereLike(Builder $query, array $where): string
    {
        $where['operator'] = $where['not'] ? 'not ' : '';
        $where['operator'] .= $where['caseSensitive'] ? 'like binary' : 'like';

        return $this->whereBasic($query, $where);
    }
}
