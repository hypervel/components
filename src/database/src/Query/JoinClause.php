<?php

declare(strict_types=1);

namespace Hypervel\Database\Query;

use Closure;
use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use InvalidArgumentException;

class JoinClause extends Builder
{
    /**
     * The type of join being performed.
     */
    public string $type;

    /**
     * The table the join clause is joining to.
     */
    public ExpressionContract|string $table;

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
     * @throws InvalidArgumentException
     */
    public function on(
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null,
        string $boolean = 'and',
    ): static {
        if ($first instanceof Closure) {
            return $this->whereNested($first, $boolean);
        }

        return $this->whereColumn($first, $operator, $second, $boolean);
    }

    /**
     * Add an "or on" clause to the join.
     */
    public function orOn(
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null,
    ): static {
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
