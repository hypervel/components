<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use ArrayAccess;
use Closure;
use Hypervel\Broadcasting\Contracts\HasBroadcastChannel;
use Hypervel\Context\Context;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface as Resolver;
use Hypervel\Event\Contracts\Dispatcher;
use Hypervel\Database\Eloquent\Attributes\Boot;
use Hypervel\Database\Eloquent\Attributes\Initialize;
use Hypervel\Database\Eloquent\Attributes\Scope as LocalScope;
use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Concerns\AsPivot;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Eloquent\Relations;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Queue\Contracts\QueueableCollection;
use Hypervel\Queue\Contracts\QueueableEntity;
use Hypervel\Router\Contracts\UrlRoutable;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\Contracts\Arrayable;
use Hypervel\Support\Contracts\CanBeEscapedWhenCastToString;
use Hypervel\Support\Contracts\Jsonable;
use Hypervel\Support\Str;
use Hypervel\Support\StrCache;
use Hypervel\Support\Stringable as SupportStringable;
use Hypervel\Support\Traits\ForwardsCalls;
use JsonException;
use JsonSerializable;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Stringable;
use UnitEnum;

use function Hypervel\Support\enum_value;

abstract class Model implements Arrayable, ArrayAccess, CanBeEscapedWhenCastToString, HasBroadcastChannel, Jsonable, JsonSerializable, QueueableEntity, Stringable, UrlRoutable
{
    use Concerns\HasAttributes,
        Concerns\HasEvents,
        Concerns\HasGlobalScopes,
        Concerns\HasRelationships,
        Concerns\HasTimestamps,
        Concerns\HasUniqueIds,
        Concerns\HidesAttributes,
        Concerns\GuardsAttributes,
        Concerns\PreventsCircularRecursion,
        Concerns\TransformsToResource,
        ForwardsCalls;
    /** @use HasCollection<\Hypervel\Database\Eloquent\Collection<array-key, static & self>> */
    use HasCollection;

    /**
     * Context key for storing models that should ignore touch.
     */
    protected const IGNORE_ON_TOUCH_CONTEXT_KEY = '__database.model.ignoreOnTouch';

    /**
     * Context key for storing whether broadcasting is enabled.
     */
    protected const BROADCASTING_CONTEXT_KEY = '__database.model.broadcasting';

    /**
     * Context key for storing whether events are disabled.
     */
    protected const EVENTS_DISABLED_CONTEXT_KEY = '__database.model.eventsDisabled';

    /**
     * Context key for storing whether mass assignment is unguarded.
     */
    protected const UNGUARDED_CONTEXT_KEY = '__database.model.unguarded';

    /**
     * The connection name for the model.
     */
    protected UnitEnum|string|null $connection = null;

    /**
     * The table associated with the model.
     */
    protected ?string $table = null;

    /**
     * The primary key for the model.
     */
    protected string $primaryKey = 'id';

    /**
     * The "type" of the primary key ID.
     */
    protected string $keyType = 'int';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public bool $incrementing = true;

    /**
     * The relations to eager load on every query.
     *
     * @var array<int, string>
     */
    protected array $with = [];

    /**
     * The relationship counts that should be eager loaded on every query.
     *
     * @var array<int, string>
     */
    protected array $withCount = [];

    /**
     * Indicates whether lazy loading will be prevented on this model.
     */
    public bool $preventsLazyLoading = false;

    /**
     * The number of models to return for pagination.
     */
    protected int $perPage = 15;

    /**
     * Indicates if the model exists.
     */
    public bool $exists = false;

    /**
     * Indicates if the model was inserted during the object's lifecycle.
     */
    public bool $wasRecentlyCreated = false;

    /**
     * Indicates that the object's string representation should be escaped when __toString is invoked.
     */
    protected bool $escapeWhenCastingToString = false;

    /**
     * The connection resolver instance.
     */
    protected static ?Resolver $resolver = null;

    /**
     * The event dispatcher instance.
     */
    protected static ?Dispatcher $dispatcher = null;

    /**
     * The array of booted models.
     *
     * @var array<class-string<self>, bool>
     */
    protected static array $booted = [];

    /**
     * The callbacks that should be executed after the model has booted.
     *
     * @var array<class-string<self>, array<int, Closure>>
     */
    protected static array $bootedCallbacks = [];

    /**
     * The array of trait initializers that will be called on each new instance.
     *
     * @var array<class-string<self>, array<int, string>>
     */
    protected static array $traitInitializers = [];

    /**
     * The array of global scopes on the model.
     *
     * @var array<class-string<self>, array<string, Scope|Closure>>
     */
    protected static array $globalScopes = [];

    /**
     * Indicates whether lazy loading should be restricted on all models.
     */
    protected static bool $modelsShouldPreventLazyLoading = false;

    /**
     * Indicates whether relations should be automatically loaded on all models when they are accessed.
     */
    protected static bool $modelsShouldAutomaticallyEagerLoadRelationships = false;

    /**
     * The callback that is responsible for handling lazy loading violations.
     *
     * @var (callable(self, string): void)|null
     */
    protected static $lazyLoadingViolationCallback;

    /**
     * Indicates if an exception should be thrown instead of silently discarding non-fillable attributes.
     */
    protected static bool $modelsShouldPreventSilentlyDiscardingAttributes = false;

    /**
     * The callback that is responsible for handling discarded attribute violations.
     *
     * @var (callable(self, array): void)|null
     */
    protected static $discardedAttributeViolationCallback;

    /**
     * Indicates if an exception should be thrown when trying to access a missing attribute on a retrieved model.
     */
    protected static bool $modelsShouldPreventAccessingMissingAttributes = false;

    /**
     * The callback that is responsible for handling missing attribute violations.
     *
     * @var (callable(self, string): void)|null
     */
    protected static $missingAttributeViolationCallback;

    /**
     * The Eloquent query builder class to use for the model.
     *
     * @var class-string<\Hypervel\Database\Eloquent\Builder<*>>
     */
    protected static string $builder = Builder::class;

    /**
     * The Eloquent collection class to use for the model.
     *
     * @var class-string<\Hypervel\Database\Eloquent\Collection<*, *>>
     */
    protected static string $collectionClass = Collection::class;

    /**
     * Cache of soft deletable models.
     *
     * @var array<class-string<self>, bool>
     */
    protected static array $isSoftDeletable;

    /**
     * Cache of prunable models.
     *
     * @var array<class-string<self>, bool>
     */
    protected static array $isPrunable;

    /**
     * Cache of mass prunable models.
     *
     * @var array<class-string<self>, bool>
     */
    protected static array $isMassPrunable;

    /**
     * The name of the "created at" column.
     *
     * @var string|null
     */
    const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    const UPDATED_AT = 'updated_at';

    /**
     * Create a new Eloquent model instance.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->bootIfNotBooted();

        $this->initializeTraits();

        $this->syncOriginal();

        $this->fill($attributes);
    }

    /**
     * Check if the model needs to be booted and if so, do it.
     */
    protected function bootIfNotBooted(): void
    {
        if (! isset(static::$booted[static::class])) {
            static::$booted[static::class] = true;

            $this->fireModelEvent('booting', false);

            static::booting();
            static::boot();
            static::booted();

            static::$bootedCallbacks[static::class] ??= [];

            foreach (static::$bootedCallbacks[static::class] as $callback) {
                $callback();
            }

            $this->fireModelEvent('booted', false);
        }
    }

    /**
     * Perform any actions required before the model boots.
     */
    protected static function booting(): void
    {
        //
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        static::bootTraits();
    }

    /**
     * Boot all of the bootable traits on the model.
     */
    protected static function bootTraits(): void
    {
        $class = static::class;

        $booted = [];

        static::$traitInitializers[$class] = [];

        $uses = class_uses_recursive($class);

        $conventionalBootMethods = array_map(static fn ($trait) => 'boot'.class_basename($trait), $uses);
        $conventionalInitMethods = array_map(static fn ($trait) => 'initialize'.class_basename($trait), $uses);

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if (! in_array($method->getName(), $booted) &&
                $method->isStatic() &&
                (in_array($method->getName(), $conventionalBootMethods) ||
                $method->getAttributes(Boot::class) !== [])) {
                $method->invoke(null);

                $booted[] = $method->getName();
            }

            if (in_array($method->getName(), $conventionalInitMethods) ||
                $method->getAttributes(Initialize::class) !== []) {
                static::$traitInitializers[$class][] = $method->getName();
            }
        }

        static::$traitInitializers[$class] = array_unique(static::$traitInitializers[$class]);
    }

    /**
     * Initialize any initializable traits on the model.
     */
    protected function initializeTraits(): void
    {
        foreach (static::$traitInitializers[static::class] as $method) {
            $this->{$method}();
        }
    }

    /**
     * Perform any actions required after the model boots.
     */
    protected static function booted(): void
    {
        //
    }

    /**
     * Register a closure to be executed after the model has booted.
     */
    protected static function whenBooted(Closure $callback): void
    {
        static::$bootedCallbacks[static::class] ??= [];

        static::$bootedCallbacks[static::class][] = $callback;
    }

    /**
     * Clear the list of booted models so they will be re-booted.
     */
    public static function clearBootedModels(): void
    {
        static::$booted = [];
        static::$bootedCallbacks = [];

        static::$globalScopes = [];
    }

    /**
     * Disables relationship model touching for the current class during given callback scope.
     */
    public static function withoutTouching(callable $callback): void
    {
        static::withoutTouchingOn([static::class], $callback);
    }

    /**
     * Disables relationship model touching for the given model classes during given callback scope.
     *
     * @param  array<int, class-string<self>>  $models
     */
    public static function withoutTouchingOn(array $models, callable $callback): void
    {
        /** @var list<class-string<self>> $previous */
        $previous = Context::get(self::IGNORE_ON_TOUCH_CONTEXT_KEY, []);
        Context::set(self::IGNORE_ON_TOUCH_CONTEXT_KEY, array_merge($previous, $models));

        try {
            $callback();
        } finally {
            Context::set(self::IGNORE_ON_TOUCH_CONTEXT_KEY, $previous);
        }
    }

    /**
     * Determine if the given model is ignoring touches.
     *
     * @param  class-string<self>|null  $class
     */
    public static function isIgnoringTouch(?string $class = null): bool
    {
        $class = $class ?: static::class;

        if (! get_class_vars($class)['timestamps'] || ! $class::UPDATED_AT) {
            return true;
        }

        /** @var array<int, class-string<self>> $ignoreOnTouch */
        $ignoreOnTouch = Context::get(self::IGNORE_ON_TOUCH_CONTEXT_KEY, []);

        foreach ($ignoreOnTouch as $ignoredClass) {
            if ($class === $ignoredClass || is_subclass_of($class, $ignoredClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indicate that models should prevent lazy loading, silently discarding attributes, and accessing missing attributes.
     */
    public static function shouldBeStrict(bool $shouldBeStrict = true): void
    {
        static::preventLazyLoading($shouldBeStrict);
        static::preventSilentlyDiscardingAttributes($shouldBeStrict);
        static::preventAccessingMissingAttributes($shouldBeStrict);
    }

    /**
     * Prevent model relationships from being lazy loaded.
     */
    public static function preventLazyLoading(bool $value = true): void
    {
        static::$modelsShouldPreventLazyLoading = $value;
    }

    /**
     * Determine if model relationships should be automatically eager loaded when accessed.
     */
    public static function automaticallyEagerLoadRelationships(bool $value = true): void
    {
        static::$modelsShouldAutomaticallyEagerLoadRelationships = $value;
    }

    /**
     * Register a callback that is responsible for handling lazy loading violations.
     *
     * @param  (callable(self, string): void)|null  $callback
     */
    public static function handleLazyLoadingViolationUsing(?callable $callback): void
    {
        static::$lazyLoadingViolationCallback = $callback;
    }

    /**
     * Prevent non-fillable attributes from being silently discarded.
     */
    public static function preventSilentlyDiscardingAttributes(bool $value = true): void
    {
        static::$modelsShouldPreventSilentlyDiscardingAttributes = $value;
    }

    /**
     * Register a callback that is responsible for handling discarded attribute violations.
     *
     * @param  (callable(self, array): void)|null  $callback
     */
    public static function handleDiscardedAttributeViolationUsing(?callable $callback): void
    {
        static::$discardedAttributeViolationCallback = $callback;
    }

    /**
     * Prevent accessing missing attributes on retrieved models.
     */
    public static function preventAccessingMissingAttributes(bool $value = true): void
    {
        static::$modelsShouldPreventAccessingMissingAttributes = $value;
    }

    /**
     * Register a callback that is responsible for handling missing attribute violations.
     *
     * @param  (callable(self, string): void)|null  $callback
     */
    public static function handleMissingAttributeViolationUsing(?callable $callback): void
    {
        static::$missingAttributeViolationCallback = $callback;
    }

    /**
     * Execute a callback without broadcasting any model events for all model types.
     */
    public static function withoutBroadcasting(callable $callback): mixed
    {
        $wasBroadcasting = Context::get(self::BROADCASTING_CONTEXT_KEY, true);

        Context::set(self::BROADCASTING_CONTEXT_KEY, false);

        try {
            return $callback();
        } finally {
            Context::set(self::BROADCASTING_CONTEXT_KEY, $wasBroadcasting);
        }
    }

    /**
     * Determine if broadcasting is currently enabled.
     */
    public static function isBroadcasting(): bool
    {
        return (bool) Context::get(self::BROADCASTING_CONTEXT_KEY, true);
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws MassAssignmentException
     */
    public function fill(array $attributes): static
    {
        $totallyGuarded = $this->totallyGuarded();

        $fillable = $this->fillableFromArray($attributes);

        foreach ($fillable as $key => $value) {
            // The developers may choose to place some attributes in the "fillable" array
            // which means only those attributes may be set through mass assignment to
            // the model, and all others will just get ignored for security reasons.
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif ($totallyGuarded || static::preventsSilentlyDiscardingAttributes()) {
                if (isset(static::$discardedAttributeViolationCallback)) {
                    call_user_func(static::$discardedAttributeViolationCallback, $this, [$key]);
                } else {
                    throw new MassAssignmentException(sprintf(
                        'Add [%s] to fillable property to allow mass assignment on [%s].',
                        $key, get_class($this)
                    ));
                }
            }
        }

        if (count($attributes) !== count($fillable) &&
            static::preventsSilentlyDiscardingAttributes()) {
            $keys = array_diff(array_keys($attributes), array_keys($fillable));

            if (isset(static::$discardedAttributeViolationCallback)) {
                call_user_func(static::$discardedAttributeViolationCallback, $this, $keys);
            } else {
                throw new MassAssignmentException(sprintf(
                    'Add fillable property [%s] to allow mass assignment on [%s].',
                    implode(', ', $keys),
                    get_class($this)
                ));
            }
        }

        return $this;
    }

    /**
     * Fill the model with an array of attributes. Force mass assignment.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function forceFill(array $attributes): static
    {
        return static::unguarded(fn () => $this->fill($attributes));
    }

    /**
     * Qualify the given column name by the model's table.
     */
    public function qualifyColumn(string $column): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        return $this->getTable().'.'.$column;
    }

    /**
     * Qualify the given columns with the model's table.
     *
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    public function qualifyColumns(array $columns): array
    {
        return (new BaseCollection($columns))
            ->map(fn ($column) => $this->qualifyColumn($column))
            ->all();
    }

    /**
     * Create a new instance of the given model.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newInstance(array $attributes = [], bool $exists = false): static
    {
        // This method just provides a convenient way for us to generate fresh model
        // instances of this current model. It is particularly useful during the
        // hydration of new objects via the Eloquent query builder instances.
        $model = new static;

        $model->exists = $exists;

        $model->setConnection(
            $this->getConnectionName()
        );

        $model->setTable($this->getTable());

        $model->mergeCasts($this->casts);

        $model->fill((array) $attributes);

        return $model;
    }

    /**
     * Create a new model instance that is existing.
     *
     * @param  array<string, mixed>|object  $attributes
     */
    public function newFromBuilder(array|object $attributes = [], UnitEnum|string|null $connection = null): static
    {
        $model = $this->newInstance([], true);

        $model->setRawAttributes((array) $attributes, true);

        $model->setConnection($connection ?? $this->getConnectionName());

        $model->fireModelEvent('retrieved', false);

        return $model;
    }

    /**
     * Begin querying the model on a given connection.
     *
     * @return Builder<static>
     */
    public static function on(UnitEnum|string|null $connection = null): Builder
    {
        // First we will just create a fresh instance of this model, and then we can set the
        // connection on the model so that it is used for the queries we execute, as well
        // as being set on every relation we retrieve without a custom connection name.
        return (new static)->setConnection($connection)->newQuery();
    }

    /**
     * Begin querying the model on the write connection.
     *
     * @return Builder<static>
     */
    public static function onWriteConnection(): Builder
    {
        // @phpstan-ignore return.type (useWritePdo returns $this, mixin type inference loses Builder)
        return static::query()->useWritePdo();
    }

    /**
     * Get all of the models from the database.
     *
     * @param  array<int, string>|string  $columns
     * @return Collection<int, static>
     */
    public static function all(array|string $columns = ['*']): Collection
    {
        return static::query()->get(
            is_array($columns) ? $columns : func_get_args()
        );
    }

    /**
     * Begin querying a model with eager loading.
     *
     * @param  array<int, string>|string  $relations
     * @return Builder<static>
     */
    public static function with(array|string $relations): Builder
    {
        return static::query()->with(
            is_string($relations) ? func_get_args() : $relations
        );
    }

    /**
     * Eager load relations on the model.
     *
     * @param  array<int, string>|string  $relations
     */
    public function load(array|string $relations): static
    {
        $query = $this->newQueryWithoutRelationships()->with(
            is_string($relations) ? func_get_args() : $relations
        );

        $query->eagerLoadRelations([$this]);

        return $this;
    }

    /**
     * Eager load relationships on the polymorphic relation of a model.
     *
     * @param  array<class-string, array<int, string>>  $relations
     */
    public function loadMorph(string $relation, array $relations): static
    {
        if (! $this->{$relation}) {
            return $this;
        }

        $className = get_class($this->{$relation});

        $this->{$relation}->load($relations[$className] ?? []);

        return $this;
    }

    /**
     * Eager load relations on the model if they are not already eager loaded.
     *
     * @param  array<int, string>|string  $relations
     */
    public function loadMissing(array|string $relations): static
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        $this->newCollection([$this])->loadMissing($relations);

        return $this;
    }

    /**
     * Eager load relation's column aggregations on the model.
     *
     * @param  array<int, string>|string  $relations
     */
    public function loadAggregate(array|string $relations, string $column, ?string $function = null): static
    {
        $this->newCollection([$this])->loadAggregate($relations, $column, $function);

        return $this;
    }

    /**
     * Eager load relation counts on the model.
     *
     * @param  array<int, string>|string  $relations
     */
    public function loadCount(array|string $relations): static
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        return $this->loadAggregate($relations, '*', 'count');
    }

    /**
     * Eager load relation max column values on the model.
     *
     * @param  array<int, string>|string  $relations
     */
    public function loadMax(array|string $relations, string $column): static
    {
        return $this->loadAggregate($relations, $column, 'max');
    }

    /**
     * Eager load relation min column values on the model.
     *
     * @param  array<int, string>|string  $relations
     */
    public function loadMin(array|string $relations, string $column): static
    {
        return $this->loadAggregate($relations, $column, 'min');
    }

    /**
     * Eager load relation's column summations on the model.
     *
     * @param  array<int, string>|string  $relations
     */
    public function loadSum(array|string $relations, string $column): static
    {
        return $this->loadAggregate($relations, $column, 'sum');
    }

    /**
     * Eager load relation average column values on the model.
     *
     * @param  array<int, string>|string  $relations
     */
    public function loadAvg(array|string $relations, string $column): static
    {
        return $this->loadAggregate($relations, $column, 'avg');
    }

    /**
     * Eager load related model existence values on the model.
     *
     * @param  array<int, string>|string  $relations
     */
    public function loadExists(array|string $relations): static
    {
        return $this->loadAggregate($relations, '*', 'exists');
    }

    /**
     * Eager load relationship column aggregation on the polymorphic relation of a model.
     *
     * @param  array<class-string, array<int, string>>  $relations
     */
    public function loadMorphAggregate(string $relation, array $relations, string $column, ?string $function = null): static
    {
        if (! $this->{$relation}) {
            return $this;
        }

        $className = get_class($this->{$relation});

        $this->{$relation}->loadAggregate($relations[$className] ?? [], $column, $function);

        return $this;
    }

    /**
     * Eager load relationship counts on the polymorphic relation of a model.
     *
     * @param  array<class-string, array<int, string>>  $relations
     */
    public function loadMorphCount(string $relation, array $relations): static
    {
        return $this->loadMorphAggregate($relation, $relations, '*', 'count');
    }

    /**
     * Eager load relationship max column values on the polymorphic relation of a model.
     *
     * @param  array<class-string, array<int, string>>  $relations
     */
    public function loadMorphMax(string $relation, array $relations, string $column): static
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'max');
    }

    /**
     * Eager load relationship min column values on the polymorphic relation of a model.
     *
     * @param  array<class-string, array<int, string>>  $relations
     */
    public function loadMorphMin(string $relation, array $relations, string $column): static
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'min');
    }

    /**
     * Eager load relationship column summations on the polymorphic relation of a model.
     *
     * @param  array<class-string, array<int, string>>  $relations
     */
    public function loadMorphSum(string $relation, array $relations, string $column): static
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'sum');
    }

    /**
     * Eager load relationship average column values on the polymorphic relation of a model.
     *
     * @param  array<class-string, array<int, string>>  $relations
     */
    public function loadMorphAvg(string $relation, array $relations, string $column): static
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'avg');
    }

    /**
     * Increment a column's value by a given amount.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function increment(string $column, float|int $amount = 1, array $extra = []): int
    {
        return $this->incrementOrDecrement($column, $amount, $extra, 'increment');
    }

    /**
     * Decrement a column's value by a given amount.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function decrement(string $column, float|int $amount = 1, array $extra = []): int
    {
        return $this->incrementOrDecrement($column, $amount, $extra, 'decrement');
    }

    /**
     * Run the increment or decrement method on the model.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function incrementOrDecrement(string $column, float|int $amount, array $extra, string $method): int|false
    {
        if (! $this->exists) {
            return $this->newQueryWithoutRelationships()->{$method}($column, $amount, $extra);
        }

        $this->{$column} = $this->isClassDeviable($column)
            ? $this->deviateClassCastableAttribute($method, $column, $amount)
            : $this->{$column} + ($method === 'increment' ? $amount : $amount * -1);

        $this->forceFill($extra);

        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->isClassDeviable($column)) {
            $amount = (clone $this)->setAttribute($column, $amount)->getAttributeFromArray($column);
        }

        return tap($this->setKeysForSaveQuery($this->newQueryWithoutScopes())->{$method}($column, $amount, $extra), function () use ($column) {
            $this->syncChanges();

            $this->fireModelEvent('updated', false);

            $this->syncOriginalAttribute($column);
        });
    }

    /**
     * Update the model in the database.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->save($options);
    }

    /**
     * Update the model in the database within a transaction.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     *
     * @throws \Throwable
     */
    public function updateOrFail(array $attributes = [], array $options = []): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->saveOrFail($options);
    }

    /**
     * Update the model in the database without raising any events.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->saveQuietly($options);
    }

    /**
     * Increment a column's value by a given amount without raising any events.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function incrementQuietly(string $column, float|int $amount = 1, array $extra = []): int|false
    {
        return static::withoutEvents(
            fn () => $this->incrementOrDecrement($column, $amount, $extra, 'increment')
        );
    }

    /**
     * Decrement a column's value by a given amount without raising any events.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function decrementQuietly(string $column, float|int $amount = 1, array $extra = []): int|false
    {
        return static::withoutEvents(
            fn () => $this->incrementOrDecrement($column, $amount, $extra, 'decrement')
        );
    }

    /**
     * Save the model and all of its relationships.
     */
    public function push(): bool
    {
        return $this->withoutRecursion(function () {
            if (! $this->save()) {
                return false;
            }

            // To sync all of the relationships to the database, we will simply spin through
            // the relationships and save each model via this "push" method, which allows
            // us to recurse into all of these nested relations for the model instance.
            foreach ($this->relations as $models) {
                $models = $models instanceof Collection
                    ? $models->all()
                    : [$models];

                foreach (array_filter($models) as $model) {
                    if (! $model->push()) {
                        return false;
                    }
                }
            }

            return true;
        }, true);
    }

    /**
     * Save the model and all of its relationships without raising any events to the parent model.
     */
    public function pushQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->push());
    }

    /**
     * Save the model to the database without raising any events.
     *
     * @param  array<string, mixed>  $options
     */
    public function saveQuietly(array $options = []): bool
    {
        return static::withoutEvents(fn () => $this->save($options));
    }

    /**
     * Save the model to the database.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $this->mergeAttributesFromCachedCasts();

        $query = $this->newModelQuery();

        // If the "saving" event returns false we'll bail out of the save and return
        // false, indicating that the save failed. This provides a chance for any
        // listeners to cancel save operations if validations fail or whatever.
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        // If the model already exists in the database we can just update our record
        // that is already in this database using the current IDs in this "where"
        // clause to only update this model. Otherwise, we'll just insert them.
        if ($this->exists) {
            $saved = $this->isDirty() ?
                $this->performUpdate($query) : true;
        }

        // If the model is brand new, we'll insert it into our database and set the
        // ID attribute on the model to the value of the newly inserted row's ID
        // which is typically an auto-increment value managed by the database.
        else {
            $saved = $this->performInsert($query);

            if (! $this->getConnectionName() &&
                $connection = $query->getConnection()) {
                $this->setConnection($connection->getName());
            }
        }

        // If the model is successfully saved, we need to do a few more things once
        // that is done. We will call the "saved" method here to run any actions
        // we need to happen after a model gets successfully saved right here.
        if ($saved) {
            $this->finishSave($options);
        }

        return $saved;
    }

    /**
     * Save the model to the database within a transaction.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws \Throwable
     */
    public function saveOrFail(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn () => $this->save($options));
    }

    /**
     * Perform any actions that are necessary after the model is saved.
     *
     * @param  array<string, mixed>  $options
     */
    protected function finishSave(array $options): void
    {
        $this->fireModelEvent('saved', false);

        if ($this->isDirty() && ($options['touch'] ?? true)) {
            $this->touchOwners();
        }

        $this->syncOriginal();
    }

    /**
     * Perform a model update operation.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        // If the updating event returns false, we will cancel the update operation so
        // developers can hook Validation systems into their models and cancel this
        // operation if the model does not pass validation. Otherwise, we update.
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        // First we need to create a fresh query instance and touch the creation and
        // update timestamp on the model which are maintained by us for developer
        // convenience. Then we will just continue saving the model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // Once we have run the update operation, we will fire the "updated" event for
        // this model instance. This will allow developers to hook into these after
        // models are updated, giving them a chance to do any special processing.
        $dirty = $this->getDirtyForUpdate();

        if (count($dirty) > 0) {
            $this->setKeysForSaveQuery($query)->update($dirty);

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * Set the keys for a select query.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<static>  $query
     * @return \Hypervel\Database\Eloquent\Builder<static>
     */
    protected function setKeysForSelectQuery(Builder $query): Builder
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSelectQuery());

        return $query;
    }

    /**
     * Get the primary key value for a select query.
     */
    protected function getKeyForSelectQuery(): mixed
    {
        return $this->original[$this->getKeyName()] ?? $this->getKey();
    }

    /**
     * Set the keys for a save update query.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<static>  $query
     * @return \Hypervel\Database\Eloquent\Builder<static>
     */
    protected function setKeysForSaveQuery(Builder $query): Builder
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());

        return $query;
    }

    /**
     * Get the primary key value for a save query.
     */
    protected function getKeyForSaveQuery(): mixed
    {
        return $this->original[$this->getKeyName()] ?? $this->getKey();
    }

    /**
     * Perform a model insert operation.
     *
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        if ($this->usesUniqueIds()) {
            $this->setUniqueIds();
        }

        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        // First we'll need to create a fresh query instance and touch the creation and
        // update timestamps on this model, which are maintained by us for developer
        // convenience. After, we will just continue saving these model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // If the model has an incrementing key, we can use the "insertGetId" method on
        // the query builder, which will give us back the final inserted ID for this
        // table from the database. Not all tables have to be incrementing though.
        $attributes = $this->getAttributesForInsert();

        if ($this->getIncrementing()) {
            $this->insertAndSetId($query, $attributes);
        }

        // If the table isn't incrementing we'll simply insert these attributes as they
        // are. These attribute arrays must contain an "id" column previously placed
        // there by the developer as the manually determined key for these models.
        else {
            if (empty($attributes)) {
                return true;
            }

            $query->insert($attributes);
        }

        // We will go ahead and set the exists property to true, so that it is set when
        // the created event is fired, just in case the developer tries to update it
        // during the event. This will allow them to do so and run an update here.
        $this->exists = true;

        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }

    /**
     * Insert the given attributes and set the ID on the model.
     *
     * @param  Builder<static>  $query
     * @param  array<string, mixed>  $attributes
     */
    protected function insertAndSetId(Builder $query, array $attributes): void
    {
        $id = $query->insertGetId($attributes, $keyName = $this->getKeyName());

        $this->setAttribute($keyName, $id);
    }

    /**
     * Destroy the models for the given IDs.
     *
     * @param  Collection|BaseCollection|array<int, mixed>|int|string  $ids
     */
    public static function destroy(Collection|BaseCollection|array|int|string $ids): int
    {
        if ($ids instanceof EloquentCollection) {
            $ids = $ids->modelKeys();
        }

        if ($ids instanceof BaseCollection) {
            $ids = $ids->all();
        }

        $ids = is_array($ids) ? $ids : func_get_args();

        if (count($ids) === 0) {
            return 0;
        }

        // We will actually pull the models from the database table and call delete on
        // each of them individually so that their events get fired properly with a
        // correct set of attributes in case the developers wants to check these.
        $key = ($instance = new static)->getKeyName();

        $count = 0;

        foreach ($instance->whereIn($key, $ids)->get() as $model) {
            if ($model->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Delete the model from the database.
     *
     * Returns bool|null for standard models, int (affected rows) for pivot models.
     *
     * @throws LogicException
     */
    public function delete(): int|bool|null
    {
        $this->mergeAttributesFromCachedCasts();

        // @phpstan-ignore function.impossibleType (defensive: users may set $primaryKey = null)
        if (is_null($this->getKeyName())) {
            throw new LogicException('No primary key defined on model.');
        }

        // If the model doesn't exist, there is nothing to delete so we'll just return
        // immediately and not do anything else. Otherwise, we will continue with a
        // deletion process on the model, firing the proper events, and so forth.
        if (! $this->exists) {
            return null;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        // Here, we'll touch the owning models, verifying these timestamps get updated
        // for the models. This will allow any caching to get broken on the parents
        // by the timestamp. Then we will go ahead and delete the model instance.
        $this->touchOwners();

        $this->performDeleteOnModel();

        // Once the model has been deleted, we will fire off the deleted event so that
        // the developers may hook into post-delete operations. We will then return
        // a boolean true as the delete is presumably successful on the database.
        $this->fireModelEvent('deleted', false);

        return true;
    }

    /**
     * Delete the model from the database without raising any events.
     */
    public function deleteQuietly(): ?bool
    {
        return static::withoutEvents(fn () => $this->delete());
    }

    /**
     * Delete the model from the database within a transaction.
     *
     * @throws \Throwable
     */
    public function deleteOrFail(): ?bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->getConnection()->transaction(fn () => $this->delete());
    }

    /**
     * Force a hard delete on a soft deleted model.
     *
     * This method protects developers from running forceDelete when the trait is missing.
     */
    public function forceDelete(): ?bool
    {
        return $this->delete();
    }

    /**
     * Force a hard destroy on a soft deleted model.
     *
     * This method protects developers from running forceDestroy when the trait is missing.
     *
     * @param  Collection|BaseCollection|array<int, mixed>|int|string  $ids
     */
    public static function forceDestroy(Collection|BaseCollection|array|int|string $ids): int
    {
        return static::destroy($ids);
    }

    /**
     * Perform the actual delete query on this model instance.
     */
    protected function performDeleteOnModel(): void
    {
        $this->setKeysForSaveQuery($this->newModelQuery())->delete();

        $this->exists = false;
    }

    /**
     * Begin querying the model.
     *
     * @return Builder<static>
     */
    public static function query(): Builder
    {
        return (new static)->newQuery();
    }

    /**
     * Get a new query builder for the model's table.
     *
     * @return Builder<static>
     */
    public function newQuery(): Builder
    {
        return $this->registerGlobalScopes($this->newQueryWithoutScopes());
    }

    /**
     * Get a new query builder that doesn't have any global scopes or eager loading.
     *
     * @return Builder<static>
     */
    public function newModelQuery(): Builder
    {
        // @phpstan-ignore return.type (template covariance: $this vs static in setModel)
        return $this->newEloquentBuilder(
            $this->newBaseQueryBuilder()
        )->setModel($this);
    }

    /**
     * Get a new query builder with no relationships loaded.
     *
     * @return Builder<static>
     */
    public function newQueryWithoutRelationships(): Builder
    {
        return $this->registerGlobalScopes($this->newModelQuery());
    }

    /**
     * Register the global scopes for this builder instance.
     *
     * @param  Builder<static>  $builder
     * @return Builder<static>
     */
    public function registerGlobalScopes(Builder $builder): Builder
    {
        foreach ($this->getGlobalScopes() as $identifier => $scope) {
            $builder->withGlobalScope($identifier, $scope);
        }

        return $builder;
    }

    /**
     * Get a new query builder that doesn't have any global scopes.
     *
     * @return Builder<static>
     */
    public function newQueryWithoutScopes(): Builder
    {
        return $this->newModelQuery()
            ->with($this->with)
            ->withCount($this->withCount);
    }

    /**
     * Get a new query instance without a given scope.
     *
     * @return Builder<static>
     */
    public function newQueryWithoutScope(Scope|string $scope): Builder
    {
        return $this->newQuery()->withoutGlobalScope($scope);
    }

    /**
     * Get a new query to restore one or more models by their queueable IDs.
     *
     * @param  array|int|string  $ids
     * @return \Hypervel\Database\Eloquent\Builder<static>
     */
    public function newQueryForRestoration(array|int|string $ids): Builder
    {
        return $this->newQueryWithoutScopes()->whereKey($ids);
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @return Builder<*>
     */
    public function newEloquentBuilder(QueryBuilder $query): Builder
    {
        $builderClass = $this->resolveCustomBuilderClass();

        // @phpstan-ignore function.alreadyNarrowedType (defensive: validates custom builder class at runtime)
        if ($builderClass && is_subclass_of($builderClass, Builder::class)) {
            return new $builderClass($query);
        }

        return new static::$builder($query);
    }

    /**
     * Resolve the custom Eloquent builder class from the model attributes.
     *
     * @return class-string<Builder>|false
     */
    protected function resolveCustomBuilderClass(): string|false
    {
        $attributes = (new ReflectionClass($this))
            ->getAttributes(UseEloquentBuilder::class);

        return ! empty($attributes)
            ? $attributes[0]->newInstance()->builderClass
            : false;
    }

    /**
     * Get a new query builder instance for the connection.
     */
    protected function newBaseQueryBuilder(): QueryBuilder
    {
        return $this->getConnection()->query();
    }

    /**
     * Create a new pivot model instance.
     *
     * @param  array<string, mixed>  $attributes
     * @param  class-string<Pivot>|null  $using
     */
    public function newPivot(self $parent, array $attributes, string $table, bool $exists, ?string $using = null): Pivot
    {
        return $using ? $using::fromRawAttributes($parent, $attributes, $table, $exists)
            : Pivot::fromAttributes($parent, $attributes, $table, $exists);
    }

    /**
     * Determine if the model has a given scope.
     */
    public function hasNamedScope(string $scope): bool
    {
        return method_exists($this, 'scope'.ucfirst($scope)) ||
            static::isScopeMethodWithAttribute($scope);
    }

    /**
     * Apply the given named scope if possible.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function callNamedScope(string $scope, array $parameters = []): mixed
    {
        if ($this->isScopeMethodWithAttribute($scope)) {
            return $this->{$scope}(...$parameters);
        }

        return $this->{'scope'.ucfirst($scope)}(...$parameters);
    }

    /**
     * Determine if the given method has a scope attribute.
     */
    protected static function isScopeMethodWithAttribute(string $method): bool
    {
        return method_exists(static::class, $method) &&
            (new ReflectionMethod(static::class, $method))
                ->getAttributes(LocalScope::class) !== [];
    }

    /**
     * Convert the model instance to an array.
     */
    public function toArray(): array
    {
        return $this->withoutRecursion(
            fn () => array_merge($this->attributesToArray(), $this->relationsToArray()),
            fn () => $this->attributesToArray(),
        );
    }

    /**
     * Convert the model instance to JSON.
     *
     * @throws \Hypervel\Database\Eloquent\JsonEncodingException
     */
    public function toJson(int $options = 0): string
    {
        try {
            $json = json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw JsonEncodingException::forModel($this, $e->getMessage());
        }

        return $json;
    }

    /**
     * Convert the model instance to pretty print formatted JSON.
     *
     * @throws \Hypervel\Database\Eloquent\JsonEncodingException
     */
    public function toPrettyJson(int $options = 0): string
    {
        return $this->toJson(JSON_PRETTY_PRINT | $options);
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Reload a fresh model instance from the database.
     *
     * @param  array<int, string>|string  $with
     */
    public function fresh(array|string $with = []): ?static
    {
        if (! $this->exists) {
            return null;
        }

        return $this->setKeysForSelectQuery($this->newQueryWithoutScopes())
            ->useWritePdo()
            ->with(is_string($with) ? func_get_args() : $with)
            ->first();
    }

    /**
     * Reload the current model instance with fresh attributes from the database.
     */
    public function refresh(): static
    {
        if (! $this->exists) {
            return $this;
        }

        $this->setRawAttributes(
            $this->setKeysForSelectQuery($this->newQueryWithoutScopes())
                ->useWritePdo()
                ->firstOrFail()
                ->attributes
        );

        $this->load((new BaseCollection($this->relations))->reject(
            fn ($relation) => $relation instanceof Pivot
                || (is_object($relation) && in_array(AsPivot::class, class_uses_recursive($relation), true))
        )->keys()->all());

        $this->syncOriginal();

        return $this;
    }

    /**
     * Clone the model into a new, non-existing instance.
     *
     * @param  array<int, string>|null  $except
     */
    public function replicate(?array $except = null): static
    {
        $defaults = array_values(array_filter([
            $this->getKeyName(),
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
            ...$this->uniqueIds(),
            'laravel_through_key',
        ]));

        $attributes = Arr::except(
            $this->getAttributes(), $except ? array_unique(array_merge($except, $defaults)) : $defaults
        );

        return tap(new static, function ($instance) use ($attributes) {
            $instance->setRawAttributes($attributes);

            $instance->setRelations($this->relations);

            $instance->fireModelEvent('replicating', false);
        });
    }

    /**
     * Clone the model into a new, non-existing instance without raising any events.
     *
     * @param  array<int, string>|null  $except
     */
    public function replicateQuietly(?array $except = null): static
    {
        return static::withoutEvents(fn () => $this->replicate($except));
    }

    /**
     * Determine if two models have the same ID and belong to the same table.
     */
    public function is(?self $model): bool
    {
        return ! is_null($model) &&
            $this->getKey() === $model->getKey() &&
            $this->getTable() === $model->getTable() &&
            $this->getConnectionName() === $model->getConnectionName();
    }

    /**
     * Determine if two models are not the same.
     */
    public function isNot(?self $model): bool
    {
        return ! $this->is($model);
    }

    /**
     * Get the database connection for the model.
     */
    public function getConnection(): Connection
    {
        return static::resolveConnection($this->getConnectionName());
    }

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return enum_value($this->connection);
    }

    /**
     * Set the connection associated with the model.
     */
    public function setConnection(UnitEnum|string|null $name): static
    {
        $this->connection = $name;

        return $this;
    }

    /**
     * Resolve a connection instance.
     */
    public static function resolveConnection(UnitEnum|string|null $connection = null): Connection
    {
        // @phpstan-ignore return.type (resolver interface returns ConnectionInterface, but concrete always returns Connection)
        return static::$resolver->connection($connection);
    }

    /**
     * Get the connection resolver instance.
     */
    public static function getConnectionResolver(): ?Resolver
    {
        return static::$resolver;
    }

    /**
     * Set the connection resolver instance.
     */
    public static function setConnectionResolver(Resolver $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * Unset the connection resolver for models.
     */
    public static function unsetConnectionResolver(): void
    {
        static::$resolver = null;
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return $this->table ?? StrCache::snake(Str::pluralStudly(class_basename($this)));
    }

    /**
     * Set the table associated with the model.
     */
    public function setTable(string $table): static
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Get the primary key for the model.
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Set the primary key for the model.
     */
    public function setKeyName(string $key): static
    {
        $this->primaryKey = $key;

        return $this;
    }

    /**
     * Get the table qualified key name.
     */
    public function getQualifiedKeyName(): string
    {
        return $this->qualifyColumn($this->getKeyName());
    }

    /**
     * Get the auto-incrementing key type.
     */
    public function getKeyType(): string
    {
        return $this->keyType;
    }

    /**
     * Set the data type for the primary key.
     */
    public function setKeyType(string $type): static
    {
        $this->keyType = $type;

        return $this;
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     */
    public function getIncrementing(): bool
    {
        return $this->incrementing;
    }

    /**
     * Set whether IDs are incrementing.
     */
    public function setIncrementing(bool $value): static
    {
        $this->incrementing = $value;

        return $this;
    }

    /**
     * Get the value of the model's primary key.
     */
    public function getKey(): mixed
    {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * Get the queueable identity for the entity.
     */
    public function getQueueableId(): mixed
    {
        return $this->getKey();
    }

    /**
     * Get the queueable relationships for the entity.
     */
    public function getQueueableRelations(): array
    {
        return $this->withoutRecursion(function () {
            $relations = [];

            foreach ($this->getRelations() as $key => $relation) {
                if (! method_exists($this, $key)) {
                    continue;
                }

                $relations[] = $key;

                if ($relation instanceof QueueableCollection) {
                    foreach ($relation->getQueueableRelations() as $collectionValue) {
                        $relations[] = $key.'.'.$collectionValue;
                    }
                }

                if ($relation instanceof QueueableEntity) {
                    foreach ($relation->getQueueableRelations() as $entityValue) {
                        $relations[] = $key.'.'.$entityValue;
                    }
                }
            }

            return array_unique($relations);
        }, []);
    }

    /**
     * Get the queueable connection for the entity.
     */
    public function getQueueableConnection(): ?string
    {
        return $this->getConnectionName();
    }

    /**
     * Get the value of the model's route key.
     */
    public function getRouteKey(): mixed
    {
        return $this->getAttribute($this->getRouteKeyName());
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * Retrieve the model for a bound value.
     */
    public function resolveRouteBinding(mixed $value, ?string $field = null): ?self
    {
        return $this->resolveRouteBindingQuery($this, $value, $field)->first();
    }

    /**
     * Retrieve the model for a bound value.
     */
    public function resolveSoftDeletableRouteBinding(mixed $value, ?string $field = null): ?self
    {
        return $this->resolveRouteBindingQuery($this, $value, $field)->withTrashed()->first();
    }

    /**
     * Retrieve the child model for a bound value.
     */
    public function resolveChildRouteBinding(string $childType, mixed $value, ?string $field): ?self
    {
        return $this->resolveChildRouteBindingQuery($childType, $value, $field)->first();
    }

    /**
     * Retrieve the child model for a bound value.
     */
    public function resolveSoftDeletableChildRouteBinding(string $childType, mixed $value, ?string $field): ?self
    {
        return $this->resolveChildRouteBindingQuery($childType, $value, $field)->withTrashed()->first();
    }

    /**
     * Retrieve the child model query for a bound value.
     *
     * @return Relations\Relation<self, $this, *>
     */
    protected function resolveChildRouteBindingQuery(string $childType, mixed $value, ?string $field): Relations\Relation
    {
        $relationship = $this->{$this->childRouteBindingRelationshipName($childType)}();

        $field = $field ?: $relationship->getRelated()->getRouteKeyName();

        if ($relationship instanceof HasManyThrough ||
            $relationship instanceof BelongsToMany) {
            $field = $relationship->getRelated()->qualifyColumn($field);
        }

        return $relationship instanceof Model
            ? $relationship->resolveRouteBindingQuery($relationship, $value, $field)
            : $relationship->getRelated()->resolveRouteBindingQuery($relationship, $value, $field);
    }

    /**
     * Retrieve the child route model binding relationship name for the given child type.
     */
    protected function childRouteBindingRelationshipName(string $childType): string
    {
        return Str::plural(Str::camel($childType));
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param  self|Builder|Relations\Relation<*, *, *>  $query
     * @return Builder<static>|Relations\Relation<*, *, *>
     */
    public function resolveRouteBindingQuery(self|Builder|Relations\Relation $query, mixed $value, ?string $field = null): Builder|Relations\Relation
    {
        return $query->where($field ?? $this->getRouteKeyName(), $value);
    }

    /**
     * Get the default foreign key name for the model.
     */
    public function getForeignKey(): string
    {
        return StrCache::snake(class_basename($this)).'_'.$this->getKeyName();
    }

    /**
     * Get the number of models to return per page.
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * Set the number of models to return per page.
     */
    public function setPerPage(int $perPage): static
    {
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * Determine if the model is soft deletable.
     */
    public static function isSoftDeletable(): bool
    {
        return static::$isSoftDeletable[static::class] ??= in_array(SoftDeletes::class, class_uses_recursive(static::class));
    }

    /**
     * Determine if the model is prunable.
     */
    protected function isPrunable(): bool
    {
        return self::$isPrunable[static::class] ??= in_array(Prunable::class, class_uses_recursive(static::class)) || static::isMassPrunable();
    }

    /**
     * Determine if the model is mass prunable.
     */
    protected function isMassPrunable(): bool
    {
        return self::$isMassPrunable[static::class] ??= in_array(MassPrunable::class, class_uses_recursive(static::class));
    }

    /**
     * Determine if lazy loading is disabled.
     */
    public static function preventsLazyLoading(): bool
    {
        return static::$modelsShouldPreventLazyLoading;
    }

    /**
     * Determine if relationships are being automatically eager loaded when accessed.
     */
    public static function isAutomaticallyEagerLoadingRelationships(): bool
    {
        return static::$modelsShouldAutomaticallyEagerLoadRelationships;
    }

    /**
     * Determine if discarding guarded attribute fills is disabled.
     */
    public static function preventsSilentlyDiscardingAttributes(): bool
    {
        return static::$modelsShouldPreventSilentlyDiscardingAttributes;
    }

    /**
     * Determine if accessing missing attributes is disabled.
     */
    public static function preventsAccessingMissingAttributes(): bool
    {
        return static::$modelsShouldPreventAccessingMissingAttributes;
    }

    /**
     * Get the broadcast channel route definition that is associated with the given entity.
     */
    public function broadcastChannelRoute(): string
    {
        return str_replace('\\', '.', get_class($this)).'.{'.Str::camel(class_basename($this)).'}';
    }

    /**
     * Get the broadcast channel name that is associated with the given entity.
     */
    public function broadcastChannel(): string
    {
        return str_replace('\\', '.', get_class($this)).'.'.$this->getKey();
    }

    /**
     * Dynamically retrieve attributes on the model.
     */
    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Determine if the given attribute exists.
     *
     * @param  mixed  $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        $shouldPrevent = static::$modelsShouldPreventAccessingMissingAttributes;

        static::$modelsShouldPreventAccessingMissingAttributes = false;

        try {
            return ! is_null($this->getAttribute($offset));
        } finally {
            static::$modelsShouldPreventAccessingMissingAttributes = $shouldPrevent;
        }
    }

    /**
     * Get the value for a given offset.
     *
     * @param  mixed  $offset
     * @return mixed
     */
    public function offsetGet($offset): mixed
    {
        return $this->getAttribute($offset);
    }

    /**
     * Set the value for a given offset.
     *
     * @param  mixed  $offset
     * @param  mixed  $value
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        $this->setAttribute($offset, $value);
    }

    /**
     * Unset the value for a given offset.
     *
     * @param  mixed  $offset
     * @return void
     */
    public function offsetUnset($offset): void
    {
        unset(
            $this->attributes[$offset],
            $this->relations[$offset],
            $this->attributeCastCache[$offset],
            $this->classCastCache[$offset]
        );
    }

    /**
     * Determine if an attribute or relation exists on the model.
     */
    public function __isset(string $key): bool
    {
        return $this->offsetExists($key);
    }

    /**
     * Unset an attribute on the model.
     */
    public function __unset(string $key): void
    {
        $this->offsetUnset($key);
    }

    /**
     * Handle dynamic method calls into the model.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (in_array($method, ['increment', 'decrement', 'incrementQuietly', 'decrementQuietly'])) {
            return $this->$method(...$parameters);
        }

        if ($resolver = $this->relationResolver(static::class, $method)) {
            return $resolver($this);
        }

        if (Str::startsWith($method, 'through') &&
            method_exists($this, $relationMethod = (new SupportStringable($method))->after('through')->lcfirst()->toString())) {
            return $this->through($relationMethod);
        }

        return $this->forwardCallTo($this->newQuery(), $method, $parameters);
    }

    /**
     * Handle dynamic static method calls into the model.
     *
     * @param  array<int, mixed>  $parameters
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        if (static::isScopeMethodWithAttribute($method)) {
            return static::query()->$method(...$parameters);
        }

        return (new static)->$method(...$parameters);
    }

    /**
     * Convert the model to its string representation.
     */
    public function __toString(): string
    {
        return $this->escapeWhenCastingToString
            ? e($this->toJson())
            : $this->toJson();
    }

    /**
     * Indicate that the object's string representation should be escaped when __toString is invoked.
     */
    public function escapeWhenCastingToString(bool $escape = true): static
    {
        $this->escapeWhenCastingToString = $escape;

        return $this;
    }

    /**
     * Prepare the object for serialization.
     *
     * @return array<int, string>
     */
    public function __sleep(): array
    {
        $this->mergeAttributesFromCachedCasts();

        $this->classCastCache = [];
        $this->attributeCastCache = [];
        $this->relationAutoloadCallback = null;
        $this->relationAutoloadContext = null;

        $keys = get_object_vars($this);

        if (version_compare(PHP_VERSION, '8.4.0', '>=')) {
            foreach ((new ReflectionClass($this))->getProperties() as $property) {
                if ($property->hasHooks()) {
                    unset($keys[$property->getName()]);
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * When a model is being unserialized, check if it needs to be booted.
     */
    public function __wakeup(): void
    {
        $this->bootIfNotBooted();

        $this->initializeTraits();

        if (static::isAutomaticallyEagerLoadingRelationships()) {
            $this->withRelationshipAutoloading();
        }
    }
}
