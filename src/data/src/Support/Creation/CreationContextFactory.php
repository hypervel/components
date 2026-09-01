<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Closure;
use Hypervel\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
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
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;

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

    /** @var list<Normalizer|class-string<Normalizer>> */
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
    ) {
        $this->validationStrategy = $this->config->validationStrategy;
    }

    /**
     * Set the validation strategy.
     */
    public function validationStrategy(ValidationStrategy $validationStrategy): self
    {
        $this->validationStrategy = $validationStrategy;

        return $this;
    }

    /**
     * Disable validation.
     */
    public function withoutValidation(): self
    {
        return $this->validationStrategy(ValidationStrategy::Disabled);
    }

    /**
     * Validate only Request sources.
     */
    public function onlyValidateRequests(): self
    {
        return $this->validationStrategy(ValidationStrategy::OnlyRequests);
    }

    /**
     * Validate every source.
     */
    public function alwaysValidate(): self
    {
        return $this->validationStrategy(ValidationStrategy::Always);
    }

    /**
     * Enable or disable property-name mapping.
     */
    public function withPropertyNameMapping(bool $withPropertyNameMapping = true): self
    {
        $this->mapPropertyNames = $withPropertyNameMapping;

        return $this;
    }

    /**
     * Disable or enable property-name mapping.
     */
    public function withoutPropertyNameMapping(bool $withoutPropertyNameMapping = true): self
    {
        $this->mapPropertyNames = ! $withoutPropertyNameMapping;

        return $this;
    }

    /**
     * Disable or enable named creation methods.
     */
    public function withoutMagicalCreation(bool $withoutMagicalCreation = true): self
    {
        $this->disableMagicalCreation = $withoutMagicalCreation;

        return $this;
    }

    /**
     * Enable or disable named creation methods.
     */
    public function withMagicalCreation(bool $withMagicalCreation = true): self
    {
        $this->disableMagicalCreation = ! $withMagicalCreation;

        return $this;
    }

    /**
     * Ignore named creation methods for this operation.
     */
    public function ignoreMagicalMethod(string ...$methods): self
    {
        array_push($this->ignoredMagicalMethods, ...$methods);

        return $this;
    }

    /**
     * Add a cast for a declared base type.
     *
     * @param Cast|class-string<Cast> $cast
     */
    public function withCast(string $castable, Cast|string $cast): self
    {
        $this->casts[$castable] = $cast;

        return $this;
    }

    /**
     * Merge casts for declared base types.
     *
     * @param array<string, Cast|class-string<Cast>> $casts
     */
    public function withCastCollection(array $casts): self
    {
        $this->casts = array_replace($this->casts, $casts);

        return $this;
    }

    /**
     * Add custom source normalizers.
     *
     * @param Normalizer|class-string<Normalizer> ...$normalizers
     */
    public function withNormalizers(Normalizer|string ...$normalizers): self
    {
        array_push($this->normalizers, ...$normalizers);

        return $this;
    }

    /**
     * Add a prepare-data hook.
     */
    public function prepareData(Closure $hook): self
    {
        $this->prepareDataHooks[] = $hook;

        return $this;
    }

    /**
     * Add a before-validation hook.
     */
    public function beforeValidation(Closure $hook): self
    {
        $this->beforeValidationHooks[] = $hook;

        return $this;
    }

    /**
     * Add a before-rules hook.
     */
    public function beforeRules(Closure $hook): self
    {
        $this->beforeRulesHooks[] = $hook;

        return $this;
    }

    /**
     * Add an after-rules hook.
     */
    public function afterRules(Closure $hook): self
    {
        $this->afterRulesHooks[] = $hook;

        return $this;
    }

    /**
     * Add a validator customization hook.
     */
    public function withValidator(Closure $hook): self
    {
        $this->withValidatorHooks[] = $hook;

        return $this;
    }

    /**
     * Add an after-validation hook.
     */
    public function afterValidation(Closure $hook): self
    {
        $this->afterValidationHooks[] = $hook;

        return $this;
    }

    /**
     * Add a before-creation hook.
     */
    public function beforeCreation(Closure $hook): self
    {
        $this->beforeCreationHooks[] = $hook;

        return $this;
    }

    /**
     * Add an after-creation hook.
     */
    public function afterCreation(Closure $hook): self
    {
        $this->afterCreationHooks[] = $hook;

        return $this;
    }

    /**
     * Build immutable options for one root operation.
     *
     * @return CreationContext<TData>
     */
    public function get(CreationMode $mode = CreationMode::Create): CreationContext
    {
        return new CreationContext(
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
     * @template TCollectKey of array-key
     * @template TCollectValue
     *
     * @param AbstractCursorPaginator|AbstractPaginator|array<TCollectKey, TCollectValue>|Collection<TCollectKey, TCollectValue>|CursorPaginatorContract|DataCollection<TCollectKey, TCollectValue>|EloquentCollection<TCollectKey, TCollectValue>|Enumerable|LazyCollection<TCollectKey, TCollectValue>|PaginatorContract $items
     *
     * @return ($into is 'array' ? array<TCollectKey, TData> : ($into is class-string<EloquentCollection> ? Collection<TCollectKey, TData> : ($into is class-string<Collection> ? Collection<TCollectKey, TData> : ($into is class-string<LazyCollection> ? LazyCollection<TCollectKey, TData> : ($into is class-string<DataCollection> ? DataCollection<TCollectKey, TData> : ($into is class-string<PaginatedDataCollection> ? PaginatedDataCollection<TCollectKey, TData> : ($into is class-string<CursorPaginatedDataCollection> ? CursorPaginatedDataCollection<TCollectKey, TData> : ($items is EloquentCollection ? Collection<TCollectKey, TData> : ($items is Collection ? Collection<TCollectKey, TData> : ($items is LazyCollection ? LazyCollection<TCollectKey, TData> : ($items is Enumerable ? Enumerable<TCollectKey, TData> : ($items is array ? array<TCollectKey, TData> : ($items is AbstractPaginator ? AbstractPaginator : ($items is PaginatorContract ? PaginatorContract : ($items is AbstractCursorPaginator ? AbstractCursorPaginator : ($items is CursorPaginatorContract ? CursorPaginatorContract : ($items is DataCollection ? DataCollection<TCollectKey, TData> : DataCollection<TCollectKey, TData>)))))))))))))))))
     */
    public function collect(
        mixed $items,
        ?string $into = null,
    ): array|DataCollection|PaginatedDataCollection|CursorPaginatedDataCollection|Enumerable|AbstractPaginator|PaginatorContract|AbstractCursorPaginator|CursorPaginatorContract|LazyCollection|Collection {
        return $this->creator->collect($this->dataClass, $this->get(), $items, $into);
    }
}
