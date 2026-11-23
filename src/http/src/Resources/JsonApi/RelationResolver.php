<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\JsonApi;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use InvalidArgumentException;

/**
 * @internal
 */
class RelationResolver
{
    /**
     * The relation resolver.
     *
     * @var Closure(mixed):(null|AnonymousResourceCollection|Collection|JsonApiResource|Model)
     */
    public Closure $relationResolver;

    /**
     * The relation resource class.
     *
     * @var null|class-string<JsonApiResource>
     */
    public ?string $relationResourceClass = null;

    /**
     * Construct a new resource relationship resolver.
     *
     * @param null|class-string<JsonApiResource>|Closure(mixed):(null|AnonymousResourceCollection|Collection|JsonApiResource|Model) $resolver
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
    public function handle(mixed $resource): Collection|JsonApiResource|Model|null
    {
        $resolved = value($this->relationResolver, $resource);

        // Preserve supplied resources; rebuilding them from models loses constructor state.
        if ($resolved instanceof AnonymousResourceCollection) {
            $this->relationResourceClass ??= $resolved->collects;

            return $resolved->collection;
        }

        if ($resolved instanceof JsonApiResource) {
            $this->relationResourceClass ??= $resolved::class;

            return $resolved;
        }

        return $resolved;
    }

    /**
     * Get the resource class.
     *
     * @return null|class-string<JsonApiResource>
     */
    public function resourceClass(): ?string
    {
        return $this->relationResourceClass;
    }
}
