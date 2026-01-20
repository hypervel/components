<?php

declare(strict_types=1);

namespace Hypervel\Database\Query;

use Closure;
use Hyperf\Database\Query\Builder;
use Hyperf\Database\Query\Grammars\Grammar;
use Hyperf\Database\Query\Processors\Processor;
use Hypervel\Database\ConnectionInterface;

class JoinClause extends Builder
{
    /**
     * The type of join being performed.
     */
    public string $type;

    /**
     * The table the join clause is joining to.
     *
     * @var \Hypervel\Database\Contracts\Query\Expression|string
     */
    public $table;

    /**
     * The connection of the parent query builder.
     */
    protected ConnectionInterface $parentConnection;

    /**
     * The grammar of the parent query builder.
     */
    protected Grammar $parentGrammar;

    /**
     * The processor of the parent query builder.
     */
    protected Processor $parentProcessor;

    /**
     * The class name of the parent query builder.
     */
    protected string $parentClass;

    /**
     * Create a new join clause instance.
     */
    public function __construct(Builder $parentQuery, string $type, string $table)
    {
        $this->type = $type;
        $this->table = $table;
        $this->parentClass = get_class($parentQuery);
        $this->parentGrammar = $parentQuery->getGrammar();
        $this->parentProcessor = $parentQuery->getProcessor();
        $this->parentConnection = $parentQuery->getConnection();

        parent::__construct(
            $this->parentConnection,
            $this->parentGrammar,
            $this->parentProcessor
        );
    }

    /**
     * Add an "on" clause to the join.
     *
     * On clauses can be chained, e.g.
     *
     *  $join->on('contacts.user_id', '=', 'users.id')
     *       ->on('contacts.info_id', '=', 'info.id')
     *
     * will produce the following SQL:
     *
     * on `contacts`.`user_id` = `users`.`id` and `contacts`.`info_id` = `info`.`id`
     *
     * @param \Closure|\Hypervel\Database\Contracts\Query\Expression|string $first
     * @param \Hypervel\Database\Contracts\Query\Expression|string|null $second
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function on($first, ?string $operator = null, $second = null, string $boolean = 'and'): static
    {
        if ($first instanceof Closure) {
            return $this->whereNested($first, $boolean);
        }

        return $this->whereColumn($first, $operator, $second, $boolean);
    }

    /**
     * Add an "or on" clause to the join.
     *
     * @param \Closure|\Hypervel\Database\Contracts\Query\Expression|string $first
     * @param \Hypervel\Database\Contracts\Query\Expression|string|null $second
     */
    public function orOn($first, ?string $operator = null, $second = null): static
    {
        return $this->on($first, $operator, $second, 'or');
    }

    /**
     * Get a new instance of the join clause builder.
     */
    public function newQuery(): static
    {
        return new static($this->newParentQuery(), $this->type, $this->table);
    }

    /**
     * Create a new query instance for sub-query.
     */
    protected function forSubQuery(): Builder
    {
        return $this->newParentQuery()->newQuery();
    }

    /**
     * Create a new parent query instance.
     */
    protected function newParentQuery(): Builder
    {
        $class = $this->parentClass;

        return new $class($this->parentConnection, $this->parentGrammar, $this->parentProcessor);
    }
}
