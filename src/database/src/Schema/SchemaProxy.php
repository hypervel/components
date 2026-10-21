<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\Connection;

/**
 * @mixin Builder
 */
class SchemaProxy
{
    /**
     * @var null|(Closure(Connection, string, null|Closure): Blueprint)
     */
    protected ?Closure $resolver = null;

    /**
     * Create a new schema proxy.
     */
    public function __construct(protected Container $app)
    {
    }

    /**
     * Forward a schema operation to the current connection's builder.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->connection()
            ->{$name}(...$arguments);
    }

    /**
     * Get schema builder with specific connection.
     *
     * Routes through DatabaseManager to respect usingConnection() overrides.
     */
    public function connection(?string $name = null): Builder
    {
        $builder = $this->app
            ->make('db')
            ->connection($name)
            ->getSchemaBuilder();

        // Retain configuration without retaining a coroutine's pooled connection.
        if ($this->resolver !== null) {
            $builder->blueprintResolver($this->resolver);
        }

        return $builder;
    }

    /**
     * Set the default Schema Blueprint resolver callback.
     *
     * Boot-only. The callback persists on the shared proxy for the worker
     * lifetime and applies to every subsequent schema builder it creates.
     *
     * @param Closure(Connection, string, null|Closure): Blueprint $resolver
     */
    public function blueprintResolver(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }
}
