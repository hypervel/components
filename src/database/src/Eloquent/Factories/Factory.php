<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Closure;
use Faker\Generator;
use Hyperf\Collection\Enumerable;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Context\ApplicationContext;
use Hypervel\Foundation\Contracts\Application;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\StrCache;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\ForwardsCalls;
use Hypervel\Support\Traits\Macroable;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @template TModel of \Hypervel\Database\Eloquent\Model
 *
 * @method $this trashed()
 */
abstract class Factory
{
    use Conditionable, ForwardsCalls, Macroable {
        __call as macroCall;
    }

    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TModel>|null
     */
    protected ?string $model = null;

    /**
     * The number of models that should be generated.
     */
    protected ?int $count = null;

    /**
     * The state transformations that will be applied to the model.
     */
    protected Collection $states;

    /**
     * The parent relationships that will be applied to the model.
     */
    protected Collection $has;

    /**
     * The child relationships that will be applied to the model.
     */
    protected Collection $for;

    /**
     * The model instances to always use when creating relationships.
     */
    protected Collection $recycle;

    /**
     * The "after making" callbacks that will be applied to the model.
     */
    protected Collection $afterMaking;

    /**
     * The "after creating" callbacks that will be applied to the model.
     */
    protected Collection $afterCreating;

    /**
     * Whether relationships should not be automatically created.
     */
    protected bool $expandRelationships = true;

    /**
     * The relationships that should not be automatically created.
     */
    protected array $excludeRelationships = [];

    /**
     * The name of the database connection that will be used to create the models.
     */
    protected UnitEnum|string|null $connection = null;

    /**
     * The current Faker instance.
     */
    protected ?Generator $faker = null;

    /**
     * The default namespace where factories reside.
     */
    public static string $namespace = 'Database\\Factories\\';

    /**
     * @deprecated use $modelNameResolvers
     *
     * @var (callable(self): class-string<TModel>)|null
     */
    protected static mixed $modelNameResolver = null;

    /**
     * The default model name resolvers.
     *
     * @var array<class-string, callable(self): class-string<TModel>>
     */
    protected static array $modelNameResolvers = [];

    /**
     * The factory name resolver.
     *
     * @var callable|null
     */
    protected static mixed $factoryNameResolver = null;

    /**
     * Whether to expand relationships by default.
     */
    protected static bool $expandRelationshipsByDefault = true;

    /**
     * Create a new factory instance.
     */
    public function __construct(
        ?int $count = null,
        ?Collection $states = null,
        ?Collection $has = null,
        ?Collection $for = null,
        ?Collection $afterMaking = null,
        ?Collection $afterCreating = null,
        UnitEnum|string|null $connection = null,
        ?Collection $recycle = null,
        ?bool $expandRelationships = null,
        array $excludeRelationships = [],
    ) {
        $this->count = $count;
        $this->states = $states ?? new Collection;
        $this->has = $has ?? new Collection;
        $this->for = $for ?? new Collection;
        $this->afterMaking = $afterMaking ?? new Collection;
        $this->afterCreating = $afterCreating ?? new Collection;
        $this->connection = $connection;
        $this->recycle = $recycle ?? new Collection;
        $this->faker = $this->withFaker();
        $this->expandRelationships = $expandRelationships ?? self::$expandRelationshipsByDefault;
        $this->excludeRelationships = $excludeRelationships;
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * Get a new factory instance for the given attributes.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     */
    public static function new(callable|array $attributes = []): static
    {
        return (new static)->state($attributes)->configure();
    }

    /**
     * Get a new factory instance for the given number of models.
     */
    public static function times(int $count): static
    {
        return static::new()->count($count);
    }

    /**
     * Configure the factory.
     */
    public function configure(): static
    {
        return $this;
    }

    /**
     * Get the raw attributes generated by the factory.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return array<int|string, mixed>
     */
    public function raw(callable|array $attributes = [], ?Model $parent = null): array
    {
        if ($this->count === null) {
            return $this->state($attributes)->getExpandedAttributes($parent);
        }

        return array_map(function () use ($attributes, $parent) {
            return $this->state($attributes)->getExpandedAttributes($parent);
        }, range(1, $this->count));
    }

    /**
     * Create a single model and persist it to the database.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return TModel
     */
    public function createOne(callable|array $attributes = []): Model
    {
        return $this->count(null)->create($attributes);
    }

    /**
     * Create a single model and persist it to the database without dispatching any model events.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return TModel
     */
    public function createOneQuietly(callable|array $attributes = []): Model
    {
        return $this->count(null)->createQuietly($attributes);
    }

    /**
     * Create a collection of models and persist them to the database.
     *
     * @param  int|null|iterable<int, array<string, mixed>>  $records
     * @return \Hypervel\Database\Eloquent\Collection<int, TModel>
     */
    public function createMany(int|iterable|null $records = null): EloquentCollection
    {
        $records ??= ($this->count ?? 1);

        $this->count = null;

        if (is_numeric($records)) {
            $records = array_fill(0, $records, []);
        }

        // @phpstan-ignore return.type (TModel lost through Collection->map closure)
        return new EloquentCollection(
            (new Collection($records))->map(function ($record) {
                return $this->state($record)->create();
            })
        );
    }

    /**
     * Create a collection of models and persist them to the database without dispatching any model events.
     *
     * @param  int|null|iterable<int, array<string, mixed>>  $records
     * @return \Hypervel\Database\Eloquent\Collection<int, TModel>
     */
    public function createManyQuietly(int|iterable|null $records = null): EloquentCollection
    {
        return Model::withoutEvents(fn () => $this->createMany($records));
    }

    /**
     * Create a collection of models and persist them to the database.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return \Hypervel\Database\Eloquent\Collection<int, TModel>|TModel
     */
    public function create(callable|array $attributes = [], ?Model $parent = null): EloquentCollection|Model
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        if ($results instanceof Model) {
            $this->store(new Collection([$results]));

            $this->callAfterCreating(new Collection([$results]), $parent);
        } else {
            $this->store($results);

            $this->callAfterCreating($results, $parent);
        }

        return $results;
    }

    /**
     * Create a collection of models and persist them to the database without dispatching any model events.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return \Hypervel\Database\Eloquent\Collection<int, TModel>|TModel
     */
    public function createQuietly(callable|array $attributes = [], ?Model $parent = null): EloquentCollection|Model
    {
        return Model::withoutEvents(fn () => $this->create($attributes, $parent));
    }

    /**
     * Create a callback that persists a model in the database when invoked.
     *
     * @param  array<string, mixed>  $attributes
     * @return \Closure(): (\Hypervel\Database\Eloquent\Collection<int, TModel>|TModel)
     */
    public function lazy(array $attributes = [], ?Model $parent = null): Closure
    {
        return fn () => $this->create($attributes, $parent);
    }

    /**
     * Set the connection name on the results and store them.
     *
     * @param  \Hypervel\Support\Collection<int, \Hypervel\Database\Eloquent\Model>  $results
     */
    protected function store(Collection $results): void
    {
        $results->each(function ($model) {
            if (! isset($this->connection)) {
                $model->setConnection($model->newQueryWithoutScopes()->getConnection()->getName());
            }

            $model->save();

            foreach ($model->getRelations() as $name => $items) {
                if ($items instanceof Enumerable && $items->isEmpty()) {
                    $model->unsetRelation($name);
                }
            }

            $this->createChildren($model);
        });
    }

    /**
     * Create the children for the given model.
     */
    protected function createChildren(Model $model): void
    {
        Model::unguarded(function () use ($model) {
            $this->has->each(function ($has) use ($model) {
                $has->recycle($this->recycle)->createFor($model);
            });
        });
    }

    /**
     * Make a single instance of the model.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return TModel
     */
    public function makeOne(callable|array $attributes = []): Model
    {
        return $this->count(null)->make($attributes);
    }

    /**
     * Create a collection of models.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return \Hypervel\Database\Eloquent\Collection<int, TModel>|TModel
     */
    public function make(callable|array $attributes = [], ?Model $parent = null): EloquentCollection|Model
    {
        $autoEagerLoadingEnabled = Model::isAutomaticallyEagerLoadingRelationships();

        if ($autoEagerLoadingEnabled) {
            Model::automaticallyEagerLoadRelationships(false);
        }

        try {
            if (! empty($attributes)) {
                return $this->state($attributes)->make([], $parent);
            }

            if ($this->count === null) {
                return tap($this->makeInstance($parent), function ($instance) {
                    $this->callAfterMaking(new Collection([$instance]));
                });
            }

            if ($this->count < 1) {
                return $this->newModel()->newCollection();
            }

            $instances = $this->newModel()->newCollection(array_map(function () use ($parent) {
                return $this->makeInstance($parent);
            }, range(1, $this->count)));

            $this->callAfterMaking($instances);

            return $instances;
        } finally {
            Model::automaticallyEagerLoadRelationships($autoEagerLoadingEnabled);
        }
    }

    /**
     * Insert the model records in bulk. No model events are emitted.
     *
     * @param  array<string, mixed>  $attributes
     * @param  Model|null  $parent
     * @return void
     */
    public function insert(array $attributes = [], ?Model $parent = null): void
    {
        $made = $this->make($attributes, $parent);

        $madeCollection = $made instanceof Collection
            ? $made
            : $this->newModel()->newCollection([$made]);

        $model = $madeCollection->first();

        if (isset($this->connection)) {
            $model->setConnection($this->connection);
        }

        $query = $model->newQueryWithoutScopes();

        $query->fillAndInsert(
            $madeCollection->withoutAppends()
                ->setHidden([])
                ->map(static fn (Model $model) => $model->attributesToArray())
                ->all()
        );
    }

    /**
     * Make an instance of the model with the given attributes.
     *
     * @return TModel
     */
    protected function makeInstance(?Model $parent): Model
    {
        return Model::unguarded(function () use ($parent) {
            return tap($this->newModel($this->getExpandedAttributes($parent)), function ($instance) {
                if (isset($this->connection)) {
                    $instance->setConnection($this->connection);
                }
            });
        });
    }

    /**
     * Get a raw attributes array for the model.
     */
    protected function getExpandedAttributes(?Model $parent): array
    {
        return $this->expandAttributes($this->getRawAttributes($parent));
    }

    /**
     * Get the raw attributes for the model as an array.
     */
    protected function getRawAttributes(?Model $parent): array
    {
        return $this->states->pipe(function ($states) {
            return $this->for->isEmpty() ? $states : new Collection(array_merge([function () {
                return $this->parentResolvers();
            }], $states->all()));
        })->reduce(function ($carry, $state) use ($parent) {
            if ($state instanceof Closure) {
                $state = $state->bindTo($this);
            }

            return array_merge($carry, $state($carry, $parent));
        }, $this->definition());
    }

    /**
     * Create the parent relationship resolvers (as deferred Closures).
     */
    protected function parentResolvers(): array
    {
        return $this->for
            ->map(fn (BelongsToRelationship $for) => $for->recycle($this->recycle)->attributesFor($this->newModel()))
            ->collapse()
            ->all();
    }

    /**
     * Expand all attributes to their underlying values.
     */
    protected function expandAttributes(array $definition): array
    {
        return (new Collection($definition))
            ->map($evaluateRelations = function ($attribute, $key) {
                if (! $this->expandRelationships && $attribute instanceof self) {
                    $attribute = null;
                } elseif ($attribute instanceof self &&
                    array_intersect([$attribute->modelName(), $key], $this->excludeRelationships)) {
                    $attribute = null;
                } elseif ($attribute instanceof self) {
                    $attribute = $this->getRandomRecycledModel($attribute->modelName())?->getKey()
                        ?? $attribute->recycle($this->recycle)->create()->getKey();
                } elseif ($attribute instanceof Model) {
                    $attribute = $attribute->getKey();
                }

                return $attribute;
            })
            ->map(function ($attribute, $key) use (&$definition, $evaluateRelations) {
                if (is_callable($attribute) && ! is_string($attribute) && ! is_array($attribute)) {
                    $attribute = $attribute($definition);
                }

                $attribute = $evaluateRelations($attribute, $key);

                $definition[$key] = $attribute;

                return $attribute;
            })
            ->all();
    }

    /**
     * Add a new state transformation to the model definition.
     *
     * @param  (callable(array<string, mixed>, Model|null): array<string, mixed>)|array<string, mixed>  $state
     */
    public function state(callable|array $state): static
    {
        return $this->newInstance([
            'states' => $this->states->concat([
                is_callable($state) ? $state : fn () => $state,
            ]),
        ]);
    }

    /**
     * Prepend a new state transformation to the model definition.
     *
     * @param  (callable(array<string, mixed>, Model|null): array<string, mixed>)|array<string, mixed>  $state
     */
    public function prependState(callable|array $state): static
    {
        return $this->newInstance([
            'states' => $this->states->prepend(
                is_callable($state) ? $state : fn () => $state,
            ),
        ]);
    }

    /**
     * Set a single model attribute.
     */
    public function set(string|int $key, mixed $value): static
    {
        return $this->state([$key => $value]);
    }

    /**
     * Add a new sequenced state transformation to the model definition.
     */
    public function sequence(mixed ...$sequence): static
    {
        return $this->state(new Sequence(...$sequence));
    }

    /**
     * Add a new sequenced state transformation to the model definition and update the pending creation count to the size of the sequence.
     */
    public function forEachSequence(array ...$sequence): static
    {
        return $this->state(new Sequence(...$sequence))->count(count($sequence));
    }

    /**
     * Add a new cross joined sequenced state transformation to the model definition.
     */
    public function crossJoinSequence(array ...$sequence): static
    {
        return $this->state(new CrossJoinSequence(...$sequence));
    }

    /**
     * Define a child relationship for the model.
     */
    public function has(self $factory, ?string $relationship = null): static
    {
        return $this->newInstance([
            'has' => $this->has->concat([new Relationship(
                $factory, $relationship ?? $this->guessRelationship($factory->modelName())
            )]),
        ]);
    }

    /**
     * Attempt to guess the relationship name for a "has" relationship.
     */
    protected function guessRelationship(string $related): string
    {
        $guess = StrCache::camel(StrCache::plural(class_basename($related)));

        return method_exists($this->modelName(), $guess) ? $guess : StrCache::singular($guess);
    }

    /**
     * Define an attached relationship for the model.
     *
     * @param  (callable(): array<string, mixed>)|array<string, mixed>  $pivot
     */
    public function hasAttached(self|Collection|Model|array $factory, callable|array $pivot = [], ?string $relationship = null): static
    {
        return $this->newInstance([
            'has' => $this->has->concat([new BelongsToManyRelationship(
                $factory,
                $pivot,
                $relationship ?? StrCache::camel(StrCache::plural(class_basename(
                    $factory instanceof Factory
                        ? $factory->modelName()
                        : Collection::wrap($factory)->first()
                )))
            )]),
        ]);
    }

    /**
     * Define a parent relationship for the model.
     */
    public function for(self|Model $factory, ?string $relationship = null): static
    {
        return $this->newInstance(['for' => $this->for->concat([new BelongsToRelationship(
            $factory,
            $relationship ?? StrCache::camel(class_basename(
                $factory instanceof Factory ? $factory->modelName() : $factory
            ))
        )])]);
    }

    /**
     * Provide model instances to use instead of any nested factory calls when creating relationships.
     */
    public function recycle(Model|Collection|array $model): static
    {
        // Group provided models by the type and merge them into existing recycle collection
        return $this->newInstance([
            'recycle' => $this->recycle
                ->flatten()
                ->merge(
                    Collection::wrap($model instanceof Model ? func_get_args() : $model)
                        ->flatten()
                )->groupBy(fn ($model) => get_class($model)),
        ]);
    }

    /**
     * Retrieve a random model of a given type from previously provided models to recycle.
     *
     * @template TClass of \Hypervel\Database\Eloquent\Model
     *
     * @param  class-string<TClass>  $modelClassName
     * @return TClass|null
     */
    public function getRandomRecycledModel(string $modelClassName): ?Model
    {
        return $this->recycle->get($modelClassName)?->random();
    }

    /**
     * Add a new "after making" callback to the model definition.
     *
     * @param  \Closure(TModel): mixed  $callback
     */
    public function afterMaking(Closure $callback): static
    {
        return $this->newInstance(['afterMaking' => $this->afterMaking->concat([$callback])]);
    }

    /**
     * Add a new "after creating" callback to the model definition.
     *
     * @param  \Closure(TModel, \Hypervel\Database\Eloquent\Model|null): mixed  $callback
     */
    public function afterCreating(Closure $callback): static
    {
        return $this->newInstance(['afterCreating' => $this->afterCreating->concat([$callback])]);
    }

    /**
     * Call the "after making" callbacks for the given model instances.
     */
    protected function callAfterMaking(Collection $instances): void
    {
        $instances->each(function ($model) {
            $this->afterMaking->each(function ($callback) use ($model) {
                $callback($model);
            });
        });
    }

    /**
     * Call the "after creating" callbacks for the given model instances.
     */
    protected function callAfterCreating(Collection $instances, ?Model $parent = null): void
    {
        $instances->each(function ($model) use ($parent) {
            $this->afterCreating->each(function ($callback) use ($model, $parent) {
                $callback($model, $parent);
            });
        });
    }

    /**
     * Specify how many models should be generated.
     */
    public function count(?int $count): static
    {
        return $this->newInstance(['count' => $count]);
    }

    /**
     * Indicate that related parent models should not be created.
     *
     * @param  array<string|class-string<Model>>  $parents
     */
    public function withoutParents(array $parents = []): static
    {
        return $this->newInstance(! $parents ? ['expandRelationships' => false] : ['excludeRelationships' => $parents]);
    }

    /**
     * Get the name of the database connection that is used to generate models.
     */
    public function getConnectionName(): ?string
    {
        return enum_value($this->connection);
    }

    /**
     * Specify the database connection that should be used to generate models.
     */
    public function connection(UnitEnum|string|null $connection): static
    {
        return $this->newInstance(['connection' => $connection]);
    }

    /**
     * Create a new instance of the factory builder with the given mutated properties.
     */
    protected function newInstance(array $arguments = []): static
    {
        // @phpstan-ignore return.type (new static preserves TModel at runtime, PHPStan can't track)
        return new static(...array_values(array_merge([
            'count' => $this->count,
            'states' => $this->states,
            'has' => $this->has,
            'for' => $this->for,
            'afterMaking' => $this->afterMaking,
            'afterCreating' => $this->afterCreating,
            'connection' => $this->connection,
            'recycle' => $this->recycle,
            'expandRelationships' => $this->expandRelationships,
            'excludeRelationships' => $this->excludeRelationships,
        ], $arguments)));
    }

    /**
     * Get a new model instance.
     *
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function newModel(array $attributes = []): Model
    {
        $model = $this->modelName();

        return new $model($attributes);
    }

    /**
     * Get the name of the model that is generated by the factory.
     *
     * @return class-string<TModel>
     */
    public function modelName(): string
    {
        if ($this->model !== null) {
            return $this->model;
        }

        $resolver = static::$modelNameResolvers[static::class] ?? static::$modelNameResolvers[self::class] ?? static::$modelNameResolver ?? function (self $factory) {
            $namespacedFactoryBasename = Str::replaceLast(
                'Factory', '', Str::replaceFirst(static::$namespace, '', $factory::class)
            );

            $factoryBasename = Str::replaceLast('Factory', '', class_basename($factory));

            $appNamespace = static::appNamespace();

            return class_exists($appNamespace.'Models\\'.$namespacedFactoryBasename)
                ? $appNamespace.'Models\\'.$namespacedFactoryBasename
                : $appNamespace.$factoryBasename;
        };

        return $resolver($this);
    }

    /**
     * Specify the callback that should be invoked to guess model names based on factory names.
     *
     * @param  callable(self): class-string<TModel>  $callback
     */
    public static function guessModelNamesUsing(callable $callback): void
    {
        static::$modelNameResolvers[static::class] = $callback;
    }

    /**
     * Specify the default namespace that contains the application's model factories.
     */
    public static function useNamespace(string $namespace): void
    {
        static::$namespace = $namespace;
    }

    /**
     * Get a new factory instance for the given model name.
     *
     * @template TClass of \Hypervel\Database\Eloquent\Model
     *
     * @param  class-string<TClass>  $modelName
     * @return \Hypervel\Database\Eloquent\Factories\Factory<TClass>
     */
    public static function factoryForModel(string $modelName): self
    {
        $factory = static::resolveFactoryName($modelName);

        return $factory::new();
    }

    /**
     * Specify the callback that should be invoked to guess factory names based on dynamic relationship names.
     *
     * @param  callable(class-string<\Hypervel\Database\Eloquent\Model>): class-string<\Hypervel\Database\Eloquent\Factories\Factory>  $callback
     */
    public static function guessFactoryNamesUsing(callable $callback): void
    {
        static::$factoryNameResolver = $callback;
    }

    /**
     * Specify that relationships should create parent relationships by default.
     */
    public static function expandRelationshipsByDefault(): void
    {
        static::$expandRelationshipsByDefault = true;
    }

    /**
     * Specify that relationships should not create parent relationships by default.
     */
    public static function dontExpandRelationshipsByDefault(): void
    {
        static::$expandRelationshipsByDefault = false;
    }

    /**
     * Get a new Faker instance.
     */
    protected function withFaker(): ?Generator
    {
        if (! class_exists(Generator::class)) {
            return null;
        }

        return ApplicationContext::getContainer()->make(Generator::class);
    }

    /**
     * Get the factory name for the given model name.
     *
     * @template TClass of \Hypervel\Database\Eloquent\Model
     *
     * @param  class-string<TClass>  $modelName
     * @return class-string<\Hypervel\Database\Eloquent\Factories\Factory<TClass>>
     */
    public static function resolveFactoryName(string $modelName): string
    {
        $resolver = static::$factoryNameResolver ?? function (string $modelName) {
            $appNamespace = static::appNamespace();

            $modelName = Str::startsWith($modelName, $appNamespace.'Models\\')
                ? Str::after($modelName, $appNamespace.'Models\\')
                : Str::after($modelName, $appNamespace);

            return static::$namespace.$modelName.'Factory';
        };

        return $resolver($modelName);
    }

    /**
     * Get the application namespace for the application.
     */
    protected static function appNamespace(): string
    {
        try {
            return ApplicationContext::getContainer()
                ->make(Application::class)
                ->getNamespace();
        } catch (Throwable) {
            return 'App\\';
        }
    }

    /**
     * Flush the factory's global state.
     */
    public static function flushState(): void
    {
        static::$modelNameResolver = null;
        static::$modelNameResolvers = [];
        static::$factoryNameResolver = null;
        static::$namespace = 'Database\\Factories\\';
        static::$expandRelationshipsByDefault = true;
    }

    /**
     * Proxy dynamic factory methods onto their proper methods.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if ($method === 'trashed' && $this->modelName()::isSoftDeletable()) {
            return $this->state([
                $this->newModel()->getDeletedAtColumn() => $parameters[0] ?? Carbon::now()->subDay(),
            ]);
        }

        if (! Str::startsWith($method, ['for', 'has'])) {
            static::throwBadMethodCallException($method);
        }

        $relationship = StrCache::camel(Str::substr($method, 3));

        $relatedModel = get_class($this->newModel()->{$relationship}()->getRelated());

        if (method_exists($relatedModel, 'newFactory')) {
            $factory = $relatedModel::newFactory() ?? static::factoryForModel($relatedModel);
        } else {
            $factory = static::factoryForModel($relatedModel);
        }

        if (str_starts_with($method, 'for')) {
            return $this->for($factory->state($parameters[0] ?? []), $relationship);
        }

        return $this->has(
            $factory
                ->count(is_numeric($parameters[0] ?? null) ? $parameters[0] : 1)
                ->state((is_callable($parameters[0] ?? null) || is_array($parameters[0] ?? null)) ? $parameters[0] : ($parameters[1] ?? [])),
            $relationship
        );
    }
}
