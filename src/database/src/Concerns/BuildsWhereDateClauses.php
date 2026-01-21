<?php

declare(strict_types=1);

namespace Hypervel\Database\Concerns;

use Carbon\Carbon;
use Hypervel\Support\Arr;

trait BuildsWhereDateClauses
{
    /**
     * Add a where clause to determine if a "date" column is in the past to the query.
     *
     * @return $this
     */
    public function wherePast(array|string $columns): static
    {
        return $this->wherePastOrFuture($columns, '<', 'and');
    }

    /**
     * Add a where clause to determine if a "date" column is in the past or now to the query.
     *
     * @return $this
     */
    public function whereNowOrPast(array|string $columns): static
    {
        return $this->wherePastOrFuture($columns, '<=', 'and');
    }

    /**
     * Add an "or where" clause to determine if a "date" column is in the past to the query.
     *
     * @return $this
     */
    public function orWherePast(array|string $columns): static
    {
        return $this->wherePastOrFuture($columns, '<', 'or');
    }

    /**
     * Add a where clause to determine if a "date" column is in the past or now to the query.
     *
     * @return $this
     */
    public function orWhereNowOrPast(array|string $columns): static
    {
        return $this->wherePastOrFuture($columns, '<=', 'or');
    }

    /**
     * Add a where clause to determine if a "date" column is in the future to the query.
     *
     * @return $this
     */
    public function whereFuture(array|string $columns): static
    {
        return $this->wherePastOrFuture($columns, '>', 'and');
    }

    /**
     * Add a where clause to determine if a "date" column is in the future or now to the query.
     *
     * @return $this
     */
    public function whereNowOrFuture(array|string $columns): static
    {
        return $this->wherePastOrFuture($columns, '>=', 'and');
    }

    /**
     * Add an "or where" clause to determine if a "date" column is in the future to the query.
     *
     * @return $this
     */
    public function orWhereFuture(array|string $columns): static
    {
        return $this->wherePastOrFuture($columns, '>', 'or');
    }

    /**
     * Add an "or where" clause to determine if a "date" column is in the future or now to the query.
     *
     * @return $this
     */
    public function orWhereNowOrFuture(array|string $columns): static
    {
        return $this->wherePastOrFuture($columns, '>=', 'or');
    }

    /**
     * Add an "where" clause to determine if a "date" column is in the past or future.
     *
     * @return $this
     */
    protected function wherePastOrFuture(array|string $columns, string $operator, string $boolean): static
    {
        $type = 'Basic';
        $value = Carbon::now();

        foreach (Arr::wrap($columns) as $column) {
            $this->wheres[] = compact('type', 'column', 'boolean', 'operator', 'value');

            $this->addBinding($value);
        }

        return $this;
    }

    /**
     * Add a "where date" clause to determine if a "date" column is today to the query.
     *
     * @return $this
     */
    public function whereToday(array|string $columns, string $boolean = 'and'): static
    {
        return $this->whereTodayBeforeOrAfter($columns, '=', $boolean);
    }

    /**
     * Add a "where date" clause to determine if a "date" column is before today.
     *
     * @return $this
     */
    public function whereBeforeToday(array|string $columns): static
    {
        return $this->whereTodayBeforeOrAfter($columns, '<', 'and');
    }

    /**
     * Add a "where date" clause to determine if a "date" column is today or before to the query.
     *
     * @return $this
     */
    public function whereTodayOrBefore(array|string $columns): static
    {
        return $this->whereTodayBeforeOrAfter($columns, '<=', 'and');
    }

    /**
     * Add a "where date" clause to determine if a "date" column is after today.
     *
     * @return $this
     */
    public function whereAfterToday(array|string $columns): static
    {
        return $this->whereTodayBeforeOrAfter($columns, '>', 'and');
    }

    /**
     * Add a "where date" clause to determine if a "date" column is today or after to the query.
     *
     * @return $this
     */
    public function whereTodayOrAfter(array|string $columns): static
    {
        return $this->whereTodayBeforeOrAfter($columns, '>=', 'and');
    }

    /**
     * Add an "or where date" clause to determine if a "date" column is today to the query.
     *
     * @return $this
     */
    public function orWhereToday(array|string $columns): static
    {
        return $this->whereToday($columns, 'or');
    }

    /**
     * Add an "or where date" clause to determine if a "date" column is before today.
     *
     * @return $this
     */
    public function orWhereBeforeToday(array|string $columns): static
    {
        return $this->whereTodayBeforeOrAfter($columns, '<', 'or');
    }

    /**
     * Add an "or where date" clause to determine if a "date" column is today or before to the query.
     *
     * @return $this
     */
    public function orWhereTodayOrBefore(array|string $columns): static
    {
        return $this->whereTodayBeforeOrAfter($columns, '<=', 'or');
    }

    /**
     * Add an "or where date" clause to determine if a "date" column is after today.
     *
     * @return $this
     */
    public function orWhereAfterToday(array|string $columns): static
    {
        return $this->whereTodayBeforeOrAfter($columns, '>', 'or');
    }

    /**
     * Add an "or where date" clause to determine if a "date" column is today or after to the query.
     *
     * @return $this
     */
    public function orWhereTodayOrAfter(array|string $columns): static
    {
        return $this->whereTodayBeforeOrAfter($columns, '>=', 'or');
    }

    /**
     * Add a "where date" clause to determine if a "date" column is today or after to the query.
     *
     * @return $this
     */
    protected function whereTodayBeforeOrAfter(array|string $columns, string $operator, string $boolean): static
    {
        $value = Carbon::today()->format('Y-m-d');

        foreach (Arr::wrap($columns) as $column) {
            $this->addDateBasedWhere('Date', $column, $operator, $value, $boolean);
        }

        return $this;
    }
}
