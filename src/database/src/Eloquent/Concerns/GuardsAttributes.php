<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Attributes\Fillable;
use Hypervel\Database\Eloquent\Attributes\Guarded;
use Hypervel\Database\Eloquent\Attributes\Initialize;
use Hypervel\Database\Eloquent\Attributes\Unguarded;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Engine\Coroutine;
use Hypervel\Support\Str;
use ReflectionClass;

trait GuardsAttributes
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected array $fillable = [];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>
     */
    protected array $guarded = ['*'];

    /**
     * The actual columns that exist on the database and can be guarded.
     *
     * @var array<class-string,list<string>>
     */
    protected static array $guardableColumns = [];

    /**
     * The resolved guard configuration for each model class.
     *
     * @var array<class-string, array{guarded: null|array<int, string>, propertyDefault: array<int, string>}>
     */
    protected static array $guardConfigurations = [];

    /**
     * Initialize the GuardsAttributes trait.
     */
    #[Initialize]
    public function initializeGuardsAttributes(): void
    {
        if ($this->modelClassAttributesInitialized) {
            return;
        }

        $this->mergeFillable(static::resolveClassAttribute(Fillable::class, 'columns') ?? []);

        $configuration = static::guardConfiguration();

        // A subclass constructor may intentionally configure guarding before
        // calling the model constructor. Class metadata must not replace it.
        if ($configuration['guarded'] !== null
            && $this->guarded === $configuration['propertyDefault']) {
            $this->guarded = $configuration['guarded'];
        }
    }

    /**
     * Resolve the nearest guard configuration for the model class.
     *
     * @return array{guarded: null|array<int, string>, propertyDefault: array<int, string>}
     */
    protected static function guardConfiguration(): array
    {
        $class = static::class;

        if (isset(static::$guardConfigurations[$class])) {
            return static::$guardConfigurations[$class];
        }

        $reflection = new ReflectionClass($class);
        $propertyDeclarer = static::classPropertyDeclarer('guarded');
        $propertyDefault = $reflection->getDefaultProperties()['guarded'];
        $guarded = null;

        do {
            $reflectionClass = $reflection->getName();

            // The framework Model's trait default is the fallback, not a user
            // declaration that should mask attributes on application models.
            if ($reflectionClass === $propertyDeclarer && $reflectionClass !== Model::class) {
                break;
            }

            $guardedAttributes = $reflection->getAttributes(Guarded::class);
            $unguardedAttributes = $reflection->getAttributes(Unguarded::class);

            if ($guardedAttributes !== []) {
                $guarded = $guardedAttributes[0]->newInstance()->columns;
                break;
            }

            if ($unguardedAttributes !== []) {
                $guarded = [];
                break;
            }

            $guarded = static::guardConfigurationFromTraits($reflection->getTraits());

            if ($guarded !== null) {
                break;
            }
        } while ($reflection = $reflection->getParentClass());

        return static::$guardConfigurations[$class] = [
            'guarded' => $guarded,
            'propertyDefault' => $propertyDefault,
        ];
    }

    /**
     * Resolve guard configuration declared by traits at one class level.
     *
     * @param array<class-string, ReflectionClass<object>> $traits
     * @return null|array<int, string>
     */
    protected static function guardConfigurationFromTraits(array $traits): ?array
    {
        $unguarded = false;

        foreach ($traits as $trait) {
            $guardedAttributes = $trait->getAttributes(Guarded::class);

            if ($guardedAttributes !== []) {
                return $guardedAttributes[0]->newInstance()->columns;
            }

            $unguarded = $unguarded
                || $trait->getAttributes(Unguarded::class) !== [];
        }

        return $unguarded ? [] : null;
    }

    /**
     * Get the fillable attributes for the model.
     *
     * @return array<string>
     */
    public function getFillable(): array
    {
        return $this->fillable;
    }

    /**
     * Set the fillable attributes for the model.
     *
     * @param array<string> $fillable
     * @return $this
     */
    public function fillable(array $fillable): static
    {
        $this->fillable = $fillable;

        return $this;
    }

    /**
     * Merge new fillable attributes with existing fillable attributes on the model.
     *
     * @param array<string> $fillable
     * @return $this
     */
    public function mergeFillable(array $fillable): static
    {
        if ($fillable === []) {
            return $this;
        }

        $this->fillable = array_values(array_unique(array_merge($this->fillable, $fillable)));

        return $this;
    }

    /**
     * Get the guarded attributes for the model.
     *
     * @return array<string>
     */
    public function getGuarded(): array
    {
        return static::isUnguarded()
            ? []
            : $this->guarded;
    }

    /**
     * Set the guarded attributes for the model.
     *
     * @param array<string> $guarded
     * @return $this
     */
    public function guard(array $guarded): static
    {
        $this->guarded = $guarded;

        return $this;
    }

    /**
     * Merge new guarded attributes with existing guarded attributes on the model.
     *
     * @param array<string> $guarded
     * @return $this
     */
    public function mergeGuarded(array $guarded): static
    {
        $this->guarded = array_values(array_unique(array_merge($this->guarded, $guarded)));

        return $this;
    }

    /**
     * Disable all mass assignable restrictions.
     *
     * Uses Context for coroutine-safe state management.
     */
    public static function unguard(bool $state = true): void
    {
        CoroutineContext::set(self::UNGUARDED_CONTEXT_KEY, $state);
    }

    /**
     * Enable the mass assignment restrictions.
     */
    public static function reguard(): void
    {
        CoroutineContext::set(self::UNGUARDED_CONTEXT_KEY, false);
    }

    /**
     * Determine if the current state is "unguarded".
     */
    public static function isUnguarded(): bool
    {
        return (bool) CoroutineContext::get(self::UNGUARDED_CONTEXT_KEY, false);
    }

    /**
     * Run the given callable while being unguarded.
     *
     * Uses Context for coroutine-safe state management, ensuring concurrent
     * requests don't interfere with each other's guarding state.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    public static function unguarded(callable $callback): mixed
    {
        if (static::isUnguarded()) {
            return $callback();
        }

        $wasUnguarded = CoroutineContext::get(self::UNGUARDED_CONTEXT_KEY, false);
        CoroutineContext::set(self::UNGUARDED_CONTEXT_KEY, true);

        try {
            return $callback();
        } finally {
            CoroutineContext::set(self::UNGUARDED_CONTEXT_KEY, $wasUnguarded);
        }
    }

    /**
     * Determine if the given attribute may be mass assigned.
     */
    public function isFillable(string $key): bool
    {
        if (static::isUnguarded()) {
            return true;
        }

        // If the key is in the "fillable" array, we can of course assume that it's
        // a fillable attribute. Otherwise, we will check the guarded array when
        // we need to determine if the attribute is black-listed on the model.
        if (in_array($key, $this->getFillable(), true)) {
            return true;
        }

        // If the attribute is explicitly listed in the "guarded" array then we can
        // return false immediately. This means this attribute is definitely not
        // fillable and there is no point in going any further in this method.
        if ($this->isGuarded($key)) {
            return false;
        }

        return empty($this->getFillable())
            && ! str_contains($key, '.')
            && ! str_starts_with($key, '_');
    }

    /**
     * Determine if the given key is guarded.
     */
    public function isGuarded(string $key): bool
    {
        if (empty($this->getGuarded())) {
            return false;
        }

        if ($this->getGuarded() === ['*']) {
            return true;
        }

        // A key that is not valid UTF-8 names no attribute and cannot be normalized.
        if (! mb_check_encoding($key, 'UTF-8')) {
            return true;
        }

        // A key the class has not cached may be request input that this check rejects,
        // so lookups made while checking it are not cached.
        $guarding = ! isset(static::$attributeMethodNames[static::class][$key]);
        $previousGuard = null;

        if ($guarding) {
            $previousGuard = CoroutineContext::get(self::GUARDING_ATTRIBUTE_CONTEXT_KEY);
            CoroutineContext::set(self::GUARDING_ATTRIBUTE_CONTEXT_KEY, Coroutine::id());
        }

        try {
            // Normalize the key to match against guarded list by using the same
            // normalization applied in attribute resolution paths (studly/camel).
            // This prevents bypass via alternate spellings like 'is-admin' vs 'is_admin'.
            // Build both patterns once rather than for every guarded key.
            $keyPattern = '/^' . preg_quote($key, '/') . '$/i';
            $normalizedKeyPattern = '/^' . preg_quote(Str::studly($key), '/') . '$/i';

            foreach ($this->getGuarded() as $guardedKey) {
                // Compare both the raw key (for backward compatibility with exact matches)
                // and the normalized key (to prevent bypass via delimiter/case variations).
                // Only a setter resolves alternate spellings to the guarded attribute; without one,
                // the key is stored as given, so a distinct column such as "username" stays fillable.
                if (preg_match($keyPattern, $guardedKey)
                    || (preg_match($normalizedKeyPattern, Str::studly($guardedKey))
                        && ($this->hasSetMutator($key) || $this->hasAttributeSetMutator($key)))) {
                    return true;
                }
            }

            return ! $this->isGuardableColumn($key);
        } finally {
            if ($guarding) {
                CoroutineContext::set(self::GUARDING_ATTRIBUTE_CONTEXT_KEY, $previousGuard);
            }
        }
    }

    /**
     * Determine if the given column is a valid, guardable column.
     */
    protected function isGuardableColumn(string $key): bool
    {
        if ($this->hasSetMutator($key) || $this->hasAttributeSetMutator($key) || $this->isClassCastable($key)) {
            return true;
        }

        if (! isset(static::$guardableColumns[get_class($this)])) {
            $columns = $this->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($this->getTable());

            if (empty($columns)) {
                return true;
            }

            static::$guardableColumns[get_class($this)] = $columns;
        }

        return in_array($key, static::$guardableColumns[get_class($this)], true);
    }

    /**
     * Determine if the model is totally guarded.
     */
    public function totallyGuarded(): bool
    {
        return count($this->getFillable()) === 0 && $this->getGuarded() === ['*'];
    }

    /**
     * Get the fillable attributes of a given array.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function fillableFromArray(array $attributes): array
    {
        if (count($this->getFillable()) > 0 && ! static::isUnguarded()) {
            return array_intersect_key($attributes, array_flip($this->getFillable()));
        }

        return $attributes;
    }
}
