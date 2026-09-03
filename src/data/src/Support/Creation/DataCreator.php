<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use BackedEnum;
use DateTimeInterface;
use Hypervel\Container\Container as ConcreteContainer;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Hypervel\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Data\Attributes\AutoLazy;
use Hypervel\Data\Attributes\AutoWhenLoadedLazy;
use Hypervel\Data\Attributes\GetsCast;
use Hypervel\Data\Casts\BuiltinTypeCast;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\CastableCast;
use Hypervel\Data\Casts\DateTimeInterfaceCast;
use Hypervel\Data\Casts\EnumCast;
use Hypervel\Data\Casts\IterableItemCast;
use Hypervel\Data\Casts\Uncastable;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Enums\CustomCreationMethodType;
use Hypervel\Data\Exceptions\CannotCreateAbstractClass;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Exceptions\CannotCreateDataCollectable;
use Hypervel\Data\Exceptions\CannotSetComputedValue;
use Hypervel\Data\Lazy;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalized\UnknownProperty;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Optional;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataMethod;
use Hypervel\Data\Support\DataMethodMatch;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Types\NamedType;
use Hypervel\Data\Support\Types\Type;
use Hypervel\Data\Support\Validation\DataValidator;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;
use Traversable;

use function data_set;

/** @phpstan-type OperationMemo array<string, object|list<Normalizer>> */
class DataCreator
{
    /**
     * Create a data creator.
     */
    public function __construct(
        protected readonly Container $container,
        protected readonly DataClassRepository $dataClasses,
        protected readonly DataConfig $config,
        protected readonly DataCollectableFactory $dataCollectables,
        protected readonly DataInstantiator $instantiator,
        protected readonly DataValidator $validator,
    ) {
    }

    /**
     * Create a fresh construction factory for a data class.
     *
     * @template TData of BaseData
     *
     * @param class-string<TData> $class
     * @return CreationContextFactory<TData>
     */
    public function factory(string $class): CreationContextFactory
    {
        return new CreationContextFactory($this, $this->config, $class);
    }

    /**
     * Create a data object through one fixed construction operation.
     *
     * @template TData of BaseData
     *
     * @param class-string<TData> $class
     * @return TData
     */
    public function create(
        string $class,
        CreationContext $context,
        mixed ...$payloads,
    ): BaseData {
        /** @var BaseData $data */
        $data = $this->execute($class, $context, $payloads);

        return $data;
    }

    /**
     * Validate a payload without casting or construction.
     *
     * @param class-string<BaseData> $class
     * @param array<array-key, mixed> $payloads
     * @return array<array-key, mixed>
     */
    public function validate(
        string $class,
        CreationContext $context,
        array $payloads,
    ): array {
        /** @var array<array-key, mixed> $validated */
        $validated = $this->execute($class, $context, $payloads);

        return $validated;
    }

    /**
     * Get validation rules for a payload without running the Validator.
     *
     * @param class-string<BaseData> $class
     * @param array<array-key, mixed> $payloads
     * @return array<string, list<array|object|string>>
     */
    public function getValidationRules(
        string $class,
        CreationContext $context,
        array $payloads,
    ): array {
        /** @var array<string, list<array|object|string>> $rules */
        $rules = $this->execute($class, $context, $payloads);

        return $rules;
    }

    /**
     * Resolve one deferred automatic lazy property.
     *
     * @internal
     */
    public function resolveAutoLazyProperty(
        string $propertyName,
        mixed $value,
        ConstructionState $state,
        ?AutoLazyReplayMode $replay,
    ): mixed {
        $class = $state->nodeClass() ?? $state->context->dataClass;
        $property = $this->dataClasses->get($class)->properties[$propertyName];
        $extensions = [];

        if ($replay !== null && ! $property->isFinishedValue($value)) {
            $wireKey = $state->originalKey($propertyName);
            $this->fillResolvedProperty(
                $property,
                $property->inputPath($wireKey),
                $value,
                $state,
                $extensions,
                false,
                false,
                $replay === AutoLazyReplayMode::Hook,
            );
        }

        return $this->castProperty($property, $value, $state, $extensions);
    }

    /**
     * Collect data objects through one root operation.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TCollectValue of BaseData
     * @template TModelValue of Model
     * @template TData of BaseData
     *
     * @param class-string<TData> $class
     * @param AbstractCursorPaginator<TKey, TValue>|AbstractPaginator<TKey, TValue>|array<TKey, TValue>|Collection<TKey, TValue>|CursorPaginatedDataCollection<TKey, TCollectValue>|CursorPaginatorContract<TKey, TValue>|DataCollection<TKey, TCollectValue>|EloquentCollection<TKey, TModelValue>|Enumerable<TKey, TValue>|LazyCollection<TKey, TValue>|LengthAwarePaginatorContract<TKey, TValue>|PaginatedDataCollection<TKey, TCollectValue>|PaginatorContract<TKey, TValue>|Traversable<TKey, TValue> $items
     * @param null|'array'|class-string $into
     * @return AbstractCursorPaginator<TKey, TData>|AbstractPaginator<TKey, TData>|array<TKey, TData>|CursorPaginatedDataCollection<TKey, TData>|CursorPaginatorContract<TKey, TData>|DataCollection<TKey, TData>|Enumerable<TKey, TData>|LengthAwarePaginatorContract<TKey, TData>|PaginatedDataCollection<TKey, TData>|PaginatorContract<TKey, TData>
     */
    public function collect(
        string $class,
        CreationContext $context,
        mixed $items,
        ?string $into = null,
    ): array|DataCollection|PaginatedDataCollection|CursorPaginatedDataCollection|Enumerable|AbstractPaginator|PaginatorContract|AbstractCursorPaginator|CursorPaginatorContract|LazyCollection|Collection {
        $shouldValidate = $this->validator->shouldValidate($context, [$items]);

        if ($items instanceof LazyCollection && ! $shouldValidate) {
            return $this->collectLazy($class, $context, $items, $into);
        }

        $values = $this->dataCollectables->items($items);

        if ($values === null) {
            throw CannotCreateDataCollectable::create(
                get_debug_type($items),
                $into ?? 'inferred collection',
            );
        }

        return $this->collectEager(
            $class,
            $context,
            $items,
            $values,
            $into,
            $shouldValidate,
        );
    }

    /**
     * Create typed items through one internal collection operation.
     *
     * @internal
     *
     * @template TKey of array-key
     * @template TData of BaseData
     *
     * @param class-string<TData> $class
     * @param null|array<TKey, mixed>|DataCollection<TKey, BaseData>|Enumerable<TKey, mixed> $items
     * @return Enumerable<TKey, TData>
     */
    public function collectItems(
        string $class,
        CreationContext $context,
        mixed $items,
    ): Enumerable {
        if ($items === null) {
            return new Collection;
        }

        $shouldValidate = $this->validator->shouldValidate($context, [$items]);

        if ($items instanceof LazyCollection && ! $shouldValidate) {
            return $this->deferCollectionItems($class, $context, $items);
        }

        $values = $this->dataCollectables->items($items);

        if ($values === null) {
            throw CannotCreateDataCollectable::create(
                get_debug_type($items),
                DataCollection::class,
            );
        }

        return new Collection($this->createEagerCollectionItems(
            $class,
            $context,
            $items,
            $values,
            $shouldValidate,
        ));
    }

    /**
     * Collect a deferred source without enumerating it.
     *
     * @template TKey of array-key
     * @template TData of BaseData
     *
     * @param class-string<TData> $class
     * @param LazyCollection<TKey, mixed> $source
     * @return AbstractCursorPaginator<TKey, TData>|AbstractPaginator<TKey, TData>|array<TKey, TData>|CursorPaginatedDataCollection<TKey, TData>|CursorPaginatorContract<TKey, TData>|DataCollection<TKey, TData>|Enumerable<TKey, TData>|LengthAwarePaginatorContract<TKey, TData>|PaginatedDataCollection<TKey, TData>|PaginatorContract<TKey, TData>
     */
    protected function collectLazy(
        string $class,
        CreationContext $context,
        LazyCollection $source,
        ?string $into,
    ): array|DataCollection|PaginatedDataCollection|CursorPaginatedDataCollection|Enumerable|AbstractPaginator|PaginatorContract|AbstractCursorPaginator|CursorPaginatorContract|LazyCollection|Collection {
        $items = $this->deferCollectionItems($class, $context, $source);
        $methodSource = $this->dataCollectables->forMethodSource($class, $source, $items);
        $dataClass = $this->dataClasses->get($class);
        $match = $methodSource === null
            ? null
            : $this->matchNamedCollectionFactory($dataClass, $context, $methodSource, $into);

        return $match !== null
            ? $this->invokeNamedFactory($dataClass, ...$match)
            : $this->dataCollectables->forTarget($class, $source, $items, $into);
    }

    /**
     * Create a deferred item collection with one shared operation memo.
     *
     * @template TKey of array-key
     * @template TData of BaseData
     *
     * @param class-string<TData> $class
     * @param LazyCollection<TKey, mixed> $source
     * @return LazyCollection<TKey, TData>
     */
    protected function deferCollectionItems(
        string $class,
        CreationContext $context,
        LazyCollection $source,
    ): LazyCollection {
        $extensions = [];

        // Deferred traversal remains part of this root operation, so its resolved extensions stay shared.
        return $source->map(
            function (mixed $item) use ($class, $context, &$extensions): BaseData {
                return $this->createUnvalidatedNode(
                    $class,
                    $context,
                    $item,
                    $extensions,
                );
            },
        );
    }

    /**
     * Create one nested node without restarting root validation or authorization.
     *
     * @template TData of BaseData
     *
     * @param class-string<TData> $class
     * @param OperationMemo $extensions
     * @return TData
     */
    protected function createUnvalidatedNode(
        string $class,
        CreationContext $context,
        mixed $item,
        array &$extensions,
    ): BaseData {
        if ($item instanceof $class) {
            return $item;
        }

        $state = ConstructionState::create($context, $class);
        $direct = $this->fillNode(
            $class,
            [$item],
            $state,
            $extensions,
            false,
            false,
        );

        // Named factories, morph selection, instantiation, and after-creation hooks all enforce this class.
        /** @var TData $data */
        $data = $direct ?? $this->castAndInstantiateNode($state, $extensions);

        return $data;
    }

    /**
     * Collect an eager source through one Fill and validation graph.
     *
     * @template TKey of array-key
     * @template TData of BaseData
     *
     * @param class-string<TData> $class
     * @param array<TKey, mixed> $values
     * @return AbstractCursorPaginator<TKey, TData>|AbstractPaginator<TKey, TData>|array<TKey, TData>|CursorPaginatedDataCollection<TKey, TData>|CursorPaginatorContract<TKey, TData>|DataCollection<TKey, TData>|Enumerable<TKey, TData>|LengthAwarePaginatorContract<TKey, TData>|PaginatedDataCollection<TKey, TData>|PaginatorContract<TKey, TData>
     */
    protected function collectEager(
        string $class,
        CreationContext $context,
        mixed $source,
        array $values,
        ?string $into,
        bool $shouldValidate,
    ): array|DataCollection|PaginatedDataCollection|CursorPaginatedDataCollection|Enumerable|AbstractPaginator|PaginatorContract|AbstractCursorPaginator|CursorPaginatorContract|LazyCollection|Collection {
        $items = $this->createEagerCollectionItems(
            $class,
            $context,
            $source,
            $values,
            $shouldValidate,
        );
        $methodSource = $this->dataCollectables->forMethodSource($class, $source, $items);
        $dataClass = $this->dataClasses->get($class);
        $match = $methodSource === null
            ? null
            : $this->matchNamedCollectionFactory($dataClass, $context, $methodSource, $into);

        return $match !== null
            ? $this->invokeNamedFactory($dataClass, ...$match)
            : $this->dataCollectables->forTarget($class, $source, $items, $into);
    }

    /**
     * Create every eager item through one Fill and validation graph.
     *
     * @template TKey of array-key
     * @template TData of BaseData
     *
     * @param class-string<TData> $class
     * @param array<TKey, mixed> $values
     * @return array<TKey, TData>
     */
    protected function createEagerCollectionItems(
        string $class,
        CreationContext $context,
        mixed $source,
        array $values,
        bool $shouldValidate,
    ): array {
        $request = $shouldValidate
            ? $this->validator->authorize($class, [$source])
            : null;
        $state = ConstructionState::create($context, $class);
        $extensions = [];

        if ($source instanceof EloquentCollection) {
            $this->loadMissingRelations($class, $source);
        }

        $this->fillCollectionItems(
            $class,
            $values,
            $state,
            $extensions,
            $shouldValidate,
        );

        if ($shouldValidate && $context->beforeValidationHooks !== []) {
            $this->applyPayloadHooks(
                $class,
                $context->beforeValidationHooks,
                $state,
                $extensions,
                true,
                true,
                collection: true,
            );
        }

        if ($shouldValidate) {
            $compiled = $this->validator->compileCollection($state, $class);
            $this->validator->validate($state, $compiled, $request, $class);
        }

        if ($shouldValidate && $context->afterValidationHooks !== []) {
            $this->applyPayloadHooks(
                $class,
                $context->afterValidationHooks,
                $state,
                $extensions,
                false,
                false,
                collection: true,
            );
        }

        // Fill and construction preserve source keys and enforce the requested class at every object exit.
        /** @var array<TKey, TData> $items */
        $items = $this->castCollectionItems($class, $state, $extensions);

        return $items;
    }

    /**
     * Batch relations explicitly selected by data properties.
     *
     * @param class-string<BaseData> $class
     */
    protected function loadMissingRelations(string $class, EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $model = $models->first();
        $relations = [];

        foreach ($this->dataClasses->get($class)->properties as $property) {
            if (! $property->loadRelation) {
                continue;
            }

            if (($relation = $property->resolveModelRelation($model)) !== null) {
                $relations[] = $relation;
            }
        }

        if ($relations !== []) {
            $models->loadMissing($relations);
        }
    }

    /**
     * Fill every eager root collection item into one construction state.
     *
     * @param class-string<BaseData> $class
     * @param array<array-key, mixed> $values
     * @param OperationMemo $extensions
     */
    protected function fillCollectionItems(
        string $class,
        array $values,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
    ): void {
        foreach ($values as $key => $item) {
            if ($item instanceof $class) {
                $state->writeFinishedItemValue($key, $item);

                continue;
            }

            $state->writeItemValue($key, []);
            $state->enterItem($key);

            try {
                $direct = $this->fillNode(
                    $class,
                    [$item],
                    $state,
                    $extensions,
                    $shouldValidate,
                    $shouldValidate,
                );
            } finally {
                $state->leave();
            }

            if ($direct !== null) {
                $state->writeFinishedItemValue($key, $direct);
            }
        }
    }

    /**
     * Cast and instantiate every eager root collection item.
     *
     * @param class-string<BaseData> $class
     * @param OperationMemo $extensions
     * @return array<array-key, BaseData>
     */
    protected function castCollectionItems(
        string $class,
        ConstructionState $state,
        array &$extensions,
    ): array {
        $items = [];

        foreach ($state->payload() as $key => $item) {
            if ($item instanceof $class) {
                $items[$key] = $item;

                continue;
            }

            $state->enterItem($key);

            try {
                $items[$key] = $this->castAndInstantiateNode($state, $extensions);
            } finally {
                $state->leave();
            }
        }

        return $items;
    }

    /**
     * Run the fixed operation through its selected exit point.
     *
     * @param class-string<BaseData> $class
     * @param array<array-key, mixed> $payloads
     * @return array<array-key, mixed>|BaseData
     */
    protected function execute(
        string $class,
        CreationContext $context,
        array $payloads,
    ): BaseData|array {
        if ($context->mode === CreationMode::Create
            && count($payloads) === 1
            && $payloads[0] instanceof $class
        ) {
            return $payloads[0];
        }

        $shouldValidate = $context->mode !== CreationMode::Rules
            && $this->validator->shouldValidate($context, $payloads);
        $compilesRules = $shouldValidate || $context->mode === CreationMode::Rules;
        $request = $shouldValidate
            ? $this->validator->authorize($class, $payloads)
            : null;

        $state = ConstructionState::create($context, $class);
        $extensions = [];
        $direct = $this->fillNode(
            $class,
            $payloads,
            $state,
            $extensions,
            $shouldValidate,
            $compilesRules,
        );

        if ($direct !== null) {
            return $direct;
        }

        if ($compilesRules && $context->beforeValidationHooks !== []) {
            $this->applyPayloadHooks(
                $class,
                $context->beforeValidationHooks,
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
            );
        }

        if ($context->mode === CreationMode::Rules) {
            return $this->validator->compile($state)->rules;
        }

        if ($shouldValidate) {
            $compiled = $this->validator->compile($state);
            $this->validator->validate($state, $compiled, $request);
        }

        if ($shouldValidate && $context->afterValidationHooks !== []) {
            $this->applyPayloadHooks(
                $class,
                $context->afterValidationHooks,
                $state,
                $extensions,
                false,
                false,
                reconcile: $context->mode === CreationMode::Create,
            );
        }

        if ($context->mode === CreationMode::Validate) {
            return $state->payload();
        }

        return $this->castAndInstantiateNode($state, $extensions);
    }

    /**
     * Normalize and fill one data node into the root construction state.
     *
     * @param class-string<BaseData> $class
     * @param array<array-key, mixed> $payloads
     * @param OperationMemo $extensions
     */
    protected function fillNode(
        string $class,
        array $payloads,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
    ): ?BaseData {
        $dataClass = $this->dataClasses->get($class);
        $match = $this->matchNamedFactory($dataClass, $state->context, $payloads);

        if ($match !== null) {
            $result = $this->invokeNamedFactory($dataClass, ...$match);

            if ($result instanceof $class) {
                return $result;
            }

            $payloads = [$result];
        }

        $direct = $this->tryCreateDirectArrayNode(
            $dataClass,
            $payloads,
            $state,
            $shouldValidate,
            $compilesRules,
        );

        if ($direct !== null) {
            return $direct;
        }

        $normalizers = $this->resolveNormalizers($dataClass, $state->context, $extensions);
        $payloads = $payloads === [] ? [[]] : $payloads;
        $sources = [];
        $unknownInputSources = [];

        foreach ($payloads as $payload) {
            $source = SourceResolver::resolve($class, $payload, $normalizers);
            $sources[] = $source;
            $unknownInputSources[] = $payload instanceof Request
                ? ($payload->isJson() ? $payload->json()->all() : $payload->request->all())
                : $source;
        }

        $propertySources = $sources;
        $resolvedProperties = $this->resolveProperties($dataClass, $propertySources, $state->context);

        if ($state->context->prepareDataHooks !== []) {
            $input = $this->mergeSources($dataClass, $propertySources, $state->context);

            foreach ($state->context->prepareDataHooks as $hook) {
                $input = $hook($input);
            }

            $propertySources = [$input];
            $resolvedProperties = $this->resolveProperties($dataClass, $propertySources, $state->context);
        }

        $class = $this->resolveMorphClass($dataClass, $resolvedProperties);

        if ($class !== $dataClass->name) {
            $dataClass = $this->dataClasses->get($class);
            $resolvedProperties = $this->resolveProperties($dataClass, $propertySources, $state->context);
        }

        $state->setNodeClass($class);

        if ($shouldValidate && $dataClass->failOnUnknownFields) {
            $state->recordUnknownInput(
                $this->mergeSources($dataClass, $unknownInputSources, $state->context),
            );
        }

        $this->fillResolvedProperties(
            $dataClass,
            $resolvedProperties,
            $sources,
            $payloads,
            $state,
            $extensions,
            $shouldValidate,
            $compilesRules,
            false,
        );

        return null;
    }

    /**
     * Create one exact array node without entering the general Fill path.
     *
     * A miss remains in the current invocation so a named factory is never matched twice.
     *
     * @param array<array-key, mixed> $payloads
     */
    protected function tryCreateDirectArrayNode(
        DataClass $dataClass,
        array $payloads,
        ConstructionState $state,
        bool $shouldValidate,
        bool $compilesRules,
    ): ?BaseData {
        $context = $state->context;

        if ($context->mode !== CreationMode::Create
            || $shouldValidate
            || $compilesRules
            || ! $dataClass->directArrayCreation
            || count($payloads) !== 1
            || $context->normalizers !== []
            || $context->casts !== []
            || $context->prepareDataHooks !== []
            || $context->beforeCreationHooks !== []
            || $context->afterCreationHooks !== []) {
            return null;
        }

        $payload = $payloads[array_key_first($payloads)];

        if (! is_array($payload)) {
            return null;
        }

        $properties = [];

        foreach ($dataClass->properties as $property) {
            $mappedKey = $this->propertyInputKey($property, $context);
            $match = $this->matchPropertySource($payload, $property, $mappedKey);
            $value = $match === null ? UnknownProperty::create() : $match[1];

            if ($value instanceof UnknownProperty) {
                // Computed values are assigned by the class and never enter construction input.
                if ($property->computed) {
                    continue;
                }

                if ($property->hasDefaultValue) {
                    continue;
                }

                if ($property->type->isOptional) {
                    $properties[$property->name] = Optional::create();

                    continue;
                }

                if ($property->type->isNullable) {
                    $properties[$property->name] = null;

                    continue;
                }

                return null;
            }

            if ($property->computed) {
                return null;
            }

            if ($value === null || $value instanceof Optional) {
                $properties[$property->name] = $value;

                continue;
            }

            if (! $property->type->acceptsValue($value)) {
                return null;
            }

            $properties[$property->name] = $value;
        }

        return $dataClass->directConstructorInstantiation
            ? $this->instantiator->instantiateDirect($dataClass, $properties)
            : $this->instantiator->instantiate($dataClass, $properties);
    }

    /**
     * Fill one node introduced or changed by a validation payload hook.
     *
     * @param class-string<BaseData> $class
     * @param OperationMemo $extensions
     */
    protected function fillHookNode(
        string $class,
        mixed $payload,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
    ): ?BaseData {
        $dataClass = $this->dataClasses->get($class);
        $match = $this->matchNamedFactory($dataClass, $state->context, [$payload]);

        if ($match !== null) {
            $payload = $this->invokeNamedFactory($dataClass, ...$match);

            if ($payload instanceof $class) {
                return $payload;
            }
        }

        $source = SourceResolver::resolve($class, $payload, []);
        $resolvedProperties = $this->resolveProperties($dataClass, [$source], $state->context);
        $class = $this->resolveMorphClass($dataClass, $resolvedProperties);

        if ($class !== $dataClass->name) {
            $dataClass = $this->dataClasses->get($class);
            $resolvedProperties = $this->resolveProperties($dataClass, [$source], $state->context);
        }

        $state->setNodeClass($class);
        $this->fillResolvedProperties(
            $dataClass,
            $resolvedProperties,
            [$source],
            [$payload],
            $state,
            $extensions,
            $shouldValidate,
            $compilesRules,
            true,
        );

        return null;
    }

    /**
     * Write one resolved data node and recursively fill its declared children.
     *
     * @param array<string, array{array-key, mixed}> $resolvedProperties
     * @param list<array|Normalized> $sources
     * @param list<mixed> $payloads
     * @param OperationMemo $extensions
     */
    protected function fillResolvedProperties(
        DataClass $dataClass,
        array $resolvedProperties,
        array $sources,
        array $payloads,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
        bool $fromValidationHook,
    ): void {
        $contextualParameters = $dataClass->contextualParameters;

        foreach ($dataClass->properties as $property) {
            [$wireKey, $value] = $resolvedProperties[$property->name];
            $state->recordMapping($property->name, $wireKey);

            if (isset($contextualParameters[$property->name])) {
                continue;
            }

            $inputPath = $property->inputPath($wireKey);
            $autoLazySource = null;

            if ($property->autoLazy !== null) {
                $autoLazySource = $this->resolveAutoLazySource(
                    $property,
                    $sources,
                    $payloads,
                    $state->context,
                );
                $state->recordAutoLazy($property->name, $autoLazySource);
            }

            if ($value instanceof UnknownProperty) {
                if ($property->autoLazy !== null
                    && $this->requiresAutoLazyReplay($property)
                    && ($property->hasDefaultValue || $this->isAutoWhenLoaded($property))
                ) {
                    $state->recordAutoLazy(
                        $property->name,
                        $autoLazySource,
                        $fromValidationHook
                            ? AutoLazyReplayMode::Hook
                            : AutoLazyReplayMode::Normal,
                    );
                }

                continue;
            }

            if ($property->computed) {
                throw CannotSetComputedValue::create($property);
            }

            if ($property->isFinishedValue($value)) {
                $state->writeFinishedPropertyValue($inputPath, $value);

                continue;
            }

            if ($property->autoLazy !== null
                && (! $compilesRules || ! $property->validate)
                && $this->requiresAutoLazyReplay($property)
                && $value !== null
                && ! $value instanceof Optional
                && ! $value instanceof Lazy
            ) {
                $state->recordAutoLazy(
                    $property->name,
                    $autoLazySource,
                    $fromValidationHook
                        ? AutoLazyReplayMode::Hook
                        : AutoLazyReplayMode::Normal,
                );
                $state->writePropertyValue($inputPath, $value);

                continue;
            }

            $this->fillResolvedProperty(
                $property,
                $inputPath,
                $value,
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
                $fromValidationHook,
            );
        }
    }

    /**
     * Write one resolved property and recursively fill its declared children.
     *
     * @param OperationMemo $extensions
     * @param non-empty-list<array-key> $inputPath
     */
    protected function fillResolvedProperty(
        DataProperty $property,
        array $inputPath,
        mixed $value,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
        bool $fromValidationHook,
    ): void {
        $dataIterable = $property->type->getDataCollectableType();
        $typedIterable = $dataIterable === null
            ? $property->type->getNonDataIterableType()
            : null;

        if ($dataIterable !== null || $typedIterable !== null) {
            $this->retainPaginatorSource(
                $property,
                $dataIterable ?? $typedIterable,
                $value,
                $inputPath,
                $state,
            );
        }

        if ($dataIterable !== null && $value instanceof LazyCollection) {
            if (! $compilesRules) {
                $state->writePropertyValue($inputPath, $value);

                return;
            }

            $value = $value->all();
        }

        if ($dataIterable !== null && $value instanceof EloquentCollection) {
            /** @var class-string<BaseData> $itemDataClass */
            $itemDataClass = $dataIterable->dataClass;
            $this->loadMissingRelations($itemDataClass, $value);
        }

        $iterableValues = $dataIterable === null ? null : $this->iterableValues($value);

        if ($dataIterable !== null && $iterableValues !== null) {
            /** @var class-string<BaseData> $itemDataClass */
            $itemDataClass = $dataIterable->dataClass;
            $state->writePropertyValue($inputPath, []);
            $state->enterProperty($property->name, $inputPath);

            try {
                foreach ($iterableValues as $key => $item) {
                    if ($item instanceof $itemDataClass) {
                        $state->writeFinishedItemValue($key, $item);

                        continue;
                    }

                    $state->writeItemValue($key, []);
                    $state->enterItem($key);

                    try {
                        $direct = $fromValidationHook
                            ? $this->fillHookNode(
                                $itemDataClass,
                                $item,
                                $state,
                                $extensions,
                                $shouldValidate,
                                $compilesRules,
                            )
                            : $this->fillNode(
                                $itemDataClass,
                                [$item],
                                $state,
                                $extensions,
                                $shouldValidate,
                                $compilesRules,
                            );
                    } finally {
                        $state->leave();
                    }

                    if ($direct !== null) {
                        $state->writeFinishedItemValue($key, $direct);
                    }
                }
            } finally {
                $state->leave();
            }

            return;
        }

        $nestedDataClass = $property->type->getDataObjectClass();

        if ($nestedDataClass !== null
            && $value !== null
            && ! $value instanceof Optional
            && ! $property->type->acceptsValue($value)
        ) {
            $state->writePropertyValue($inputPath, []);
            $state->enterProperty($property->name, $inputPath);

            try {
                $direct = $fromValidationHook
                    ? $this->fillHookNode(
                        $nestedDataClass,
                        $value,
                        $state,
                        $extensions,
                        $shouldValidate,
                        $compilesRules,
                    )
                    : $this->fillNode(
                        $nestedDataClass,
                        [$value],
                        $state,
                        $extensions,
                        $shouldValidate,
                        $compilesRules,
                    );
            } finally {
                $state->leave();
            }

            if ($direct !== null) {
                $state->writeFinishedPropertyValue($inputPath, $direct);
            }

            return;
        }

        $state->writePropertyValue($inputPath, $value);
    }

    /**
     * Apply one root payload-hook stage and reconcile changed selections.
     *
     * @param class-string<BaseData> $class
     * @param list<callable> $hooks
     * @param OperationMemo $extensions
     */
    protected function applyPayloadHooks(
        string $class,
        array $hooks,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
        bool $reconcile = true,
        bool $collection = false,
    ): void {
        $previousPayload = $state->payload();
        $payload = $previousPayload;

        foreach ($hooks as $hook) {
            $payload = $hook($payload);
        }

        if ($payload === $previousPayload) {
            return;
        }

        $state->replacePayload($payload);

        if ($reconcile) {
            if ($collection) {
                $this->reconcileCollection(
                    $class,
                    $previousPayload,
                    $payload,
                    $state,
                    $extensions,
                    $shouldValidate,
                    $compilesRules,
                );
            } else {
                $this->reconcileNode(
                    $class,
                    $previousPayload,
                    $payload,
                    $state,
                    $extensions,
                    $shouldValidate,
                    $compilesRules,
                );
            }
        }
    }

    /**
     * Reconcile changed eager root collection items.
     *
     * @param class-string<BaseData> $class
     * @param array<array-key, mixed> $previousPayload
     * @param array<array-key, mixed> $payload
     * @param OperationMemo $extensions
     */
    protected function reconcileCollection(
        string $class,
        array $previousPayload,
        array $payload,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
    ): void {
        foreach ($payload as $key => $item) {
            $previousItem = $previousPayload[$key] ?? UnknownProperty::create();

            if ($item === $previousItem) {
                continue;
            }

            if ($item instanceof $class) {
                $state->enterItem($key);

                try {
                    $state->resetNodeStructure();
                } finally {
                    $state->leave();
                }

                $state->writeFinishedItemValue($key, $item);

                continue;
            }

            if (is_array($previousItem) && is_array($item)) {
                $state->enterItem($key);

                try {
                    if ($state->nodeClass() !== null) {
                        $this->reconcileNode(
                            $class,
                            $previousItem,
                            $item,
                            $state,
                            $extensions,
                            $shouldValidate,
                            $compilesRules,
                        );

                        continue;
                    }
                } finally {
                    $state->leave();
                }
            }

            $state->writeItemValue($key, []);
            $state->enterItem($key);

            try {
                $state->resetNodeStructure();
                $direct = $this->fillHookNode(
                    $class,
                    $item,
                    $state,
                    $extensions,
                    $shouldValidate,
                    $compilesRules,
                );
            } finally {
                $state->leave();
            }

            if ($direct !== null) {
                $state->writeFinishedItemValue($key, $direct);
            }
        }
    }

    /**
     * Reconcile one changed assembled data node without replaying prepare hooks.
     *
     * @param class-string<BaseData> $declaredClass
     * @param array<array-key, mixed> $previousPayload
     * @param array<array-key, mixed> $payload
     * @param OperationMemo $extensions
     */
    protected function reconcileNode(
        string $declaredClass,
        array $previousPayload,
        array $payload,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
    ): void {
        $declaredDataClass = $this->dataClasses->get($declaredClass);
        $declaredProperties = $this->resolveProperties(
            $declaredDataClass,
            [$payload],
            $state->context,
        );
        $class = $this->resolveMorphClass($declaredDataClass, $declaredProperties);
        $previousClass = $state->nodeClass() ?? $declaredClass;

        if ($class !== $previousClass) {
            $dataClass = $this->dataClasses->get($class);
            $resolvedProperties = $class === $declaredClass
                ? $declaredProperties
                : $this->resolveProperties($dataClass, [$payload], $state->context);
            $state->resetNodeStructure();
            $state->setNodeClass($class);
            $this->fillResolvedProperties(
                $dataClass,
                $resolvedProperties,
                [$payload],
                [$payload],
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
                true,
            );

            return;
        }

        $dataClass = $this->dataClasses->get($class);
        $resolvedProperties = $class === $declaredClass
            ? $declaredProperties
            : $this->resolveProperties($dataClass, [$payload], $state->context);
        $contextualParameters = $dataClass->contextualParameters;

        foreach ($dataClass->properties as $property) {
            $previousWireKey = $state->originalKey($property->name);
            $previousValue = SourceReader::read(
                $previousPayload,
                $property->inputPath($previousWireKey),
                $property,
            );
            [$wireKey, $value] = $resolvedProperties[$property->name];

            if ($wireKey !== $previousWireKey) {
                $state->replaceMapping($property->name, $wireKey);
            }

            if ($value === $previousValue || isset($contextualParameters[$property->name])) {
                continue;
            }

            if (! $value instanceof UnknownProperty && $property->computed) {
                throw CannotSetComputedValue::create($property);
            }

            $this->reconcileProperty(
                $property,
                $previousValue,
                $value,
                $wireKey,
                $payload,
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
            );
        }
    }

    /**
     * Reconcile one changed property selection.
     *
     * @param OperationMemo $extensions
     */
    protected function reconcileProperty(
        DataProperty $property,
        mixed $previousValue,
        mixed $value,
        string|int $wireKey,
        array $payload,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
    ): void {
        $inputPath = $property->inputPath($wireKey);
        $autoLazySource = null;

        if ($property->autoLazy !== null) {
            $autoLazySource = $this->resolveAutoLazySource(
                $property,
                [$payload],
                [$payload],
                $state->context,
            );
            $state->recordAutoLazy($property->name, $autoLazySource);
        }

        $dataIterable = $property->type->getDataCollectableType();
        $typedIterable = $dataIterable === null
            ? $property->type->getNonDataIterableType()
            : null;

        if ($property->autoLazy !== null
            && (! $compilesRules || ! $property->validate)
            && $this->requiresAutoLazyReplay($property)
            && (
                ($value instanceof UnknownProperty
                    && ($property->hasDefaultValue || $this->isAutoWhenLoaded($property)))
                || ($value !== null
                    && ! $value instanceof UnknownProperty
                    && ! $value instanceof Optional
                    && ! $value instanceof Lazy
                    && ! $property->isFinishedValue($value))
            )
        ) {
            $state->clearChildStructure($property->name);
            $state->recordAutoLazy(
                $property->name,
                $autoLazySource,
                AutoLazyReplayMode::Hook,
            );

            return;
        }

        if ($dataIterable !== null || $typedIterable !== null) {
            $this->retainPaginatorSource(
                $property,
                $dataIterable ?? $typedIterable,
                $value,
                $inputPath,
                $state,
            );
        }

        if ($dataIterable !== null) {
            $this->reconcileDataIterable(
                $property,
                $dataIterable,
                $previousValue,
                $value,
                $inputPath,
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
            );

            return;
        }

        $nestedDataClass = $property->type->getDataObjectClass();

        if ($nestedDataClass === null) {
            return;
        }

        if ($value instanceof UnknownProperty
            || $value === null
            || $value instanceof Optional
            || $property->type->acceptsValue($value)
        ) {
            $state->clearChildStructure($property->name);

            if ($value instanceof BaseData) {
                $state->writeFinishedPropertyValue($inputPath, $value);
            }

            return;
        }

        if (is_array($previousValue) && is_array($value)) {
            $state->enterProperty($property->name, $inputPath);

            try {
                if ($state->nodeClass() !== null) {
                    $this->reconcileNode(
                        $nestedDataClass,
                        $previousValue,
                        $value,
                        $state,
                        $extensions,
                        $shouldValidate,
                        $compilesRules,
                    );

                    return;
                }
            } finally {
                $state->leave();
            }
        }

        $state->clearChildStructure($property->name);
        $state->writePropertyValue($inputPath, []);
        $state->enterProperty($property->name, $inputPath);

        try {
            $state->resetNodeStructure();
            $direct = $this->fillHookNode(
                $nestedDataClass,
                $value,
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
            );
        } finally {
            $state->leave();
        }

        if ($direct !== null) {
            $state->writeFinishedPropertyValue($inputPath, $direct);
        }
    }

    /**
     * Reconcile one changed typed data iterable.
     *
     * @param OperationMemo $extensions
     * @param non-empty-list<array-key> $inputPath
     */
    protected function reconcileDataIterable(
        DataProperty $property,
        NamedType $type,
        mixed $previousValue,
        mixed $value,
        array $inputPath,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
    ): void {
        if ($property->isFinishedValue($value)) {
            $state->clearChildStructure($property->name);
            $state->writeFinishedPropertyValue($inputPath, $value);

            return;
        }

        if ($value instanceof LazyCollection) {
            if (! $compilesRules) {
                $state->clearChildStructure($property->name);

                return;
            }

            $value = $value->all();
            $state->writePropertyValue($inputPath, $value);
        }

        $values = $this->iterableValues($value);

        if ($values === null) {
            $state->clearChildStructure($property->name);

            return;
        }

        $previousValues = $this->iterableValues($previousValue) ?? [];
        /** @var class-string<BaseData> $itemDataClass */
        $itemDataClass = $type->dataClass;
        $state->enterProperty($property->name, $inputPath);

        try {
            foreach ($values as $key => $item) {
                $previousItem = $previousValues[$key] ?? UnknownProperty::create();

                if ($item === $previousItem) {
                    continue;
                }

                if ($item instanceof $itemDataClass) {
                    $state->enterItem($key);

                    try {
                        $state->resetNodeStructure();
                    } finally {
                        $state->leave();
                    }

                    $state->writeFinishedItemValue($key, $item);

                    continue;
                }

                if (is_array($previousItem) && is_array($item)) {
                    $state->enterItem($key);

                    try {
                        if ($state->nodeClass() !== null) {
                            $this->reconcileNode(
                                $itemDataClass,
                                $previousItem,
                                $item,
                                $state,
                                $extensions,
                                $shouldValidate,
                                $compilesRules,
                            );

                            continue;
                        }
                    } finally {
                        $state->leave();
                    }
                }

                $state->writeItemValue($key, []);
                $state->enterItem($key);

                try {
                    $state->resetNodeStructure();
                    $direct = $this->fillHookNode(
                        $itemDataClass,
                        $item,
                        $state,
                        $extensions,
                        $shouldValidate,
                        $compilesRules,
                    );
                } finally {
                    $state->leave();
                }

                if ($direct !== null) {
                    $state->writeFinishedItemValue($key, $direct);
                }
            }
        } finally {
            $state->leave();
        }
    }

    /**
     * Cast and instantiate the node at the current construction path.
     *
     * @param OperationMemo $extensions
     */
    protected function castAndInstantiateNode(
        ConstructionState $state,
        array &$extensions,
    ): BaseData {
        $class = $state->nodeClass() ?? $state->context->dataClass;
        $dataClass = $this->dataClasses->get($class);
        $properties = [];
        $contextualParameters = $dataClass->contextualParameters;

        foreach ($dataClass->properties as $property) {
            if (isset($contextualParameters[$property->name])) {
                continue;
            }

            $wireKey = $state->originalKey($property->name);
            $inputPath = $property->inputPath($wireKey);

            if (! $state->hasValue($inputPath)) {
                if ($property->autoLazy !== null && $property->hasDefaultValue) {
                    $value = $this->propertyDefaultValue($dataClass, $property);
                    $state->writePropertyValue($inputPath, $value);
                    $properties[$property->name] = $this->buildAutoLazy(
                        $property,
                        $value,
                        $state,
                        $extensions,
                    );

                    continue;
                }

                if ($property->autoLazy !== null && $this->isAutoWhenLoaded($property)) {
                    $properties[$property->name] = $this->buildAutoLazy(
                        $property,
                        UnknownProperty::create(),
                        $state,
                        $extensions,
                    );

                    continue;
                }

                if ($property->hasDefaultValue) {
                    continue;
                }

                if ($property->type->isOptional) {
                    $properties[$property->name] = Optional::create();
                } elseif ($property->type->isNullable) {
                    $properties[$property->name] = null;
                }

                continue;
            }

            $value = $state->getValue($inputPath);
            $properties[$property->name] = $property->autoLazy === null
                ? $this->castProperty($property, $value, $state, $extensions)
                : $this->buildAutoLazy($property, $value, $state, $extensions);
        }

        foreach ($state->context->beforeCreationHooks as $hook) {
            $properties = $hook($properties);
        }

        $data = $this->instantiator->instantiate($dataClass, $properties);

        foreach ($state->context->afterCreationHooks as $hook) {
            $data = $hook($data);

            if (! $data instanceof $class) {
                throw CannotCreateData::invalidAfterCreationResult($dataClass, $data);
            }
        }

        return $data;
    }

    /**
     * Build one automatic lazy property around the ordinary cast path.
     *
     * @param OperationMemo $extensions
     */
    protected function buildAutoLazy(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        array &$extensions,
    ): mixed {
        if ($value === null || $value instanceof Optional || $value instanceof Lazy) {
            return $value;
        }

        /** @var array{source: mixed, replay?: AutoLazyReplayMode} $recipe */
        $recipe = $state->autoLazy($property->name);
        $snapshot = $state->snapshotForProperty($property->name);
        $propertyName = $property->name;
        $replay = $recipe['replay'] ?? null;
        $castValue = static function (mixed $resolvedValue) use (
            $propertyName,
            $replay,
            $snapshot,
        ): mixed {
            $state = clone $snapshot;
            /** @var self $creator */
            $creator = ConcreteContainer::getInstance()->make(self::class);

            return $creator->resolveAutoLazyProperty(
                $propertyName,
                $resolvedValue,
                $state,
                $replay,
            );
        };

        return $this->resolveAutoLazy($property, $extensions)->build(
            $castValue,
            $recipe['source'],
            $property,
            $value,
        );
    }

    /**
     * Cast one supplied property value.
     *
     * @param OperationMemo $extensions
     */
    protected function castProperty(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        array &$extensions,
    ): mixed {
        if ($value === null || $value instanceof Optional) {
            return $value;
        }

        if ($property->isFinishedValue($value)) {
            return $value;
        }

        $dataIterable = $property->type->getDataCollectableType();
        $shouldCast = ! is_object($value)
            || $dataIterable !== null
            || ! $property->type->acceptsValue($value);
        $casts = $shouldCast
            ? $this->propertyCasts($property, $state->context, $extensions)
            : [];

        foreach ($casts as $cast) {
            $casted = $cast->cast($property, $value, $state, $state->context);

            if (! $casted instanceof Uncastable) {
                return $casted;
            }
        }

        if ($dataIterable !== null) {
            return $this->castDataIterable($property, $dataIterable, $value, $state, $extensions);
        }

        $iterable = $property->type->getNonDataIterableType();

        if ($iterable !== null) {
            return $this->castTypedIterable($property, $iterable, $value, $state, $extensions, $casts);
        }

        // The exact-array exit relies on accepted values passing before the fallback conversions below.
        if ($property->type->acceptsValue($value)) {
            return $value;
        }

        $wireKey = $state->originalKey($property->name);
        $state->enterProperty($property->name, $property->inputPath($wireKey));

        try {
            if ($state->nodeClass() !== null) {
                return $this->castAndInstantiateNode($state, $extensions);
            }
        } finally {
            $state->leave();
        }

        $dataObjectTypes = $property->type->getDataObjectTypes();

        if (count($dataObjectTypes) > 1) {
            $candidates = [];

            foreach ($dataObjectTypes as $dataObjectType) {
                /** @var class-string<BaseData> $candidate */
                $candidate = $dataObjectType->dataClass;
                $candidates[] = $candidate;
            }

            throw CannotCreateData::ambiguousDataObjectUnion($property, $candidates);
        }

        foreach ($property->type->getNamedTypes() as $type) {
            if (! $type->isCastable) {
                continue;
            }

            $key = 'castable:' . $type->name;
            /** @var CastableCast $cast */
            $cast = $extensions[$key] ??= new CastableCast($type->name);
            $casted = $cast->cast($property, $value, $state, $state->context);

            if (! $casted instanceof Uncastable) {
                return $casted;
            }
        }

        $dateType = $property->type->findAcceptedTypeForBaseType(DateTimeInterface::class);

        if ($dateType !== null) {
            $key = 'date:' . $dateType;
            /** @var DateTimeInterfaceCast $cast */
            $cast = $extensions[$key] ??= new DateTimeInterfaceCast(type: $dateType);

            return $cast->cast(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        $enumType = $property->type->findAcceptedTypeForBaseType(BackedEnum::class);

        if ($enumType !== null) {
            $key = 'enum:' . $enumType;
            /** @var EnumCast $cast */
            $cast = $extensions[$key] ??= new EnumCast($enumType);

            return $cast->cast(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        $builtin = $property->type->type->getSingleBuiltinType();

        if ($builtin !== null) {
            $key = 'builtin:' . $builtin;
            /** @var BuiltinTypeCast $cast */
            $cast = $extensions[$key] ??= new BuiltinTypeCast($builtin);

            return $cast->cast(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        return $value;
    }

    /**
     * Cast every item in one declared data iterable.
     *
     * @param OperationMemo $extensions
     */
    protected function castDataIterable(
        DataProperty $property,
        NamedType $type,
        mixed $value,
        ConstructionState $state,
        array &$extensions,
    ): mixed {
        /** @var class-string<BaseData> $dataClass */
        $dataClass = $type->dataClass;

        if ($value instanceof LazyCollection
            && ! $type->kind->isPaginator()
            && ! $type->kind->isCursorPaginator()
        ) {
            return $value->map(
                function (mixed $item) use ($dataClass, $state, &$extensions): BaseData {
                    return $this->createUnvalidatedNode(
                        $dataClass,
                        $state->context,
                        $item,
                        $extensions,
                    );
                },
            );
        }

        $values = $this->iterableValues($value);

        if ($values === null) {
            return $value;
        }

        $items = [];
        $wireKey = $state->originalKey($property->name);
        $state->enterProperty($property->name, $property->inputPath($wireKey));

        try {
            foreach ($values as $key => $item) {
                if ($item instanceof $dataClass) {
                    $items[$key] = $item;

                    continue;
                }

                $state->enterItem($key);

                try {
                    $items[$key] = $state->nodeClass() === null
                        ? $this->createUnvalidatedNode(
                            $dataClass,
                            $state->context,
                            $item,
                            $extensions,
                        )
                        : $this->castAndInstantiateNode($state, $extensions);
                } finally {
                    $state->leave();
                }
            }

            return $this->dataCollectables->forProperty(
                $type,
                $items,
                $state,
            );
        } finally {
            $state->leave();
        }
    }

    /**
     * Cast every item in one declared non-data iterable.
     *
     * @param OperationMemo $extensions
     * @param list<Cast> $casts
     */
    protected function castTypedIterable(
        DataProperty $property,
        NamedType $type,
        mixed $value,
        ConstructionState $state,
        array &$extensions,
        array $casts,
    ): mixed {
        if ($value instanceof LazyCollection
            && ! $type->kind->isPaginator()
            && ! $type->kind->isCursorPaginator()
        ) {
            return $value->map(function (mixed $item) use ($property, $type, $state, &$extensions, $casts): mixed {
                return $this->castIterableItem(
                    $property,
                    $type->iterableItemType,
                    $item,
                    $state,
                    $extensions,
                    $casts,
                );
            });
        }

        $values = $this->iterableValues($value);

        if ($values === null) {
            return $value;
        }

        $items = [];
        $wireKey = $state->originalKey($property->name);
        $state->enterProperty($property->name, $property->inputPath($wireKey));

        try {
            foreach ($values as $key => $item) {
                $items[$key] = $this->castIterableItem(
                    $property,
                    $type->iterableItemType,
                    $item,
                    $state,
                    $extensions,
                    $casts,
                );
            }

            return $this->dataCollectables->forProperty($type, $items, $state);
        } finally {
            $state->leave();
        }
    }

    /**
     * Cast one declared iterable item.
     *
     * @param OperationMemo $extensions
     * @param list<Cast> $casts
     */
    protected function castIterableItem(
        DataProperty $property,
        Type $type,
        mixed $value,
        ConstructionState $state,
        array &$extensions,
        array $casts,
    ): mixed {
        if ($value === null) {
            return $value;
        }

        foreach ($casts as $cast) {
            if (! $cast instanceof IterableItemCast) {
                continue;
            }

            $casted = $cast->castIterableItem($property, $value, $state, $state->context);

            if (! $casted instanceof Uncastable) {
                return $casted;
            }
        }

        if ($type->acceptsValue($value)) {
            return $value;
        }

        foreach ($type->getNamedTypes() as $namedType) {
            if (! $namedType->isCastable) {
                continue;
            }

            $key = 'iterable-castable:' . $namedType->name;
            /** @var CastableCast $cast */
            $cast = $extensions[$key] ??= new CastableCast($namedType->name);
            $casted = $cast->cast($property, $value, $state, $state->context);

            if (! $casted instanceof Uncastable) {
                return $casted;
            }
        }

        $dateType = $type->findAcceptedTypeForBaseType(DateTimeInterface::class);

        if ($dateType !== null) {
            $key = 'date:' . $dateType;
            /** @var DateTimeInterfaceCast $cast */
            $cast = $extensions[$key] ??= new DateTimeInterfaceCast(type: $dateType);

            return $cast->castIterableItem(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        $enumType = $type->findAcceptedTypeForBaseType(BackedEnum::class);

        if ($enumType !== null) {
            $key = 'enum:' . $enumType;
            /** @var EnumCast $cast */
            $cast = $extensions[$key] ??= new EnumCast($enumType);

            return $cast->castIterableItem(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        $builtin = $type->getSingleBuiltinType();

        if ($builtin !== null) {
            $key = 'builtin:' . $builtin;
            /** @var BuiltinTypeCast $cast */
            $cast = $extensions[$key] ??= new BuiltinTypeCast($builtin);

            return $cast->castIterableItem(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        return $value;
    }

    /**
     * Resolve one automatic lazy attribute for the current root operation.
     *
     * @param OperationMemo $extensions
     */
    protected function resolveAutoLazy(DataProperty $property, array &$extensions): AutoLazy
    {
        $attribute = $property->autoLazy;
        $key = 'auto-lazy:' . spl_object_id($attribute);

        if (! isset($extensions[$key])) {
            $extensions[$key] = $attribute->newInstance();
        }

        /** @var AutoLazy $autoLazy */
        $autoLazy = $extensions[$key];

        return $autoLazy;
    }

    /**
     * Get the ordered custom casts applicable to a property.
     *
     * @param OperationMemo $extensions
     * @return list<Cast>
     */
    protected function propertyCasts(
        DataProperty $property,
        CreationContext $context,
        array &$extensions,
    ): array {
        $casts = [];

        if ($property->cast !== null) {
            $key = 'attribute-cast:' . spl_object_id($property->cast);

            if (! isset($extensions[$key])) {
                /** @var GetsCast $attribute */
                $attribute = $property->cast->newInstance();
                $extensions[$key] = $attribute->get();
            }

            $casts[] = $extensions[$key];
        }

        foreach ($context->casts as $baseType => $cast) {
            if ($property->type->findAcceptedTypeForBaseType($baseType) !== null) {
                $casts[] = $this->resolveCast($cast, $extensions);
            }
        }

        foreach ($property->configuredCasts as $cast) {
            $casts[] = $this->resolveCast($cast, $extensions);
        }

        return $casts;
    }

    /**
     * Resolve one cast once for the current root operation.
     *
     * @param Cast|class-string<Cast> $cast
     * @param OperationMemo $extensions
     */
    protected function resolveCast(Cast|string $cast, array &$extensions): Cast
    {
        if ($cast instanceof Cast) {
            return $cast;
        }

        $key = 'cast:' . $cast;

        /** @var Cast */
        return $extensions[$key] ??= $this->container->make($cast);
    }

    /**
     * Resolve the custom normalizers for one data class.
     *
     * @param OperationMemo $extensions
     * @return list<Normalizer>
     */
    protected function resolveNormalizers(
        DataClass $dataClass,
        CreationContext $context,
        array &$extensions,
    ): array {
        // Avoid building a memo key on the common path with no custom normalizers.
        if ($context->normalizers === []
            && $this->config->normalizers === []
            && ! $dataClass->hasLifecycleMethod('normalizers')
        ) {
            return [];
        }

        $key = 'normalizers:' . $dataClass->name;

        if (isset($extensions[$key])) {
            /** @var list<Normalizer> $normalizers */
            $normalizers = $extensions[$key];

            return $normalizers;
        }

        $normalizers = [];

        if ($dataClass->hasLifecycleMethod('normalizers')) {
            $class = $dataClass->name;
            /** @var list<class-string<Normalizer>|Normalizer> $normalizers */
            $normalizers = $this->container->call($class::normalizers(...));
        }

        array_push($normalizers, ...$context->normalizers, ...$this->config->normalizers);

        foreach ($normalizers as $index => $normalizer) {
            if ($normalizer instanceof Normalizer) {
                continue;
            }

            $normalizerKey = 'normalizer:' . $normalizer;

            if (! isset($extensions[$normalizerKey])) {
                /** @var Normalizer $resolvedNormalizer */
                $resolvedNormalizer = $this->container->make($normalizer);
                $extensions[$normalizerKey] = $resolvedNormalizer;
            }

            /** @var Normalizer $resolvedNormalizer */
            $resolvedNormalizer = $extensions[$normalizerKey];
            $normalizers[$index] = $resolvedNormalizer;
        }

        return $extensions[$key] = array_values($normalizers);
    }

    /**
     * Resolve every declared property against the normalized sources.
     *
     * @param list<array|Normalized> $sources
     * @return array<string, array{array-key, mixed}>
     */
    protected function resolveProperties(
        DataClass $dataClass,
        array $sources,
        CreationContext $context,
    ): array {
        $properties = [];

        foreach ($dataClass->properties as $property) {
            $properties[$property->name] = $this->resolveProperty($sources, $property, $context);
        }

        return $properties;
    }

    /**
     * Resolve one property with mapped-key precedence inside each source.
     *
     * @param list<array|Normalized> $sources
     * @return array{array-key, mixed}
     */
    protected function resolveProperty(
        array $sources,
        DataProperty $property,
        CreationContext $context,
    ): array {
        $mappedKey = $this->propertyInputKey($property, $context);

        foreach ($sources as $source) {
            $match = $this->matchPropertySource($source, $property, $mappedKey);

            if ($match !== null) {
                return $match;
            }
        }

        return [$mappedKey, UnknownProperty::create()];
    }

    /**
     * Resolve the raw source aligned with one automatic lazy property.
     *
     * @param list<array|Normalized> $sources
     * @param list<mixed> $payloads
     */
    protected function resolveAutoLazySource(
        DataProperty $property,
        array $sources,
        array $payloads,
        CreationContext $context,
    ): mixed {
        if ($this->isAutoWhenLoaded($property)) {
            foreach ($payloads as $payload) {
                if ($payload instanceof Model) {
                    return $payload;
                }
            }

            throw CannotCreateData::autoWhenLoadedRequiresModel($property);
        }

        $mappedKey = $this->propertyInputKey($property, $context);

        foreach ($sources as $index => $source) {
            if ($this->matchPropertySource($source, $property, $mappedKey) !== null) {
                return $payloads[$index];
            }
        }

        return $payloads[0] ?? [];
    }

    /**
     * Match one property against one normalized source.
     *
     * @return null|array{array-key, mixed}
     */
    protected function matchPropertySource(
        array|Normalized $source,
        DataProperty $property,
        string|int $mappedKey,
    ): ?array {
        $value = SourceReader::read($source, $property->inputPath($mappedKey), $property);

        if (! $value instanceof UnknownProperty) {
            return [$mappedKey, $value];
        }

        if ($mappedKey === $property->name) {
            return null;
        }

        $value = SourceReader::read($source, $property->inputPath($property->name), $property);

        return $value instanceof UnknownProperty
            ? null
            : [$property->name, $value];
    }

    /**
     * Get the effective input key for one property.
     */
    protected function propertyInputKey(
        DataProperty $property,
        CreationContext $context,
    ): string|int {
        return $context->mapPropertyNames
            ? ($property->inputMappedName ?? $property->name)
            : $property->name;
    }

    /**
     * Merge normalized sources for a prepare-data hook.
     *
     * @param list<array|Normalized> $sources
     * @return array<array-key, mixed>
     */
    protected function mergeSources(
        DataClass $dataClass,
        array $sources,
        CreationContext $context,
    ): array {
        $merged = [];

        foreach ($sources as $source) {
            $values = is_array($source)
                ? $source
                : $this->projectNormalizedSource($dataClass, $source, $context);
            $merged = $this->mergeMissingValues($merged, $values);
        }

        return $merged;
    }

    /**
     * Project a non-enumerable normalized source into declared wire keys.
     *
     * @return array<array-key, mixed>
     */
    protected function projectNormalizedSource(
        DataClass $dataClass,
        Normalized $source,
        CreationContext $context,
    ): array {
        $values = [];

        foreach ($dataClass->properties as $property) {
            [$key, $value] = $this->resolveProperty([$source], $property, $context);

            if ($value instanceof UnknownProperty) {
                continue;
            }

            if (is_int($key)) {
                $values[$key] = $value;
            } else {
                data_set($values, $key, $value);
            }
        }

        return $values;
    }

    /**
     * Recursively fill only values absent from the earlier source.
     *
     * @param array<array-key, mixed> $target
     * @param array<array-key, mixed> $source
     * @return array<array-key, mixed>
     */
    protected function mergeMissingValues(array $target, array $source): array
    {
        foreach ($source as $key => $value) {
            if (! array_key_exists($key, $target)) {
                $target[$key] = $value;

                continue;
            }

            if (is_array($target[$key]) && is_array($value)) {
                $target[$key] = $this->mergeMissingValues($target[$key], $value);
            }
        }

        return $target;
    }

    /**
     * Find the first compatible named object factory.
     *
     * @param array<array-key, mixed> $payloads
     * @return null|array{DataMethod, DataMethodMatch}
     */
    protected function matchNamedFactory(
        DataClass $dataClass,
        CreationContext $context,
        array $payloads,
    ): ?array {
        if ($context->disableMagicalCreation) {
            return null;
        }

        foreach ($dataClass->methods as $method) {
            if ($method->customCreationMethodType !== CustomCreationMethodType::Object
                || in_array($method->name, $context->ignoredMagicalMethods, true)
            ) {
                continue;
            }

            $match = $method->matchPayloads($context, ...$payloads);

            if ($match !== null) {
                return [$method, $match];
            }
        }

        return null;
    }

    /**
     * Find the first compatible named collection factory.
     */
    protected function matchNamedCollectionFactory(
        DataClass $dataClass,
        CreationContext $context,
        mixed $items,
        ?string $into,
    ): ?array {
        if ($context->disableMagicalCreation) {
            return null;
        }

        foreach ($dataClass->methods as $method) {
            if ($method->customCreationMethodType !== CustomCreationMethodType::Collection
                || in_array($method->name, $context->ignoredMagicalMethods, true)
                || ($into !== null && ! $method->returns($into))
            ) {
                continue;
            }

            $match = $method->matchPayloads($context, $items);

            if ($match !== null) {
                return [$method, $match];
            }
        }

        return null;
    }

    /**
     * Invoke one matched named factory without method-binding interception.
     */
    protected function invokeNamedFactory(
        DataClass $dataClass,
        DataMethod $method,
        DataMethodMatch $match,
    ): mixed {
        $class = $dataClass->name;
        $methodName = $method->name;

        return $match->requiresContainerCall
            ? $this->container->call($class::$methodName(...), $match->arguments)
            : $class::$methodName(...$match->arguments);
    }

    /**
     * Resolve the concrete class selected by an abstract property's discriminator.
     *
     * @param array<string, array{array-key, mixed}> $resolvedProperties
     * @return class-string<BaseData>
     */
    protected function resolveMorphClass(DataClass $dataClass, array $resolvedProperties): string
    {
        if (! $dataClass->isAbstract) {
            return $dataClass->name;
        }

        if (! $dataClass->propertyMorphable) {
            throw CannotCreateAbstractClass::morphClassWasNotResolved($dataClass->name);
        }

        $properties = [];

        foreach ($dataClass->properties as $property) {
            if (! $property->morphable) {
                continue;
            }

            $value = $resolvedProperties[$property->name][1];

            if ($value instanceof UnknownProperty) {
                $value = $this->propertyDefaultValue($dataClass, $property);
            }

            if ($value instanceof UnknownProperty) {
                throw CannotCreateAbstractClass::morphClassWasNotResolved($dataClass->name);
            }

            $enum = $property->type->findAcceptedTypeForBaseType(BackedEnum::class);

            if ($enum !== null && (is_int($value) || is_string($value))) {
                $value = $enum::tryFrom($value) ?? $value;
            }

            $properties[$property->name] = $value;
        }

        /** @var class-string<BaseData&PropertyMorphableData> $class */
        $class = $dataClass->name;
        $resolvedClass = $class::morph($properties);

        if ($resolvedClass === null) {
            throw CannotCreateAbstractClass::morphClassWasNotResolved($class);
        }

        if (! is_a($resolvedClass, $class, true)) {
            throw CannotCreateAbstractClass::invalidMorphClass($class, $resolvedClass);
        }

        if ($this->dataClasses->get($resolvedClass)->isAbstract) {
            throw CannotCreateAbstractClass::invalidMorphClass($class, $resolvedClass);
        }

        return $resolvedClass;
    }

    /**
     * Materialize a property default only when morph selection requires it.
     */
    protected function propertyDefaultValue(DataClass $dataClass, DataProperty $property): mixed
    {
        if (! $property->hasDefaultValue) {
            return UnknownProperty::create();
        }

        if (! $property->isConstructorParameter) {
            return $property->reflection->getDefaultValue();
        }

        foreach ($dataClass->constructorParameters as $parameter) {
            if ($parameter->name === $property->name) {
                return $parameter->reflection->getDefaultValue();
            }
        }

        return UnknownProperty::create();
    }

    /**
     * Determine if an automatic lazy property needs deferred Fill replay.
     */
    protected function requiresAutoLazyReplay(DataProperty $property): bool
    {
        if ($property->type->getDataObjectClass() !== null
            || $property->type->getDataCollectableType() !== null
        ) {
            return true;
        }

        $type = $property->type->getNonDataIterableType();

        return $type !== null
            && ($type->kind->isPaginator() || $type->kind->isCursorPaginator());
    }

    /**
     * Determine if a property uses model relation automatic lazy loading.
     */
    protected function isAutoWhenLoaded(DataProperty $property): bool
    {
        return $property->autoLazy !== null
            && is_a($property->autoLazy->getName(), AutoWhenLoadedLazy::class, true);
    }

    /**
     * Retain source metadata for one declared paginator property.
     *
     * @param non-empty-list<array-key> $inputPath
     */
    protected function retainPaginatorSource(
        DataProperty $property,
        NamedType $type,
        mixed $value,
        array $inputPath,
        ConstructionState $state,
    ): void {
        if (! $type->kind->isPaginator() && ! $type->kind->isCursorPaginator()) {
            return;
        }

        $state->enterProperty($property->name, $inputPath);

        try {
            $this->dataCollectables->retainPaginatorSource($type, $value, $state);
        } finally {
            $state->leave();
        }
    }

    /**
     * Convert an eager iterable to its keyed values.
     *
     * @return null|array<array-key, mixed>
     */
    protected function iterableValues(mixed $value): ?array
    {
        return $this->dataCollectables->items($value);
    }
}
