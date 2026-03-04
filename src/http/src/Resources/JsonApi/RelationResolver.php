<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\JsonApi;

use Closure;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;

/**
 * @internal
 */
class RelationResolver
{
    /**
     * The relation resolver.
     *
     * @var Closure(mixed):(null|\Hypervel\Database\Eloquent\Collection|\Hypervel\Database\Eloquent\Model)
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
     * @param null|class-string<\Hypervel\Http\Resources\JsonApi\JsonApiResource>|Closure(mixed):(null|\Hypervel\Database\Eloquent\Collection|\Hypervel\Database\Eloquent\Model) $resolver
     */
    public function __construct(public string $relationName, Closure|string|null $resolver = null)
    {
        $this->relationResolver = match (true) {
            $resolver instanceof Closure => $resolver,
            default => fn ($resource) => $resource->getRelation($this->relationName),
        };

        if (is_string($resolver) && class_exists($resolver)) {
            $this->relationResourceClass = $resolver;
        }
    }

    /**
     * Resolve the relation for a resource.
     */
    public function handle(mixed $resource): Collection|Model|null
    {
        return value($this->relationResolver, $resource);
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
