<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;

class DatabasePresenceVerifier implements PresenceVerifierInterface
{
    /**
     * Create a new database presence verifier.
     */
    public function __construct(protected ConnectionResolverInterface $db)
    {
    }

    /**
     * Count the number of objects matching the resolved presence query.
     */
    public function getCount(PresenceQuery $query, mixed $value): int
    {
        $builder = $this->table($query)
            ->where($query->column, '=', $this->prepareValue($query->column, $value));

        return $this->addQueryConditions($builder, $query)->count();
    }

    /**
     * Count the distinct values matching the resolved presence query.
     */
    public function getMultiCount(PresenceQuery $query, array $values): int
    {
        $builder = $this->addWhereIn($this->table($query), $query->column, $values);

        return $this->addQueryConditions($builder, $query)->distinct()->count($query->column);
    }

    /**
     * Get the distinct existing candidate values for a batch.
     *
     * @return array<int, mixed>
     */
    public function getExistingValues(PresenceQuery $query, array $values): array
    {
        $builder = $this->addWhereIn($this->table($query), $query->column, $values);

        /** @var array<int, mixed> */
        return $this->addQueryConditions($builder, $query)->distinct()->pluck($query->column)->all();
    }

    /**
     * Add exclusions and predicates without storing invocation state on the verifier.
     */
    protected function addQueryConditions(Builder $builder, PresenceQuery $query): Builder
    {
        if ($query->excludeId !== null) {
            $builder->where($query->idColumn ?: 'id', '<>', $query->excludeId);
        }

        return $this->addConditions($builder, $query->conditions);
    }

    /**
     * Prepare the value being validated for comparison with its database column.
     */
    protected function prepareValue(string $column, mixed $value): mixed
    {
        // Unlike Laravel's value-only hook, the column keeps JSON booleans typed
        // through the same normalizer used by condition operands.
        return DatabaseCondition::prepareValue($column, $value);
    }

    /**
     * Add candidate comparisons while preserving native JSON booleans.
     */
    protected function addWhereIn(Builder $query, string $column, array $values): Builder
    {
        $values = array_map(fn (mixed $value): mixed => $this->prepareValue($column, $value), $values);

        return $query->whereIn($column, $values);
    }

    /**
     * Apply the declared predicates and explicit query callbacks.
     */
    protected function addConditions(Builder $query, DatabaseConditions $conditions): Builder
    {
        foreach ($conditions->all() as $condition) {
            if ($condition instanceof Closure) {
                $query->where($condition);
            } else {
                $this->addWhere($query, $condition);
            }
        }

        return $query;
    }

    /**
     * Apply one typed predicate without interpreting its value as a string token.
     */
    protected function addWhere(Builder $query, DatabaseCondition $condition): void
    {
        match ($condition->operator) {
            DatabaseConditionOperator::Equals => $query->where($condition->column, '=', $condition->value),
            DatabaseConditionOperator::NotEquals => $query->where($condition->column, '!=', $condition->value),
            DatabaseConditionOperator::IsNull => $query->whereNull($condition->column),
            DatabaseConditionOperator::IsNotNull => $query->whereNotNull($condition->column),
            DatabaseConditionOperator::In => $query->whereIn($condition->column, $condition->value),
            DatabaseConditionOperator::NotIn => $query->whereNotIn($condition->column, $condition->value),
        };
    }

    /**
     * Get a write-connection query builder for the requested table.
     */
    protected function table(PresenceQuery $query): Builder
    {
        return $this->db->connection($query->connection)->table($query->collection)->useWritePdo();
    }
}
