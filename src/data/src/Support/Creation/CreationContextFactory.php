<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Closure;
use Hypervel\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Hypervel\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;
use Traversable;

/**
 * @template TData of BaseData
 */
class CreationContextFactory
{
    protected ValidationStrategy $validationStrategy;

    protected bool $mapPropertyNames = true;

    protected bool $disableMagicalCreation = false;

    /** @var list<string> */
    protected array $ignoredMagicalMethods = [];

    /** @var array<string, Cast|class-string<Cast>> */
    protected array $casts = [];

    /** @var list<class-string<Normalizer>|Normalizer> */
    protected array $normalizers = [];

    /** @var list<Closure> */
    protected array $prepareDataHooks = [];

    /** @var list<Closure> */
    protected array $beforeValidationHooks = [];

    /** @var list<Closure> */
    protected array $beforeRulesHooks = [];

    /** @var list<Closure> */
    protected array $afterRulesHooks = [];

    /** @var list<Closure> */
    protected array $withValidatorHooks = [];

    /** @var list<Closure> */
    protected array $afterValidationHooks = [];

    /** @var list<Closure> */
    protected array $beforeCreationHooks = [];

    /** @var list<Closure> */
    protected array $afterCreationHooks = [];

    /**
     * Create a fresh data construction factory.
     *
     * @param class-string<TData> $dataClass
     */
    public function __construct(
        protected readonly DataCreator $creator,
        protected readonly DataConfig $config,
        public readonly string $dataClass,
        protected ?CreationContext $createContext = null,
    ) {
        $this->validationStrategy = $this->config->validationStrategy;
    }

    /**
     * Set the validation strategy.
     *
     * @return $this
     */
    public function validationStrategy(ValidationStrategy $validationStrategy): static
    {
        $this->validationStrategy = $validationStrategy;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Disable validation.
     */
    public function withoutValidation(): static
    {
        return $this->validationStrategy(ValidationStrategy::Disabled);
    }

    /**
     * Validate only Request sources.
     */
    public function onlyValidateRequests(): static
    {
        return $this->validationStrategy(ValidationStrategy::OnlyRequests);
    }

    /**
     * Validate every source.
     *
     * @return $this
     */
    public function alwaysValidate(): static
    {
        return $this->validationStrategy(ValidationStrategy::Always);
    }

    /**
     * Enable or disable property-name mapping.
     */
    public function withPropertyNameMapping(bool $withPropertyNameMapping = true): static
    {
        $this->mapPropertyNames = $withPropertyNameMapping;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Disable or enable property-name mapping.
     */
    public function withoutPropertyNameMapping(bool $withoutPropertyNameMapping = true): static
    {
        $this->mapPropertyNames = ! $withoutPropertyNameMapping;
        $this->invalidateCreateContext();

        return $this;
    }

    // REMOVED: withOptionalValues()/withoutOptionalValues(); Optional declarations always preserve absence.

    /**
     * Disable or enable named creation methods.
     */
    public function withoutMagicalCreation(bool $withoutMagicalCreation = true): static
    {
        $this->disableMagicalCreation = $withoutMagicalCreation;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Enable or disable named creation methods.
     */
    public function withMagicalCreation(bool $withMagicalCreation = true): static
    {
        $this->disableMagicalCreation = ! $withMagicalCreation;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Ignore named creation methods for this operation.
     */
    public function ignoreMagicalMethod(string ...$methods): static
    {
        array_push($this->ignoredMagicalMethods, ...$methods);
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Add a cast for a declared base type.
     *
     * @param Cast|class-string<Cast> $cast
     */
    public function withCast(string $castable, Cast|string $cast): static
    {
        $this->casts[$castable] = $cast;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Merge casts for declared base types.
     *
     * @param array<string, Cast|class-string<Cast>> $casts
     */
    public function withCastCollection(array $casts): static
    {
        $this->casts = array_replace($this->casts, $casts);
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Add custom source normalizers.
     *
     * @param class-string<Normalizer>|Normalizer ...$normalizers
     */
    public function withNormalizers(Normalizer|string ...$normalizers): static
    {
        array_push($this->normalizers, ...$normalizers);
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Add a prepare-data hook.
     */
    public function prepareData(Closure $hook): static
    {
        $this->prepareDataHooks[] = $hook;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Add a before-validation hook.
     */
    public function beforeValidation(Closure $hook): static
    {
        $this->beforeValidationHooks[] = $hook;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Add a before-rules hook.
     */
    public function beforeRules(Closure $hook): static
    {
        $this->beforeRulesHooks[] = $hook;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Add an after-rules hook.
     */
    public function afterRules(Closure $hook): static
    {
        $this->afterRulesHooks[] = $hook;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Add a validator customization hook.
     */
    public function withValidator(Closure $hook): static
    {
        $this->withValidatorHooks[] = $hook;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Add an after-validation hook.
     */
    public function afterValidation(Closure $hook): static
    {
        $this->afterValidationHooks[] = $hook;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Add a before-creation hook.
     */
    public function beforeCreation(Closure $hook): static
    {
        $this->beforeCreationHooks[] = $hook;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Add an after-creation hook.
     */
    public function afterCreation(Closure $hook): static
    {
        $this->afterCreationHooks[] = $hook;
        $this->invalidateCreateContext();

        return $this;
    }

    /**
     * Build immutable options for one root operation.
     *
     * @return CreationContext<TData>
     */
    public function get(CreationMode $mode = CreationMode::Create): CreationContext
    {
        if ($mode === CreationMode::Create && $this->createContext !== null) {
            return $this->createContext;
        }

        $context = new CreationContext(
            dataClass: $this->dataClass,
            mode: $mode,
            validationStrategy: $mode === CreationMode::Create
                ? $this->validationStrategy
                : ValidationStrategy::Always,
            mapPropertyNames: $this->mapPropertyNames,
            disableMagicalCreation: $mode === CreationMode::Create
                ? $this->disableMagicalCreation
                : true,
            ignoredMagicalMethods: $this->ignoredMagicalMethods,
            casts: $this->casts,
            normalizers: $this->normalizers,
            prepareDataHooks: $this->prepareDataHooks,
            beforeValidationHooks: $this->beforeValidationHooks,
            beforeRulesHooks: $this->beforeRulesHooks,
            afterRulesHooks: $this->afterRulesHooks,
            withValidatorHooks: $this->withValidatorHooks,
            afterValidationHooks: $this->afterValidationHooks,
            beforeCreationHooks: $this->beforeCreationHooks,
            afterCreationHooks: $this->afterCreationHooks,
            dateFormats: $this->config->dateFormats,
            dateTimezone: $this->config->dateTimezone,
        );

        return $mode === CreationMode::Create
            ? $this->createContext = $context
            : $context;
    }

    /**
     * Invalidate the immutable Create context after factory customization.
     */
    protected function invalidateCreateContext(): void
    {
        $this->createContext = null;
    }

    /**
     * Create a data object.
     *
     * @return TData
     */
    public function from(mixed ...$payloads): BaseData
    {
        return $this->creator->create($this->dataClass, $this->get(), ...$payloads);
    }

    /**
     * Validate a payload without casting or construction.
     */
    public function validate(Arrayable|array $payload): Arrayable|array
    {
        return $this->creator->validate(
            $this->dataClass,
            $this->get(CreationMode::Validate),
            [$payload],
        );
    }

    /**
     * Get validation rules for a payload.
     *
     * @return array<string, list<array|object|string>>
     */
    public function getValidationRules(array $payload): array
    {
        return $this->creator->getValidationRules(
            $this->dataClass,
            $this->get(CreationMode::Rules),
            [$payload],
        );
    }

    /**
     * Collect data objects.
     *
     * Contract-typed sources retain every possible rebuildable runtime shape.
     *
     * @template TCollectKey of array-key
     * @template TCollectValue
     * @template TDataCollectionValue of BaseData
     * @template TModelValue of Model
     *
     * @param AbstractCursorPaginator<TCollectKey, TCollectValue>|AbstractPaginator<TCollectKey, TCollectValue>|array<TCollectKey, TCollectValue>|Collection<TCollectKey, TCollectValue>|CursorPaginatedDataCollection<TCollectKey, TDataCollectionValue>|CursorPaginatorContract<TCollectKey, TCollectValue>|DataCollection<TCollectKey, TDataCollectionValue>|EloquentCollection<TCollectKey, TModelValue>|Enumerable<TCollectKey, TCollectValue>|LazyCollection<TCollectKey, TCollectValue>|LengthAwarePaginatorContract<TCollectKey, TCollectValue>|PaginatedDataCollection<TCollectKey, TDataCollectionValue>|PaginatorContract<TCollectKey, TCollectValue>|Traversable<TCollectKey, TCollectValue> $items
     * @param null|'array'|class-string $into
     * @return (
     *     $into is null
     *     ? ($items is array
     *         ? array<TCollectKey, TData>
     *         : ($items is PaginatedDataCollection<*, *>|CursorPaginatedDataCollection<*, *>|DataCollection<*, *>
     *             ? ($items is PaginatedDataCollection<*, *>
     *                 ? PaginatedDataCollection<TCollectKey, TData>
     *                 : ($items is CursorPaginatedDataCollection<*, *>
     *                     ? CursorPaginatedDataCollection<TCollectKey, TData>
     *                     : DataCollection<TCollectKey, TData>))
     *             : ($items is AbstractPaginator<*, *>
     *                 ? ($items is LengthAwarePaginator<*, *>
     *                     ? LengthAwarePaginator<TCollectKey, TData>
     *                     : ($items is Paginator<*, *>
     *                         ? Paginator<TCollectKey, TData>
     *                         : AbstractPaginator<TCollectKey, TData>))
     *                 : ($items is AbstractCursorPaginator<*, *>
     *                     ? ($items is CursorPaginator<*, *>
     *                         ? CursorPaginator<TCollectKey, TData>
     *                         : AbstractCursorPaginator<TCollectKey, TData>)
     *                     : ($items is Enumerable<*, *>
     *                         ? ($items is EloquentCollection<*, *>
     *                             ? Collection<TCollectKey, TData>
     *                             : ($items is LazyCollection<*, *>
     *                                 ? LazyCollection<TCollectKey, TData>
     *                                 : ($items is Collection<*, *>
     *                                     ? Collection<TCollectKey, TData>
     *                                     : never)))
     *                         : never)))))
     *     : ($into is 'array'
     *         ? array<TCollectKey, TData>
     *         : ($into is 'Hypervel\Support\Enumerable'|'Hypervel\Database\Eloquent\Collection'|'Hypervel\Support\Collection'
     *             ? Collection<TCollectKey, TData>
     *             : ($into is 'Hypervel\Support\LazyCollection'
     *                 ? LazyCollection<TCollectKey, TData>
     *                 : ($into is 'Hypervel\Data\PaginatedDataCollection'|'Hypervel\Data\CursorPaginatedDataCollection'|'Hypervel\Data\DataCollection'
     *                     ? ($into is 'Hypervel\Data\PaginatedDataCollection'
     *                         ? PaginatedDataCollection<TCollectKey, TData>
     *                         : ($into is 'Hypervel\Data\CursorPaginatedDataCollection'
     *                             ? CursorPaginatedDataCollection<TCollectKey, TData>
     *                             : DataCollection<TCollectKey, TData>))
     *                     : ($into is 'Hypervel\Pagination\LengthAwarePaginator'|'Hypervel\Pagination\Paginator'|'Hypervel\Pagination\CursorPaginator'|'Hypervel\Pagination\AbstractPaginator'|'Hypervel\Pagination\AbstractCursorPaginator'
     *                         ? ($into is 'Hypervel\Pagination\LengthAwarePaginator'
     *                             ? LengthAwarePaginator<TCollectKey, TData>
     *                             : ($into is 'Hypervel\Pagination\Paginator'
     *                                 ? Paginator<TCollectKey, TData>
     *                                 : ($into is 'Hypervel\Pagination\CursorPaginator'
     *                                     ? CursorPaginator<TCollectKey, TData>
     *                                     : ($into is 'Hypervel\Pagination\AbstractPaginator'
     *                                         ? AbstractPaginator<TCollectKey, TData>
     *                                         : AbstractCursorPaginator<TCollectKey, TData>))))
     *                         : ($into is 'Hypervel\Contracts\Pagination\LengthAwarePaginator'|'Hypervel\Contracts\Pagination\Paginator'|'Hypervel\Contracts\Pagination\CursorPaginator'
     *                             ? ($into is 'Hypervel\Contracts\Pagination\LengthAwarePaginator'
     *                                 ? LengthAwarePaginatorContract<TCollectKey, TData>
     *                                 : ($into is 'Hypervel\Contracts\Pagination\Paginator'
     *                                     ? PaginatorContract<TCollectKey, TData>
     *                                     : CursorPaginatorContract<TCollectKey, TData>))
     *                             : array<TCollectKey, TData>|CursorPaginatedDataCollection<TCollectKey, TData>|DataCollection<TCollectKey, TData>|PaginatedDataCollection<TCollectKey, TData>|Enumerable<TCollectKey, TData>|AbstractCursorPaginator<TCollectKey, TData>|AbstractPaginator<TCollectKey, TData>|CursorPaginatorContract<TCollectKey, TData>|LengthAwarePaginatorContract<TCollectKey, TData>|PaginatorContract<TCollectKey, TData>)))))))
     * )
     */
    public function collect(
        mixed $items,
        ?string $into = null,
    ): array|DataCollection|PaginatedDataCollection|CursorPaginatedDataCollection|Enumerable|AbstractPaginator|PaginatorContract|AbstractCursorPaginator|CursorPaginatorContract|LazyCollection|Collection {
        return $this->creator->collect($this->dataClass, $this->get(), $items, $into);
    }

    /**
     * Create typed items without whole-collection factory dispatch.
     *
     * @internal
     *
     * @template TCollectKey of array-key
     *
     * @param null|array<TCollectKey, mixed>|DataCollection<TCollectKey, BaseData>|Enumerable<TCollectKey, mixed> $items
     * @return Enumerable<TCollectKey, TData>
     */
    public function collectItems(mixed $items): Enumerable
    {
        return $this->creator->collectItems($this->dataClass, $this->get(), $items);
    }
}
