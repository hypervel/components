<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\JsonApi\Concerns;

use Generator;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Concerns\AsPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Http\Resources\JsonApi\Exceptions\ResourceIdentificationException;
use Hypervel\Http\Resources\JsonApi\JsonApiRequest;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Hypervel\Http\Resources\JsonApi\RelationResolver;
use Hypervel\Http\Resources\MissingValue;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use JsonSerializable;
use RuntimeException;
use WeakMap;

trait ResolvesJsonApiElements
{
    /**
     * The default maximum relationship depth.
     */
    protected const int DEFAULT_MAX_RELATIONSHIP_DEPTH = 5;

    /**
     * Determine whether resources respect sparse fieldsets from the request.
     */
    protected bool $usesRequestQueryString = true;

    /**
     * Determine whether included relationship for the resource from eager loaded relationship.
     */
    protected bool $includesPreviouslyLoadedRelationships = false;

    /**
     * The requested relationships for the resource.
     *
     * @var null|array<int, string>
     */
    protected ?array $requestedRelationships = null;

    /**
     * Cached loaded relationships map.
     *
     * @var null|array<int, array{0: JsonApiResource, 1: string, 2: string, 3: bool}>
     */
    public ?array $loadedRelationshipsMap = null;

    /**
     * Cached loaded relationship identifiers.
     */
    protected array $loadedRelationshipIdentifiers = [];

    /**
     * The maximum relationship depth.
     */
    public static int $maxRelationshipDepth = self::DEFAULT_MAX_RELATIONSHIP_DEPTH;

    /**
     * Specify the maximum relationship depth.
     *
     * Boot-only. The depth limit persists in a static property for the worker
     * lifetime and affects every subsequent JSON:API resource resolution.
     *
     * @param non-negative-int $depth
     */
    public static function maxRelationshipDepth(int $depth): void
    {
        static::$maxRelationshipDepth = max(0, $depth);
    }

    /**
     * Resolve `data` for the resource.
     */
    protected function resolveResourceObject(JsonApiRequest $request): array
    {
        static::prepareResourceRelationships(new Collection([$this]), $request);

        $resourceType = $this->resolveResourceType($request);

        return [
            'id' => $this->resolveResourceIdentifier($request),
            'type' => $resourceType,
            ...(new Collection([
                'attributes' => $this->resolveResourceAttributes($request, $resourceType),
                'relationships' => $this->resolveResourceRelationshipIdentifiers($request),
                'links' => $this->resolveResourceLinks($request),
                'meta' => $this->resolveResourceMetaInformation($request),
            ]))->filter()->map(fn ($value) => (object) $value),
        ];
    }

    /**
     * Resolve the resource's identifier.
     *
     * @throws ResourceIdentificationException
     */
    public function resolveResourceIdentifier(JsonApiRequest $request): string
    {
        if (! is_null($resourceId = $this->toId($request))) {
            return $resourceId;
        }

        if (! ($this->resource instanceof Model || method_exists($this->resource, 'getKey'))) {
            throw ResourceIdentificationException::attemptingToDetermineIdFor($this);
        }

        $resourceId = $this->resource->getKey();

        if ($resourceId === null) {
            throw ResourceIdentificationException::attemptingToDetermineIdFor($this);
        }

        return (string) $resourceId;
    }

    /**
     * Resolve the resource's type.
     *
     * @throws ResourceIdentificationException
     */
    public function resolveResourceType(JsonApiRequest $request): string
    {
        if (! is_null($resourceType = $this->toType($request))) {
            return $resourceType;
        }

        if (static::class !== JsonApiResource::class) {
            return (new Stringable(static::class))->classBasename()->basename('Resource')->snake()->pluralStudly()->value();
        }

        if (! $this->resource instanceof Model) {
            throw ResourceIdentificationException::attemptingToDetermineTypeFor($this);
        }

        $modelClassName = $this->resource::class;

        $morphMap = Relation::getMorphAlias($modelClassName);

        return (new Stringable(
            $morphMap !== $modelClassName ? $morphMap : class_basename($modelClassName)
        ))->snake()->pluralStudly()->value();
    }

    /**
     * Resolve the resource's attributes.
     *
     * @throws RuntimeException
     */
    protected function resolveResourceAttributes(JsonApiRequest $request, string $resourceType): array
    {
        $data = $this->toAttributes($request);

        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        } elseif ($data instanceof JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        $usesSparseFieldset = $this->usesRequestQueryString && $request->hasSparseFieldset($resourceType);

        $sparseFieldset = $usesSparseFieldset ? $request->sparseFields($resourceType) : [];

        $data = (new Collection($data))
            ->mapWithKeys(fn ($value, $key) => is_int($key) ? [$value => $this->resource->{$value}] : [$key => $value])
            ->when($usesSparseFieldset, fn ($attributes) => $attributes->only($sparseFieldset))
            ->transform(fn ($value) => value($value, $request))
            ->all();

        return $this->filter($data);
    }

    /**
     * Resolve `relationships` for the resource's data object.
     *
     * @throws RuntimeException
     */
    protected function resolveResourceRelationshipIdentifiers(JsonApiRequest $request): array
    {
        if (! $this->resource instanceof Model) {
            return [];
        }

        $this->compileResourceRelationships($request);

        return [
            ...(new Collection($this->filter($this->loadedRelationshipIdentifiers)))
                ->map(function ($relation) {
                    return ! is_null($relation) ? $relation : ['data' => null];
                })->all(),
        ];
    }

    /**
     * Compile resource relationships.
     */
    protected function compileResourceRelationships(JsonApiRequest $request): void
    {
        if (! is_null($this->loadedRelationshipsMap)) {
            return;
        }

        $resourceRelationships = $this->getResourceRelationships($request);

        $resourceRelationshipKeys = $resourceRelationships->keys();

        $this->resource->loadMissing($resourceRelationshipKeys->all());

        $this->loadedRelationshipsMap = [];

        $this->loadedRelationshipIdentifiers = (new LazyCollection(function () use ($request, $resourceRelationships) {
            foreach ($resourceRelationships as $relationName => $relationResolver) {
                $relatedModels = $relationResolver->handle($this->resource);

                yield from $this->compileResourceRelationshipUsingResolver(
                    $request,
                    $this->resource,
                    $relationResolver,
                    $relatedModels,
                );
            }
        }))->all();
    }

    /**
     * Compile resource relations.
     */
    protected function compileResourceRelationshipUsingResolver(
        JsonApiRequest $request,
        mixed $resource,
        RelationResolver $relationResolver,
        Collection|Model|null $relatedModels
    ): Generator {
        $relationName = $relationResolver->relationName;
        $resourceClass = $relationResolver->resourceClass();
        $requestedRelationships = $this->requestedResourceRelationships($request, $relationName);

        // Relationship is a collection of models...
        if ($relatedModels instanceof Collection) {
            $relatedModels = $relatedModels->values();

            if ($relatedModels->isEmpty()) {
                yield $relationName => ['data' => $relatedModels];

                return;
            }

            $relationship = $resource->{$relationName}();

            $isUnique = ! $relationship instanceof BelongsToMany;

            yield $relationName => ['data' => $relatedModels->map(function ($relatedModel) use ($request, $resourceClass, $isUnique, $requestedRelationships) {
                $relatedResource = rescue(fn () => $relatedModel->toResource($resourceClass), new JsonApiResource($relatedModel));

                if (! $relatedResource instanceof JsonApiResource) {
                    $relatedResource = new JsonApiResource($relatedResource->resource);
                }

                $relatedResource->requestedRelationships = $requestedRelationships;

                return transform(
                    [$relatedResource->resolveResourceType($request), $relatedResource->resolveResourceIdentifier($request)],
                    function ($uniqueKey) use ($relatedResource, $isUnique) {
                        $this->loadedRelationshipsMap[] = [$relatedResource, ...$uniqueKey, $isUnique];

                        return [
                            'id' => $uniqueKey[1],
                            'type' => $uniqueKey[0],
                        ];
                    }
                );
            })->all()];

            return;
        }

        // Relationship is a single model...
        $relatedModel = $relatedModels;

        if (is_null($relatedModel)) {
            yield $relationName => null;

            return;
        }
        if ($relatedModel instanceof Pivot
            || isset(class_uses_recursive($relatedModel)[AsPivot::class])) {
            yield $relationName => new MissingValue;

            return;
        }

        $relatedResource = rescue(fn () => $relatedModel->toResource($resourceClass), new JsonApiResource($relatedModel));

        if (! $relatedResource instanceof JsonApiResource) {
            $relatedResource = new JsonApiResource($relatedResource->resource);
        }

        $relatedResource->requestedRelationships = $requestedRelationships;

        yield $relationName => ['data' => transform(
            [$relatedResource->resolveResourceType($request), $relatedResource->resolveResourceIdentifier($request)],
            function ($uniqueKey) use ($relatedResource) {
                $this->loadedRelationshipsMap[] = [$relatedResource, ...$uniqueKey, true];

                return [
                    'id' => $uniqueKey[1],
                    'type' => $uniqueKey[0],
                ];
            }
        )];
    }

    /**
     * Get the requested relationships for this resource or one of its relationships.
     */
    protected function requestedResourceRelationships(JsonApiRequest $request, ?string $relationName = null): array
    {
        if (is_null($this->requestedRelationships)) {
            if ($this->includesPreviouslyLoadedRelationships) {
                return is_null($relationName) ? array_keys($this->resource->getRelations()) : [];
            }

            return $request->sparseIncluded($relationName) ?? [];
        }

        if (is_null($relationName)) {
            $requested = (new Collection($this->requestedRelationships))
                ->map(fn ($relationship) => explode('.', $relationship, 2)[0]);

            if ($this->includesPreviouslyLoadedRelationships) {
                $requested->push(...array_keys($this->resource->getRelations()));
            }

            return $requested->unique()->values()->all();
        }

        return (new Collection($this->requestedRelationships))
            ->filter(fn ($relationship) => str_starts_with($relationship, $relationName . '.'))
            ->map(fn ($relationship) => substr($relationship, strlen($relationName) + 1))
            ->values()
            ->all();
    }

    /**
     * Get the declared resolvers for the requested resource relationships.
     *
     * @return Collection<string, RelationResolver>
     */
    protected function getResourceRelationships(JsonApiRequest $request): Collection
    {
        return (new Collection($this->toRelationships($request)))
            ->transform(fn ($value, $key) => is_int($key) ? new RelationResolver($value) : new RelationResolver($key, $value))
            ->mapWithKeys(fn ($relationResolver) => [$relationResolver->relationName => $relationResolver])
            ->only($this->requestedResourceRelationships($request));
    }

    /**
     * Load requested relationships in batches before serializing their attributes.
     *
     * @internal
     * @param Collection<array-key, JsonApiResource> $resources
     */
    public static function prepareResourceRelationships(Collection $resources, JsonApiRequest $request): void
    {
        while ($resources->isNotEmpty()) {
            $groups = [];

            foreach ($resources as $resource) {
                if (! $resource->resource instanceof Model || $resource->loadedRelationshipsMap !== null) {
                    continue;
                }

                $model = $resource->resource;

                foreach ($resource->getResourceRelationships($request)->keys() as $relationship) {
                    if ($model->relationLoaded($relationship)) {
                        continue;
                    }

                    // Eager loading uses the first model's class and connection for the whole group.
                    $key = serialize([$model::class, $model->getConnectionName(), $relationship]);
                    $groups[$key] ??= [$model->newCollection(), $relationship];
                    $groups[$key][0]->push($model);
                }
            }

            foreach ($groups as [$models, $relationship]) {
                $models->loadMissing($relationship);
            }

            $next = new Collection;

            foreach ($resources as $resource) {
                if (! $resource->resource instanceof Model) {
                    continue;
                }

                $resource->compileResourceRelationships($request);

                foreach ($resource->loadedRelationshipsMap ?? [] as [$relatedResource]) {
                    // Only requested tails advance; already-loaded cycles belong to the emission walk.
                    if (! empty($relatedResource->requestedRelationships)) {
                        $next->push($relatedResource->includePreviouslyLoadedRelationships());
                    }
                }
            }

            $resources = $next;
        }
    }

    /**
     * Resolve `included` for the resource.
     */
    public function resolveIncludedResourceObjects(JsonApiRequest $request): Collection
    {
        if (! $this->resource instanceof Model) {
            return new Collection;
        }

        static::prepareResourceRelationships(new Collection([$this]), $request);

        $relations = new Collection;
        $index = 0;

        // Track visited objects by instance + type to prevent infinite loops from circular
        // references created by "chaperone()". We use object instances rather than type
        // and ID for any possible cases like BelongsToMany with different pivot data.
        // We'll track types to allow the same models with different resource types.
        $visitedObjects = new WeakMap;

        $visitedObjects[$this->resource] = [
            $this->resolveResourceType($request) => true,
        ];

        while ($index < count($this->loadedRelationshipsMap)) {
            [$resourceInstance, $type, $id, $isUnique] = $this->loadedRelationshipsMap[$index];

            $underlyingResource = $resourceInstance->resource;

            if (is_object($underlyingResource)) {
                if (isset($visitedObjects[$underlyingResource][$type])) {
                    ++$index;
                    continue;
                }

                $visitedObjects[$underlyingResource] ??= [];
                $visitedObjects[$underlyingResource][$type] = true;
            }

            $relationsData = $resourceInstance
                ->includePreviouslyLoadedRelationships()
                ->resolve($request);

            array_push($this->loadedRelationshipsMap, ...($resourceInstance->loadedRelationshipsMap ?? []));

            $relations->push(array_filter([
                'id' => $id,
                'type' => $type,
                '_uniqueKey' => implode(':', $isUnique === true ? [$id, $type] : [$id, $type, (string) Str::random()]),
                'attributes' => Arr::get($relationsData, 'data.attributes'),
                'relationships' => Arr::get($relationsData, 'data.relationships'),
                'links' => Arr::get($relationsData, 'data.links'),
                'meta' => Arr::get($relationsData, 'data.meta'),
            ]));

            ++$index;
        }

        return $relations;
    }

    /**
     * Resolve included resources and preserve linkage when combining duplicate identities.
     *
     * @internal
     * @param Collection<array-key, JsonApiResource> $resources
     */
    public static function resolveIncludedResources(Collection $resources, JsonApiRequest $request): array
    {
        static::prepareResourceRelationships($resources, $request);

        $roots = [];

        foreach ($resources as $resource) {
            if ($resource->resource instanceof Model) {
                $roots[$resource->resolveResourceType($request)][$resource->resolveResourceIdentifier($request)] = $resource;
            }
        }

        $included = [];
        $positions = [];
        $identifiers = [];

        foreach ($resources as $resource) {
            foreach ($resource->resolveIncludedResourceObjects($request) as $entry) {
                $key = $entry['_uniqueKey'];
                $relationships = (array) ($entry['relationships'] ?? []);
                $root = $roots[$entry['type']][$entry['id']] ?? null;

                // Pivot variants have distinct keys and must retain their own attributes.
                if ($root !== null && $key === $entry['id'] . ':' . $entry['type']) {
                    $identifiers[$key] ??= [];
                    static::mergeResourceRelationships($root->loadedRelationshipIdentifiers, $relationships, $identifiers[$key]);
                } elseif (isset($positions[$key])) {
                    $position = $positions[$key];
                    $identifiers[$key] ??= [];
                    static::mergeResourceRelationships($included[$position]['relationships'], $relationships, $identifiers[$key]);
                } else {
                    $positions[$key] = count($included);
                    $entry['relationships'] = $relationships;
                    unset($entry['_uniqueKey']);
                    $included[] = $entry;
                }
            }
        }

        foreach ($included as &$entry) {
            if ($entry['relationships'] === []) {
                unset($entry['relationships']);
            } else {
                $entry['relationships'] = (object) $entry['relationships'];
            }
        }
        unset($entry);

        return $included;
    }

    /**
     * Merge relationship linkage without discarding descendants of duplicate resources.
     */
    protected static function mergeResourceRelationships(array &$relationships, array $additional, array &$identifiers): void
    {
        foreach ($additional as $name => $relationship) {
            if (! array_key_exists($name, $relationships)) {
                $relationships[$name] = $relationship;
                continue;
            }

            if ($relationships[$name] instanceof MissingValue) {
                continue;
            }

            if (! isset($relationships[$name]['data'])) {
                if (isset($relationship['data'])) {
                    $relationships[$name]['data'] = $relationship['data'];
                }
                continue;
            }

            $data = &$relationships[$name]['data'];
            $incoming = $relationship['data'] ?? null;

            if ($data instanceof Collection) {
                $data = $data->all();
            }

            $incoming = $incoming instanceof Collection ? $incoming->all() : $incoming;

            if ($incoming === null || ! array_is_list($data)) {
                continue;
            }

            if (! isset($identifiers[$name])) {
                $identifiers[$name] = [];

                foreach ($data as $identifier) {
                    $identifiers[$name][$identifier['type']][$identifier['id']] = true;
                }
            }

            foreach ($incoming as $identifier) {
                if (! isset($identifiers[$name][$identifier['type']][$identifier['id']])) {
                    $identifiers[$name][$identifier['type']][$identifier['id']] = true;
                    $data[] = $identifier;
                }
            }
        }
    }

    /**
     * Resolve the links for the resource.
     *
     * @return array<string, mixed>
     */
    protected function resolveResourceLinks(JsonApiRequest $request): array
    {
        return $this->toLinks($request);
    }

    /**
     * Resolve the meta information for the resource.
     *
     * @return array<string, mixed>
     */
    protected function resolveResourceMetaInformation(JsonApiRequest $request): array
    {
        return $this->toMeta($request);
    }

    /**
     * Indicate that attributes should respect the request's sparse fieldsets.
     */
    public function respectFieldsAndIncludesInQueryString(bool $value = true): static
    {
        $this->usesRequestQueryString = $value;

        return $this;
    }

    /**
     * Indicate that attributes should ignore the request's sparse fieldsets.
     */
    public function ignoreFieldsAndIncludesInQueryString(): static
    {
        return $this->respectFieldsAndIncludesInQueryString(false);
    }

    /**
     * Indicate that relationship should include loaded relationships.
     */
    public function includePreviouslyLoadedRelationships(): static
    {
        $this->includesPreviouslyLoadedRelationships = true;

        return $this;
    }
}
