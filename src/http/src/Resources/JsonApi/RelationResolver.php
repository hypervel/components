<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\JsonApi;

use Closure;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @internal
 */
class RelationResolver
{
    /**
     * The relation resolver.
     *
     * @var Closure(mixed):(null|\Hypervel\Database\Eloquent\Collection|\Hypervel\Database\Eloquent\Model|\Hypervel\Http\Resources\JsonApi\AnonymousResourceCollection|\Hypervel\Http\Resources\JsonApi\JsonApiResource)
     */
    public Closure $relationResolver;

    /**
     * The relation resource class.
     *
     * @var null|class-string<\Hypervel\Http\Resources\JsonApi\JsonApiResource>
     */
    public ?string $relationResourceClass = null;

    /**
     * Construct a new resource relationship resolver.
     *
     * @param null|class-string<\Hypervel\Http\Resources\JsonApi\JsonApiResource>|Closure(mixed):(null|\Hypervel\Database\Eloquent\Collection|\Hypervel\Database\Eloquent\Model|\Hypervel\Http\Resources\JsonApi\AnonymousResourceCollection|\Hypervel\Http\Resources\JsonApi\JsonApiResource) $resolver
     *
     * @throws InvalidArgumentException
     */
    public function __construct(public string $relationName, Closure|string|null $resolver = null)
    {
        $this->relationResolver = match (true) {
            $resolver instanceof Closure => $resolver,
            default => fn ($resource) => $resource->getRelation($this->relationName),
        };

        if (is_string($resolver)) {
            if (! class_exists($resolver)) {
                throw new InvalidArgumentException(
                    "Resource class [{$resolver}] for relationship [{$this->relationName}] does not exist."
                );
            }

            $this->relationResourceClass = $resolver;
        }
    }

    /**
     * Resolve the relation for a resource.
     */
    public function handle(mixed $resource): Collection|Model|null
    {
        $resolved = value($this->relationResolver, $resource);

        if ($resolved instanceof AnonymousResourceCollection) {
            $this->relationResourceClass ??= $resolved->collects;

            return new Collection($resolved->collection->map->resource);
        }

        if ($resolved instanceof JsonApiResource) {
            $this->relationResourceClass ??= $resolved::class;

            return $resolved->resource;
        }

        return $resolved;
    }

    /**
     * Get the resource class.
     *
     * @return null|class-string<\Hypervel\Http\Resources\JsonApi\JsonApiResource>
     */
    public function resourceClass(): ?string
    {
        return $this->relationResourceClass;
    }
}
