<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hypervel\Database\Eloquent\Model;

class AncestorsRelation extends BaseRelation
{
    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        if (! static::shouldAddConstraints()) {
            return;
        }

        /* @phpstan-ignore-next-line */
        $this->query->whereAncestorOf($this->parent)
            ->applyNestedSetScope();
    }

    protected function matches(Model $model, Model $related): bool
    {
        /* @phpstan-ignore-next-line */
        return $related->isAncestorOf($model);
    }

    protected function addEagerConstraint(QueryBuilder $query, Model $model): void
    {
        $query->orWhereAncestorOf($model);
    }

    protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string
    {
        $key = $this->getBaseQuery()->getGrammar()->wrap($this->parent->getKeyName());

        return "{$table}.{$rgt} between {$hash}.{$lft} and {$hash}.{$rgt} and {$table}.{$key} <> {$hash}.{$key}";
    }
}
