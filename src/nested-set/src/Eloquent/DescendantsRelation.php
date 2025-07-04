<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\Constraint;

class DescendantsRelation extends BaseRelation
{
    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        if (! Constraint::isConstraint()) {
            return;
        }

        /* @phpstan-ignore-next-line */
        $this->query->whereDescendantOf($this->parent)
            ->applyNestedSetScope();
    }

    protected function addEagerConstraint(QueryBuilder $query, Model $model): void
    {
        $query->orWhereDescendantOf($model);
    }

    protected function matches(Model $model, Model $related): bool
    {
        /* @phpstan-ignore-next-line */
        return $related->isDescendantOf($model);
    }

    protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string
    {
        return "{$hash}.{$lft} between {$table}.{$lft} + 1 and {$table}.{$rgt}";
    }
}
