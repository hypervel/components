<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException as BrickMathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsInboundAttributes;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Casts\AsCollection;
use Hypervel\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Hypervel\Database\Eloquent\Casts\AsEncryptedCollection;
use Hypervel\Database\Eloquent\Casts\AsEnumArrayObject;
use Hypervel\Database\Eloquent\Casts\AsEnumCollection;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Casts\Json;
use Hypervel\Database\Eloquent\InvalidCastException;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\LazyLoadingViolationException;
use Hypervel\Support\Arr;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\Exceptions\MathException;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Str;
use Hypervel\Support\StrCache;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use SensitiveParameter;
use Stringable;
use UnitEnum;
use ValueError;

use function Hypervel\Support\enum_value;

trait HasAttributes
{
    /**
     * The model's attributes.
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * The model attribute's original state.
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * The changed model attributes.
     *
     * @var array<string, mixed>
     */
    protected array $changes = [];

    /**
     * The previous state of the changed model attributes.
     *
     * @var array<string, mixed>
     */
    protected array $previous = [];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected array $casts = [];

    /**
     * The attributes that have been cast using custom classes.
     */
    protected array $classCastCache = [];

    /**
     * The attributes that have been cast using "Attribute" return type mutators.
     */
    protected array $attributeCastCache = [];

    /**
     * The built-in, primitive cast types supported by Eloquent.
     *
     * @var string[]
     */
    protected static array $primitiveCastTypes = [
        'array',
        'bool',
        'boolean',
        'collection',
        'custom_datetime',
        'date',
        'datetime',
        'decimal',
        'double',
        'encrypted',
        'encrypted:array',
        'encrypted:collection',
        'encrypted:json',
        'encrypted:object',
        'float',
        'hashed',
        'immutable_date',
        'immutable_datetime',
        'immutable_custom_datetime',
        'int',
        'integer',
        'json',
        'json:unicode',
        'object',
        'real',
        'string',
        'timestamp',
    ];

    /**
     * The storage format of the model's date columns.
     */
    protected ?string $dateFormat = null;

    /**
     * The accessors to append to the model's array form.
     */
    protected array $appends = [];

    /**
     * Indicates whether attributes are snake cased on arrays.
     */
    public static bool $snakeAttributes = true;

    /**
     * The cache of the mutated attributes for each class.
     */
    protected static array $mutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated attributes for each class.
     */
    protected static array $attributeMutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated, gettable attributes for each class.
     */
    protected static array $getAttributeMutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated, settable attributes for each class.
     */
    protected static array $setAttributeMutatorCache = [];

    /**
     * The cache of the converted cast types.
     */
    protected static array $castTypeCache = [];

    /**
     * The encrypter instance that is used to encrypt attributes.
     *
     * @var null|\Hypervel\Contracts\Encryption\Encrypter
     */
    public static mixed $encrypter = null;

    /**
     * Initialize the trait.
     */
    protected function initializeHasAttributes(): void
    {
        $this->casts = $this->ensureCastsAreStringValues(
            array_merge($this->casts, $this->casts()),
        );
    }

    /**
     * Convert the model's attributes to an array.
     *
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        // If an attribute is a date, we will cast it to a string after converting it
        // to a DateTime / Carbon instance. This is so we will get some consistent
        // formatting while accessing attributes vs. arraying / JSONing a model.
        $attributes = $this->addDateAttributesToArray(
            $attributes = $this->getArrayableAttributes()
        );

        $attributes = $this->addMutatedAttributesToArray(
            $attributes,
            $mutatedAttributes = $this->getMutatedAttributes()
        );

        // Next we will handle any casts that have been setup for this model and cast
        // the values to their appropriate type. If the attribute has a mutator we
        // will not perform the cast on those attributes to avoid any confusion.
        $attributes = $this->addCastAttributesToArray(
            $attributes,
            $mutatedAttributes
        );

        // Here we will grab all of the appended, calculated attributes to this model
        // as these attributes are not really in the attributes array, but are run
        // when we need to array or JSON the model for convenience to the coder.
        foreach ($this->getArrayableAppends() as $key) {
            $attributes[$key] = $this->mutateAttributeForArray($key, null);
        }

        return $attributes;
    }

    /**
     * Add the date attributes to the attributes array.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function addDateAttributesToArray(array $attributes): array
    {
        foreach ($this->getDates() as $key) {
            if (is_null($key) || ! isset($attributes[$key])) {
                continue;
            }

            $attributes[$key] = $this->serializeDate(
                $this->asDateTime($attributes[$key])
            );
        }

        return $attributes;
    }

    /**
     * Add the mutated attributes to the attributes array.
     *
     * @param array<string, mixed> $attributes
     * @param array<string> $mutatedAttributes
     * @return array<string, mixed>
     */
    protected function addMutatedAttributesToArray(array $attributes, array $mutatedAttributes): array
    {
        foreach ($mutatedAttributes as $key) {
            // We want to spin through all the mutated attributes for this model and call
            // the mutator for the attribute. We cache off every mutated attributes so
            // we don't have to constantly check on attributes that actually change.
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            // Next, we will call the mutator for this attribute so that we can get these
            // mutated attribute's actual values. After we finish mutating each of the
            // attributes we will return this final array of the mutated attributes.
            $attributes[$key] = $this->mutateAttributeForArray(
                $key,
                $attributes[$key]
            );
        }

        return $attributes;
    }

    /**
     * Add the casted attributes to the attributes array.
     *
     * @param array<string, mixed> $attributes
     * @param array<string> $mutatedAttributes
     * @return array<string, mixed>
     */
    protected function addCastAttributesToArray(array $attributes, array $mutatedAttributes): array
    {
        foreach ($this->getCasts() as $key => $value) {
            if (! array_key_exists($key, $attributes)
                || in_array($key, $mutatedAttributes)) {
                continue;
            }

            // Here we will cast the attribute. Then, if the cast is a date or datetime cast
            // then we will serialize the date for the array. This will convert the dates
            // to strings based on the date format specified for these Eloquent models.
            $attributes[$key] = $this->castAttribute(
                $key,
                $attributes[$key]
            );

            // If the attribute cast was a date or a datetime, we will serialize the date as
            // a string. This allows the developers to customize how dates are serialized
            // into an array without affecting how they are persisted into the storage.
            if (isset($attributes[$key]) && in_array($value, ['date', 'datetime', 'immutable_date', 'immutable_datetime'])) {
                $attributes[$key] = $this->serializeDate($attributes[$key]);
            }

            if (isset($attributes[$key]) && ($this->isCustomDateTimeCast($value)
                || $this->isImmutableCustomDateTimeCast($value))) {
                $attributes[$key] = $attributes[$key]->format(explode(':', $value, 2)[1]);
            }

            if ($attributes[$key] instanceof DateTimeInterface
                && $this->isClassCastable($key)) {
                $attributes[$key] = $this->serializeDate($attributes[$key]);
            }

            if (isset($attributes[$key]) && $this->isClassSerializable($key)) {
                $attributes[$key] = $this->serializeClassCastableAttribute($key, $attributes[$key]);
            }

            if ($this->isEnumCastable($key) && (! ($attributes[$key] ?? null) instanceof Arrayable)) {
                $attributes[$key] = isset($attributes[$key]) ? $this->getStorableEnumValue($this->getCasts()[$key], $attributes[$key]) : null;
            }

            if ($attributes[$key] instanceof Arrayable) {
                $attributes[$key] = $attributes[$key]->toArray();
            }
        }

        return $attributes;
    }

    /**
     * Get an attribute array of all arrayable attributes.
     *
     * @return array<string, mixed>
     */
    protected function getArrayableAttributes(): array
    {
        return $this->getArrayableItems($this->getAttributes());
    }

    /**
     * Get all of the appendable values that are arrayable.
     */
    protected function getArrayableAppends(): array
    {
        if (! count($this->appends)) {
            return [];
        }

        return $this->getArrayableItems(
            array_combine($this->appends, $this->appends)
        );
    }

    /**
     * Get the model's relationships in array form.
     */
    public function relationsToArray(): array
    {
        $attributes = [];

        foreach ($this->getArrayableRelations() as $key => $value) {
            // If the values implement the Arrayable interface we can just call this
            // toArray method on the instances which will convert both models and
            // collections to their proper array form and we'll set the values.
            if ($value instanceof Arrayable) {
                $relation = $value->toArray();
            }

            // If the value is null, we'll still go ahead and set it in this list of
            // attributes, since null is used to represent empty relationships if
            // it has a has one or belongs to type relationships on the models.
            elseif (is_null($value)) {
                $relation = $value;
            }

            // If the relationships snake-casing is enabled, we will snake case this
            // key so that the relation attribute is snake cased in this returned
            // array to the developers, making this consistent with attributes.
            if (static::$snakeAttributes) {
                $key = StrCache::snake($key);
            }

            // If the relation value has been set, we will set it on this attributes
            // list for returning. If it was not arrayable or null, we'll not set
            // the value on the array because it is some type of invalid value.
            if (array_key_exists('relation', get_defined_vars())) { // check if $relation is in scope (could be null)
                $attributes[$key] = $relation ?? null;
            }

            unset($relation);
        }

        return $attributes;
    }

    /**
     * Get an attribute array of all arrayable relations.
     */
    protected function getArrayableRelations(): array
    {
        return $this->getArrayableItems($this->relations);
    }

    /**
     * Get an attribute array of all arrayable values.
     */
    protected function getArrayableItems(array $values): array
    {
        if (count($this->getVisible()) > 0) {
            $values = array_intersect_key($values, array_flip($this->getVisible()));
        }

        if (count($this->getHidden()) > 0) {
            $values = array_diff_key($values, array_flip($this->getHidden()));
        }

        return $values;
    }

    /**
     * Determine whether an attribute exists on the model.
     */
    public function hasAttribute(string $key): bool
    {
        if (! $key) {
            return false;
        }

        return array_key_exists($key, $this->attributes)
            || array_key_exists($key, $this->casts)
            || $this->hasGetMutator($key)
            || $this->hasAttributeMutator($key)
            || $this->isClassCastable($key);
    }

    /**
     * Get an attribute from the model.
     */
    public function getAttribute(string $key): mixed
    {
        if (! $key) {
            return null;
        }

        // If the attribute exists in the attribute array or has a "get" mutator we will
        // get the attribute's value. Otherwise, we will proceed as if the developers
        // are asking for a relationship's value. This covers both types of values.
        if ($this->hasAttribute($key)) {
            return $this->getAttributeValue($key);
        }

        // Here we will determine if the model base class itself contains this given key
        // since we don't want to treat any of those methods as relationships because
        // they are all intended as helper methods and none of these are relations.
        if (method_exists(self::class, $key)) {
            return $this->throwMissingAttributeExceptionIfApplicable($key);
        }

        return $this->isRelation($key) || $this->relationLoaded($key)
            ? $this->getRelationValue($key)
            : $this->throwMissingAttributeExceptionIfApplicable($key);
    }

    /**
     * Either throw a missing attribute exception or return null depending on Eloquent's configuration.
     *
     * @throws \Hypervel\Database\Eloquent\MissingAttributeException
     */
    protected function throwMissingAttributeExceptionIfApplicable(string $key): mixed
    {
        if ($this->exists
            && ! $this->wasRecentlyCreated
            && static::preventsAccessingMissingAttributes()) {
            if (isset(static::$missingAttributeViolationCallback)) {
                return call_user_func(static::$missingAttributeViolationCallback, $this, $key);
            }

            throw new MissingAttributeException($this, $key);
        }

        return null;
    }

    /**
     * Get a plain attribute (not a relationship).
     */
    public function getAttributeValue(string $key): mixed
    {
        return $this->transformModelValue($key, $this->getAttributeFromArray($key));
    }

    /**
     * Get an attribute from the $attributes array.
     */
    protected function getAttributeFromArray(string $key): mixed
    {
        return $this->getAttributes()[$key] ?? null;
    }

    /**
     * Get a relationship.
     */
    public function getRelationValue(string $key): mixed
    {
        // If the key already exists in the relationships array, it just means the
        // relationship has already been loaded, so we'll just return it out of
        // here because there is no need to query within the relations twice.
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if (! $this->isRelation($key)) {
            return null;
        }

        if ($this->attemptToAutoloadRelation($key)) {
            return $this->relations[$key];
        }

        if ($this->preventsLazyLoading) {
            $this->handleLazyLoadingViolation($key);
        }

        // If the "attribute" exists as a method on the model, we will just assume
        // it is a relationship and will load and return results from the query
        // and hydrate the relationship's value on the "relationships" array.
        return $this->getRelationshipFromMethod($key);
    }

    /**
     * Determine if the given key is a relationship method on the model.
     */
    public function isRelation(string $key): bool
    {
        if ($this->hasAttributeMutator($key)) {
            return false;
        }

        return method_exists($this, $key)
               || $this->relationResolver(static::class, $key);
    }

    /**
     * Handle a lazy loading violation.
     */
    protected function handleLazyLoadingViolation(string $key): mixed
    {
        if (isset(static::$lazyLoadingViolationCallback)) {
            return call_user_func(static::$lazyLoadingViolationCallback, $this, $key);
        }

        if (! $this->exists || $this->wasRecentlyCreated) {
            return null;
        }

        throw new LazyLoadingViolationException($this, $key);
    }

    /**
     * Get a relationship value from a method.
     *
     * @throws LogicException
     */
    protected function getRelationshipFromMethod(string $method): mixed
    {
        $relation = $this->{$method}();

        if (! $relation instanceof Relation) {
            if (is_null($relation)) {
                throw new LogicException(sprintf(
                    '%s::%s must return a relationship instance, but "null" was returned. Was the "return" keyword used?',
                    static::class,
                    $method
                ));
            }

            throw new LogicException(sprintf(
                '%s::%s must return a relationship instance.',
                static::class,
                $method
            ));
        }

        return tap($relation->getResults(), function ($results) use ($method) {
            $this->setRelation($method, $results);
        });
    }

    /**
     * Determine if a get mutator exists for an attribute.
     */
    public function hasGetMutator(string $key): bool
    {
        return method_exists($this, 'get' . StrCache::studly($key) . 'Attribute');
    }

    /**
     * Determine if a "Attribute" return type marked mutator exists for an attribute.
     */
    public function hasAttributeMutator(string $key): bool
    {
        if (isset(static::$attributeMutatorCache[get_class($this)][$key])) {
            return static::$attributeMutatorCache[get_class($this)][$key];
        }

        if (! method_exists($this, $method = StrCache::camel($key))) {
            return static::$attributeMutatorCache[get_class($this)][$key] = false;
        }

        $returnType = (new ReflectionMethod($this, $method))->getReturnType();

        return static::$attributeMutatorCache[get_class($this)][$key]
                    = $returnType instanceof ReflectionNamedType
                    && $returnType->getName() === Attribute::class;
    }

    /**
     * Determine if a "Attribute" return type marked get mutator exists for an attribute.
     */
    public function hasAttributeGetMutator(string $key): bool
    {
        if (isset(static::$getAttributeMutatorCache[get_class($this)][$key])) {
            return static::$getAttributeMutatorCache[get_class($this)][$key];
        }

        if (! $this->hasAttributeMutator($key)) {
            return static::$getAttributeMutatorCache[get_class($this)][$key] = false;
        }

        return static::$getAttributeMutatorCache[get_class($this)][$key] = is_callable($this->{StrCache::camel($key)}()->get);
    }

    /**
     * Determine if any get mutator exists for an attribute.
     */
    public function hasAnyGetMutator(string $key): bool
    {
        return $this->hasGetMutator($key) || $this->hasAttributeGetMutator($key);
    }

    /**
     * Get the value of an attribute using its mutator.
     */
    protected function mutateAttribute(string $key, mixed $value): mixed
    {
        return $this->{'get' . StrCache::studly($key) . 'Attribute'}($value);
    }

    /**
     * Get the value of an "Attribute" return type marked attribute using its mutator.
     */
    protected function mutateAttributeMarkedAttribute(string $key, mixed $value): mixed
    {
        if (array_key_exists($key, $this->attributeCastCache)) {
            return $this->attributeCastCache[$key];
        }

        $attribute = $this->{StrCache::camel($key)}();

        $value = call_user_func($attribute->get ?: function ($value) {
            return $value;
        }, $value, $this->attributes);

        if ($attribute->withCaching || (is_object($value) && $attribute->withObjectCaching)) {
            $this->attributeCastCache[$key] = $value;
        } else {
            unset($this->attributeCastCache[$key]);
        }

        return $value;
    }

    /**
     * Get the value of an attribute using its mutator for array conversion.
     */
    protected function mutateAttributeForArray(string $key, mixed $value): mixed
    {
        if ($this->isClassCastable($key)) {
            $value = $this->getClassCastableAttributeValue($key, $value);
        } elseif (isset(static::$getAttributeMutatorCache[get_class($this)][$key])
                  && static::$getAttributeMutatorCache[get_class($this)][$key] === true) {
            $value = $this->mutateAttributeMarkedAttribute($key, $value);

            $value = $value instanceof DateTimeInterface
                ? $this->serializeDate($value)
                : $value;
        } else {
            $value = $this->mutateAttribute($key, $value);
        }

        return $value instanceof Arrayable ? $value->toArray() : $value;
    }

    /**
     * Merge new casts with existing casts on the model.
     */
    public function mergeCasts(array $casts): static
    {
        $casts = $this->ensureCastsAreStringValues($casts);

        $this->casts = array_merge($this->casts, $casts);

        return $this;
    }

    /**
     * Ensure that the given casts are strings.
     */
    protected function ensureCastsAreStringValues(array $casts): array
    {
        foreach ($casts as $attribute => $cast) {
            $casts[$attribute] = match (true) {
                is_object($cast) => value(function () use ($cast, $attribute) {
                    if ($cast instanceof Stringable) {
                        return (string) $cast;
                    }

                    throw new InvalidArgumentException(
                        "The cast object for the {$attribute} attribute must implement Stringable."
                    );
                }),
                is_array($cast) => value(function () use ($cast) {
                    if (count($cast) === 1) {
                        return $cast[0];
                    }

                    [$cast, $arguments] = [array_shift($cast), $cast];

                    return $cast . ':' . implode(',', $arguments);
                }),
                default => $cast,
            };
        }

        return $casts;
    }

    /**
     * Cast an attribute to a native PHP type.
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        $castType = $this->getCastType($key);

        if (is_null($value) && in_array($castType, static::$primitiveCastTypes)) {
            return $value;
        }

        // If the key is one of the encrypted castable types, we'll first decrypt
        // the value and update the cast type so we may leverage the following
        // logic for casting this value to any additionally specified types.
        if ($this->isEncryptedCastable($key)) {
            $value = $this->fromEncryptedString($value);

            $castType = Str::after($castType, 'encrypted:');
        }

        switch ($castType) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return $this->fromFloat($value);
            case 'decimal':
                return $this->asDecimal($value, (int) explode(':', $this->getCasts()[$key], 2)[1]);
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'object':
                return $this->fromJson($value, true);
            case 'array':
            case 'json':
            case 'json:unicode':
                return $this->fromJson($value);
            case 'collection':
                return new BaseCollection($this->fromJson($value));
            case 'date':
                return $this->asDate($value);
            case 'datetime':
            case 'custom_datetime':
                return $this->asDateTime($value);
            case 'immutable_date':
                return $this->asDate($value)->toImmutable();
            case 'immutable_custom_datetime':
            case 'immutable_datetime':
                return $this->asDateTime($value)->toImmutable();
            case 'timestamp':
                return $this->asTimestamp($value);
        }

        if ($this->isEnumCastable($key)) {
            return $this->getEnumCastableAttributeValue($key, $value);
        }

        if ($this->isClassCastable($key)) {
            return $this->getClassCastableAttributeValue($key, $value);
        }

        return $value;
    }

    /**
     * Cast the given attribute using a custom cast class.
     */
    protected function getClassCastableAttributeValue(string $key, mixed $value): mixed
    {
        $caster = $this->resolveCasterClass($key);

        $objectCachingDisabled = $caster->withoutObjectCaching ?? false;

        if (isset($this->classCastCache[$key]) && ! $objectCachingDisabled) {
            return $this->classCastCache[$key];
        }
        $value = $caster instanceof CastsInboundAttributes
            ? $value
            : $caster->get($this, $key, $value, $this->attributes);

        if ($caster instanceof CastsInboundAttributes
            || ! is_object($value)
            || $objectCachingDisabled) {
            unset($this->classCastCache[$key]);
        } else {
            $this->classCastCache[$key] = $value;
        }

        return $value;
    }

    /**
     * Cast the given attribute to an enum.
     */
    protected function getEnumCastableAttributeValue(string $key, mixed $value): mixed
    {
        if (is_null($value)) {
            return null;
        }

        $castType = $this->getCasts()[$key];

        if ($value instanceof $castType) {
            return $value;
        }

        return $this->getEnumCaseFromValue($castType, $value);
    }

    /**
     * Get the type of cast for a model attribute.
     */
    protected function getCastType(string $key): string
    {
        $castType = $this->getCasts()[$key];

        if (isset(static::$castTypeCache[$castType])) {
            return static::$castTypeCache[$castType];
        }

        if ($this->isCustomDateTimeCast($castType)) {
            $convertedCastType = 'custom_datetime';
        } elseif ($this->isImmutableCustomDateTimeCast($castType)) {
            $convertedCastType = 'immutable_custom_datetime';
        } elseif ($this->isDecimalCast($castType)) {
            $convertedCastType = 'decimal';
        } elseif (class_exists($castType)) {
            $convertedCastType = $castType;
        } else {
            $convertedCastType = trim(strtolower($castType));
        }

        return static::$castTypeCache[$castType] = $convertedCastType;
    }

    /**
     * Increment or decrement the given attribute using the custom cast class.
     */
    protected function deviateClassCastableAttribute(string $method, string $key, mixed $value): mixed
    {
        return $this->resolveCasterClass($key)->{$method}(
            $this,
            $key,
            $value,
            $this->attributes
        );
    }

    /**
     * Serialize the given attribute using the custom cast class.
     */
    protected function serializeClassCastableAttribute(string $key, mixed $value): mixed
    {
        return $this->resolveCasterClass($key)->serialize(
            $this,
            $key,
            $value,
            $this->attributes
        );
    }

    /**
     * Compare two values for the given attribute using the custom cast class.
     */
    protected function compareClassCastableAttribute(string $key, mixed $original, mixed $value): bool
    {
        return $this->resolveCasterClass($key)->compare(
            $this,
            $key,
            $original,
            $value
        );
    }

    /**
     * Determine if the cast type is a custom date time cast.
     */
    protected function isCustomDateTimeCast(string $cast): bool
    {
        return str_starts_with($cast, 'date:')
                || str_starts_with($cast, 'datetime:');
    }

    /**
     * Determine if the cast type is an immutable custom date time cast.
     */
    protected function isImmutableCustomDateTimeCast(string $cast): bool
    {
        return str_starts_with($cast, 'immutable_date:')
                || str_starts_with($cast, 'immutable_datetime:');
    }

    /**
     * Determine if the cast type is a decimal cast.
     */
    protected function isDecimalCast(string $cast): bool
    {
        return str_starts_with($cast, 'decimal:');
    }

    /**
     * Set a given attribute on the model.
     */
    public function setAttribute(string|int $key, mixed $value): mixed
    {
        // Numeric keys cannot have mutators or casts, so store directly.
        if (is_int($key)) {
            $this->attributes[$key] = $value;

            return $this;
        }

        // First we will check for the presence of a mutator for the set operation
        // which simply lets the developers tweak the attribute as it is set on
        // this model, such as "json_encoding" a listing of data for storage.
        if ($this->hasSetMutator($key)) {
            return $this->setMutatedAttributeValue($key, $value);
        }
        if ($this->hasAttributeSetMutator($key)) {
            return $this->setAttributeMarkedMutatedAttributeValue($key, $value);
        }

        // If an attribute is listed as a "date", we'll convert it from a DateTime
        // instance into a form proper for storage on the database tables using
        // the connection grammar's date format. We will auto set the values.
        if (! is_null($value) && $this->isDateAttribute($key)) {
            $value = $this->fromDateTime($value);
        }

        if ($this->isEnumCastable($key)) {
            $this->setEnumCastableAttribute($key, $value);

            return $this;
        }

        if ($this->isClassCastable($key)) {
            $this->setClassCastableAttribute($key, $value);

            return $this;
        }

        if (! is_null($value) && $this->isJsonCastable($key)) {
            $value = $this->castAttributeAsJson($key, $value);
        }

        // If this attribute contains a JSON ->, we'll set the proper value in the
        // attribute's underlying array. This takes care of properly nesting an
        // attribute in the array's value in the case of deeply nested items.
        if (str_contains($key, '->')) {
            return $this->fillJsonAttribute($key, $value);
        }

        if (! is_null($value) && $this->isEncryptedCastable($key)) {
            $value = $this->castAttributeAsEncryptedString($key, $value);
        }

        if (! is_null($value) && $this->hasCast($key, 'hashed')) {
            $value = $this->castAttributeAsHashedString($key, $value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Determine if a set mutator exists for an attribute.
     */
    public function hasSetMutator(string $key): bool
    {
        return method_exists($this, 'set' . StrCache::studly($key) . 'Attribute');
    }

    /**
     * Determine if an "Attribute" return type marked set mutator exists for an attribute.
     */
    public function hasAttributeSetMutator(string $key): bool
    {
        $class = get_class($this);

        if (isset(static::$setAttributeMutatorCache[$class][$key])) {
            return static::$setAttributeMutatorCache[$class][$key];
        }

        if (! method_exists($this, $method = StrCache::camel($key))) {
            return static::$setAttributeMutatorCache[$class][$key] = false;
        }

        $returnType = (new ReflectionMethod($this, $method))->getReturnType();

        return static::$setAttributeMutatorCache[$class][$key]
                    = $returnType instanceof ReflectionNamedType
                    && $returnType->getName() === Attribute::class
                    && is_callable($this->{$method}()->set);
    }

    /**
     * Set the value of an attribute using its mutator.
     */
    protected function setMutatedAttributeValue(string $key, mixed $value): mixed
    {
        return $this->{'set' . StrCache::studly($key) . 'Attribute'}($value);
    }

    /**
     * Set the value of a "Attribute" return type marked attribute using its mutator.
     */
    protected function setAttributeMarkedMutatedAttributeValue(string $key, mixed $value): mixed
    {
        $attribute = $this->{StrCache::camel($key)}();

        $callback = $attribute->set ?: function ($value) use ($key) {
            $this->attributes[$key] = $value;
        };

        $this->attributes = array_merge(
            $this->attributes,
            $this->normalizeCastClassResponse(
                $key,
                $callback($value, $this->attributes)
            )
        );

        if ($attribute->withCaching || (is_object($value) && $attribute->withObjectCaching)) {
            $this->attributeCastCache[$key] = $value;
        } else {
            unset($this->attributeCastCache[$key]);
        }

        return $this;
    }

    /**
     * Determine if the given attribute is a date or date castable.
     */
    protected function isDateAttribute(string $key): bool
    {
        return in_array($key, $this->getDates(), true)
            || $this->isDateCastable($key);
    }

    /**
     * Set a given JSON attribute on the model.
     */
    public function fillJsonAttribute(string $key, mixed $value): static
    {
        [$key, $path] = explode('->', $key, 2);

        $value = $this->asJson($this->getArrayAttributeWithValue(
            $path,
            $key,
            $value
        ), $this->getJsonCastFlags($key));

        $this->attributes[$key] = $this->isEncryptedCastable($key)
            ? $this->castAttributeAsEncryptedString($key, $value)
            : $value;

        if ($this->isClassCastable($key)) {
            unset($this->classCastCache[$key]);
        }

        return $this;
    }

    /**
     * Set the value of a class castable attribute.
     */
    protected function setClassCastableAttribute(string $key, mixed $value): void
    {
        $caster = $this->resolveCasterClass($key);

        $this->attributes = array_replace(
            $this->attributes,
            $this->normalizeCastClassResponse($key, $caster->set(
                $this,
                $key,
                $value,
                $this->attributes
            ))
        );

        if ($caster instanceof CastsInboundAttributes
            || ! is_object($value)
            || ($caster->withoutObjectCaching ?? false)) {
            unset($this->classCastCache[$key]);
        } else {
            $this->classCastCache[$key] = $value;
        }
    }

    /**
     * Set the value of an enum castable attribute.
     *
     * @param null|int|string|UnitEnum $value
     */
    protected function setEnumCastableAttribute(string $key, mixed $value): void
    {
        $enumClass = $this->getCasts()[$key];

        if (! isset($value)) {
            $this->attributes[$key] = null;
        } elseif (is_object($value)) {
            $this->attributes[$key] = $this->getStorableEnumValue($enumClass, $value);
        } else {
            $this->attributes[$key] = $this->getStorableEnumValue(
                $enumClass,
                $this->getEnumCaseFromValue($enumClass, $value)
            );
        }
    }

    /**
     * Get an enum case instance from a given class and value.
     *
     * @return BackedEnum|UnitEnum
     */
    protected function getEnumCaseFromValue(string $enumClass, string|int $value): mixed
    {
        return is_subclass_of($enumClass, BackedEnum::class)
            ? $enumClass::from($value)
            : constant($enumClass . '::' . $value);
    }

    /**
     * Get the storable value from the given enum.
     *
     * @param BackedEnum|UnitEnum $value
     */
    protected function getStorableEnumValue(string $expectedEnum, mixed $value): string|int
    {
        if (! $value instanceof $expectedEnum) {
            throw new ValueError(sprintf('Value [%s] is not of the expected enum type [%s].', var_export($value, true), $expectedEnum));
        }

        return enum_value($value);
    }

    /**
     * Get an array attribute with the given key and value set.
     */
    protected function getArrayAttributeWithValue(string $path, string $key, mixed $value): array
    {
        return tap($this->getArrayAttributeByKey($key), function (&$array) use ($path, $value) {
            Arr::set($array, str_replace('->', '.', $path), $value);
        });
    }

    /**
     * Get an array attribute or return an empty array if it is not set.
     */
    protected function getArrayAttributeByKey(string $key): array
    {
        if (! isset($this->attributes[$key])) {
            return [];
        }

        return $this->fromJson(
            $this->isEncryptedCastable($key)
                ? $this->fromEncryptedString($this->attributes[$key])
                : $this->attributes[$key]
        );
    }

    /**
     * Cast the given attribute to JSON.
     */
    protected function castAttributeAsJson(string $key, mixed $value): string
    {
        $value = $this->asJson($value, $this->getJsonCastFlags($key));

        if ($value === false) {
            throw JsonEncodingException::forAttribute(
                $this,
                $key,
                json_last_error_msg()
            );
        }

        return $value;
    }

    /**
     * Get the JSON casting flags for the given attribute.
     */
    protected function getJsonCastFlags(string $key): int
    {
        $flags = 0;

        if ($this->hasCast($key, ['json:unicode'])) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }

        return $flags;
    }

    /**
     * Encode the given value as JSON.
     */
    protected function asJson(mixed $value, int $flags = 0): string|false
    {
        return Json::encode($value, $flags);
    }

    /**
     * Decode the given JSON back into an array or object.
     */
    public function fromJson(?string $value, bool $asObject = false): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Json::decode($value, ! $asObject);
    }

    /**
     * Decrypt the given encrypted string.
     */
    public function fromEncryptedString(string $value): mixed
    {
        return static::currentEncrypter()->decrypt($value, false);
    }

    /**
     * Cast the given attribute to an encrypted string.
     */
    protected function castAttributeAsEncryptedString(string $key, #[SensitiveParameter] mixed $value): string
    {
        return static::currentEncrypter()->encrypt($value, false);
    }

    /**
     * Set the encrypter instance that will be used to encrypt attributes.
     *
     * @param null|\Hypervel\Contracts\Encryption\Encrypter $encrypter
     */
    public static function encryptUsing(mixed $encrypter): void
    {
        static::$encrypter = $encrypter;
    }

    /**
     * Get the current encrypter being used by the model.
     *
     * @return \Hypervel\Contracts\Encryption\Encrypter
     */
    public static function currentEncrypter(): mixed
    {
        return static::$encrypter ?? Crypt::getFacadeRoot();
    }

    /**
     * Cast the given attribute to a hashed string.
     */
    protected function castAttributeAsHashedString(string $key, #[SensitiveParameter] mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! Hash::isHashed($value)) {
            return Hash::make($value);
        }

        /* @phpstan-ignore staticMethod.notFound */
        if (! Hash::verifyConfiguration($value)) {
            throw new RuntimeException("Could not verify the hashed value's configuration.");
        }

        return $value;
    }

    /**
     * Decode the given float.
     */
    public function fromFloat(mixed $value): float
    {
        return match ((string) $value) {
            'Infinity' => INF,
            '-Infinity' => -INF,
            'NaN' => NAN,
            default => (float) $value,
        };
    }

    /**
     * Return a decimal as string.
     */
    protected function asDecimal(float|string $value, int $decimals): string
    {
        try {
            return (string) BigDecimal::of($value)->toScale($decimals, RoundingMode::HALF_UP);
        } catch (BrickMathException $e) {
            throw new MathException('Unable to cast value to a decimal.', previous: $e);
        }
    }

    /**
     * Return a timestamp as DateTime object with time set to 00:00:00.
     *
     * @return \Hypervel\Support\Carbon
     */
    protected function asDate(mixed $value): CarbonInterface
    {
        return $this->asDateTime($value)->startOfDay();
    }

    /**
     * Return a timestamp as DateTime object.
     *
     * @return \Hypervel\Support\Carbon
     */
    protected function asDateTime(mixed $value): CarbonInterface
    {
        // If this value is already a Carbon instance, we shall just return it as is.
        // This prevents us having to re-instantiate a Carbon instance when we know
        // it already is one, which wouldn't be fulfilled by the DateTime check.
        if ($value instanceof CarbonInterface) {
            return Date::instance($value);
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof DateTimeInterface) {
            return Date::parse(
                $value->format('Y-m-d H:i:s.u'),
                $value->getTimezone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Date::createFromTimestamp($value, date_default_timezone_get());
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format. Again, this provides for simple date
        // fields on the database, while still supporting Carbonized conversion.
        if ($this->isStandardDateFormat($value)) {
            return Date::instance(Carbon::createFromFormat('Y-m-d', $value)->startOfDay());
        }

        $format = $this->getDateFormat();

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        try {
            $date = Date::createFromFormat($format, $value);
            // @phpstan-ignore catch.neverThrown (defensive: some Carbon versions/configs may throw)
        } catch (InvalidArgumentException) {
            $date = false;
        }

        return $date ?: Date::parse($value);
    }

    /**
     * Determine if the given value is a standard date format.
     */
    protected function isStandardDateFormat(string $value): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value);
    }

    /**
     * Convert a DateTime to a storable string.
     */
    public function fromDateTime(mixed $value): ?string
    {
        return ($value === null || $value === '') ? $value : $this->asDateTime($value)->format(
            $this->getDateFormat()
        );
    }

    /**
     * Return a timestamp as unix timestamp.
     */
    protected function asTimestamp(mixed $value): int
    {
        return $this->asDateTime($value)->getTimestamp();
    }

    /**
     * Prepare a date for array / JSON serialization.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date instanceof DateTimeImmutable
            ? CarbonImmutable::instance($date)->toJSON()
            : Carbon::instance($date)->toJSON();
    }

    /**
     * Get the attributes that should be converted to dates.
     *
     * @return array<int, null|string>
     */
    public function getDates(): array
    {
        return $this->usesTimestamps() ? [
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ] : [];
    }

    /**
     * Get the format for database stored dates.
     */
    public function getDateFormat(): string
    {
        return $this->dateFormat ?: $this->getConnection()->getQueryGrammar()->getDateFormat();
    }

    /**
     * Set the date format used by the model.
     */
    public function setDateFormat(string $format): static
    {
        $this->dateFormat = $format;

        return $this;
    }

    /**
     * Determine whether an attribute should be cast to a native type.
     */
    public function hasCast(string $key, array|string|null $types = null): bool
    {
        if (array_key_exists($key, $this->getCasts())) {
            return $types ? in_array($this->getCastType($key), (array) $types, true) : true;
        }

        return false;
    }

    /**
     * Get the attributes that should be cast.
     */
    public function getCasts(): array
    {
        if ($this->getIncrementing()) {
            return array_merge([$this->getKeyName() => $this->getKeyType()], $this->casts);
        }

        return $this->casts;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    /**
     * Determine whether a value is Date / DateTime castable for inbound manipulation.
     */
    protected function isDateCastable(string $key): bool
    {
        return $this->hasCast($key, ['date', 'datetime', 'immutable_date', 'immutable_datetime']);
    }

    /**
     * Determine whether a value is Date / DateTime custom-castable for inbound manipulation.
     */
    protected function isDateCastableWithCustomFormat(string $key): bool
    {
        return $this->hasCast($key, ['custom_datetime', 'immutable_custom_datetime']);
    }

    /**
     * Determine whether a value is JSON castable for inbound manipulation.
     */
    protected function isJsonCastable(string $key): bool
    {
        return $this->hasCast($key, ['array', 'json', 'json:unicode', 'object', 'collection', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }

    /**
     * Determine whether a value is an encrypted castable for inbound manipulation.
     */
    protected function isEncryptedCastable(string $key): bool
    {
        return $this->hasCast($key, ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }

    /**
     * Determine if the given key is cast using a custom class.
     *
     * @throws \Hypervel\Database\Eloquent\InvalidCastException
     */
    protected function isClassCastable(string $key): bool
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $this->parseCasterClass($casts[$key]);

        if (in_array($castType, static::$primitiveCastTypes)) {
            return false;
        }

        if (class_exists($castType)) {
            return true;
        }

        throw new InvalidCastException($this, $key, $castType);
    }

    /**
     * Determine if the given key is cast using an enum.
     */
    protected function isEnumCastable(string $key): bool
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $casts[$key];

        if (in_array($castType, static::$primitiveCastTypes)) {
            return false;
        }

        if (is_subclass_of($castType, Castable::class)) {
            return false;
        }

        return enum_exists($castType);
    }

    /**
     * Determine if the key is deviable using a custom class.
     *
     * @throws \Hypervel\Database\Eloquent\InvalidCastException
     */
    protected function isClassDeviable(string $key): bool
    {
        if (! $this->isClassCastable($key)) {
            return false;
        }

        $castType = $this->resolveCasterClass($key);

        return method_exists($castType::class, 'increment') && method_exists($castType::class, 'decrement');
    }

    /**
     * Determine if the key is serializable using a custom class.
     *
     * @throws \Hypervel\Database\Eloquent\InvalidCastException
     */
    protected function isClassSerializable(string $key): bool
    {
        return ! $this->isEnumCastable($key)
            && $this->isClassCastable($key)
            && method_exists($this->resolveCasterClass($key), 'serialize');
    }

    /**
     * Determine if the key is comparable using a custom class.
     */
    protected function isClassComparable(string $key): bool
    {
        return ! $this->isEnumCastable($key)
            && $this->isClassCastable($key)
            && method_exists($this->resolveCasterClass($key), 'compare');
    }

    /**
     * Resolve the custom caster class for a given key.
     */
    protected function resolveCasterClass(string $key): mixed
    {
        $castType = $this->getCasts()[$key];

        $arguments = [];

        if (is_string($castType) && str_contains($castType, ':')) {
            $segments = explode(':', $castType, 2);

            $castType = $segments[0];
            $arguments = explode(',', $segments[1]);
        }

        if (is_subclass_of($castType, Castable::class)) {
            $castType = $castType::castUsing($arguments);
        }

        if (is_object($castType)) {
            return $castType;
        }

        return new $castType(...$arguments);
    }

    /**
     * Parse the given caster class, removing any arguments.
     */
    protected function parseCasterClass(string $class): string
    {
        return ! str_contains($class, ':')
            ? $class
            : explode(':', $class, 2)[0];
    }

    /**
     * Merge the cast class and attribute cast attributes back into the model.
     */
    protected function mergeAttributesFromCachedCasts(): void
    {
        $this->mergeAttributesFromClassCasts();
        $this->mergeAttributesFromAttributeCasts();
    }

    /**
     * Merge the cast class attributes back into the model.
     */
    protected function mergeAttributesFromClassCasts(): void
    {
        foreach ($this->classCastCache as $key => $value) {
            $caster = $this->resolveCasterClass($key);

            $this->attributes = array_merge(
                $this->attributes,
                $caster instanceof CastsInboundAttributes
                    ? [$key => $value]
                    : $this->normalizeCastClassResponse($key, $caster->set($this, $key, $value, $this->attributes))
            );
        }
    }

    /**
     * Merge the cast class attributes back into the model.
     */
    protected function mergeAttributesFromAttributeCasts(): void
    {
        foreach ($this->attributeCastCache as $key => $value) {
            $attribute = $this->{StrCache::camel($key)}();

            if ($attribute->get && ! $attribute->set) {
                continue;
            }

            $callback = $attribute->set ?: function ($value) use ($key) {
                $this->attributes[$key] = $value;
            };

            $this->attributes = array_merge(
                $this->attributes,
                $this->normalizeCastClassResponse(
                    $key,
                    $callback($value, $this->attributes)
                )
            );
        }
    }

    /**
     * Normalize the response from a custom class caster.
     */
    protected function normalizeCastClassResponse(string $key, mixed $value): array
    {
        return is_array($value) ? $value : [$key => $value];
    }

    /**
     * Get all of the current attributes on the model.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        $this->mergeAttributesFromCachedCasts();

        return $this->attributes;
    }

    /**
     * Get all of the current attributes on the model for an insert operation.
     */
    protected function getAttributesForInsert(): array
    {
        return $this->getAttributes();
    }

    /**
     * Set the array of model attributes. No checking is done.
     */
    public function setRawAttributes(array $attributes, bool $sync = false): static
    {
        $this->attributes = $attributes;

        if ($sync) {
            $this->syncOriginal();
        }

        $this->classCastCache = [];
        $this->attributeCastCache = [];

        return $this;
    }

    /**
     * Get the model's original attribute values.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function getOriginal(?string $key = null, mixed $default = null): mixed
    {
        return (new static())->setRawAttributes(
            $this->original,
            $sync = true
        )->getOriginalWithoutRewindingModel($key, $default);
    }

    /**
     * Get the model's original attribute values.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    protected function getOriginalWithoutRewindingModel(?string $key = null, mixed $default = null): mixed
    {
        if ($key) {
            return $this->transformModelValue(
                $key,
                Arr::get($this->original, $key, $default)
            );
        }

        return (new Collection($this->original))
            ->mapWithKeys(fn ($value, $key) => [$key => $this->transformModelValue($key, $value)])
            ->all();
    }

    /**
     * Get the model's raw original attribute values.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function getRawOriginal(?string $key = null, mixed $default = null): mixed
    {
        return Arr::get($this->original, $key, $default);
    }

    /**
     * Get a subset of the model's attributes.
     *
     * @param array<string>|mixed $attributes
     * @return array<string, mixed>
     */
    public function only(mixed $attributes): array
    {
        $results = [];

        foreach (is_array($attributes) ? $attributes : func_get_args() as $attribute) {
            $results[$attribute] = $this->getAttribute($attribute);
        }

        return $results;
    }

    /**
     * Get all attributes except the given ones.
     *
     * @param array<string>|mixed $attributes
     */
    public function except(mixed $attributes): array
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $results = [];

        foreach ($this->getAttributes() as $key => $value) {
            if (! in_array($key, $attributes)) {
                $results[$key] = $this->getAttribute($key);
            }
        }

        return $results;
    }

    /**
     * Sync the original attributes with the current.
     */
    public function syncOriginal(): static
    {
        $this->original = $this->getAttributes();

        return $this;
    }

    /**
     * Sync a single original attribute with its current value.
     */
    public function syncOriginalAttribute(string $attribute): static
    {
        return $this->syncOriginalAttributes($attribute);
    }

    /**
     * Sync multiple original attribute with their current values.
     *
     * @param array<string>|string $attributes
     */
    public function syncOriginalAttributes(array|string $attributes): static
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $modelAttributes = $this->getAttributes();

        foreach ($attributes as $attribute) {
            $this->original[$attribute] = $modelAttributes[$attribute];
        }

        return $this;
    }

    /**
     * Sync the changed attributes.
     */
    public function syncChanges(): static
    {
        $this->changes = $this->getDirty();
        $this->previous = array_intersect_key($this->getRawOriginal(), $this->changes);

        return $this;
    }

    /**
     * Determine if the model or any of the given attribute(s) have been modified.
     *
     * @param null|array<string>|string $attributes
     */
    public function isDirty(array|string|null $attributes = null): bool
    {
        return $this->hasChanges(
            $this->getDirty(),
            is_array($attributes) ? $attributes : func_get_args()
        );
    }

    /**
     * Determine if the model or all the given attribute(s) have remained the same.
     *
     * @param null|array<string>|string $attributes
     */
    public function isClean(array|string|null $attributes = null): bool
    {
        return ! $this->isDirty(...func_get_args());
    }

    /**
     * Discard attribute changes and reset the attributes to their original state.
     */
    public function discardChanges(): static
    {
        [$this->attributes, $this->changes, $this->previous] = [$this->original, [], []];

        $this->classCastCache = [];
        $this->attributeCastCache = [];

        return $this;
    }

    /**
     * Determine if the model or any of the given attribute(s) were changed when the model was last saved.
     *
     * @param null|array<string>|string $attributes
     */
    public function wasChanged(array|string|null $attributes = null): bool
    {
        return $this->hasChanges(
            $this->getChanges(),
            is_array($attributes) ? $attributes : func_get_args()
        );
    }

    /**
     * Determine if any of the given attributes were changed when the model was last saved.
     *
     * @param array<string> $changes
     * @param null|array<string>|string $attributes
     */
    protected function hasChanges(array $changes, array|string|null $attributes = null): bool
    {
        // If no specific attributes were provided, we will just see if the dirty array
        // already contains any attributes. If it does we will just return that this
        // count is greater than zero. Else, we need to check specific attributes.
        if (empty($attributes)) {
            return count($changes) > 0;
        }

        // Here we will spin through every attribute and see if this is in the array of
        // dirty attributes. If it is, we will return true and if we make it through
        // all of the attributes for the entire array we will return false at end.
        foreach (Arr::wrap($attributes) as $attribute) {
            if (array_key_exists($attribute, $changes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the attributes that have been changed since the last sync.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->getAttributes() as $key => $value) {
            if (! $this->originalIsEquivalent($key)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Get the attributes that have been changed since the last sync for an update operation.
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate(): array
    {
        return $this->getDirty();
    }

    /**
     * Get the attributes that were changed when the model was last saved.
     *
     * @return array<string, mixed>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * Get the attributes that were previously original before the model was last saved.
     *
     * @return array<string, mixed>
     */
    public function getPrevious(): array
    {
        return $this->previous;
    }

    /**
     * Determine if the new and old values for a given key are equivalent.
     */
    public function originalIsEquivalent(string $key): bool
    {
        if (! array_key_exists($key, $this->original)) {
            return false;
        }

        $attribute = Arr::get($this->attributes, $key);
        $original = Arr::get($this->original, $key);

        if ($attribute === $original) {
            return true;
        }
        if (is_null($attribute)) {
            return false;
        }
        if ($this->isDateAttribute($key) || $this->isDateCastableWithCustomFormat($key)) {
            return $this->fromDateTime($attribute)
                === $this->fromDateTime($original);
        }
        if ($this->hasCast($key, ['object', 'collection'])) {
            return $this->fromJson($attribute)
                === $this->fromJson($original);
        }
        if ($this->hasCast($key, ['real', 'float', 'double'])) {
            if ($original === null) {
                return false;
            }

            return abs($this->castAttribute($key, $attribute) - $this->castAttribute($key, $original)) < PHP_FLOAT_EPSILON * 4;
        }
        if ($this->isEncryptedCastable($key) && ! empty(static::currentEncrypter()->getPreviousKeys())) {
            return false;
        }
        if ($this->hasCast($key, static::$primitiveCastTypes)) {
            return $this->castAttribute($key, $attribute)
                === $this->castAttribute($key, $original);
        }
        if ($this->isClassCastable($key) && Str::startsWith($this->getCasts()[$key], [AsArrayObject::class, AsCollection::class])) {
            return $this->fromJson($attribute) === $this->fromJson($original);
        }
        if ($this->isClassCastable($key) && Str::startsWith($this->getCasts()[$key], [AsEnumArrayObject::class, AsEnumCollection::class])) {
            return $this->fromJson($attribute) === $this->fromJson($original);
        }
        if ($this->isClassCastable($key) && $original !== null && Str::startsWith($this->getCasts()[$key], [AsEncryptedArrayObject::class, AsEncryptedCollection::class])) {
            if (empty(static::currentEncrypter()->getPreviousKeys())) {
                return $this->fromEncryptedString($attribute) === $this->fromEncryptedString($original);
            }

            return false;
        }
        if ($this->isClassComparable($key)) {
            return $this->compareClassCastableAttribute($key, $original, $attribute);
        }

        return is_numeric($attribute) && is_numeric($original)
            && strcmp((string) $attribute, (string) $original) === 0;
    }

    /**
     * Transform a raw model value using mutators, casts, etc.
     */
    protected function transformModelValue(string $key, mixed $value): mixed
    {
        // If the attribute has a get mutator, we will call that then return what
        // it returns as the value, which is useful for transforming values on
        // retrieval from the model to a form that is more useful for usage.
        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $value);
        }
        if ($this->hasAttributeGetMutator($key)) {
            return $this->mutateAttributeMarkedAttribute($key, $value);
        }

        // If the attribute exists within the cast array, we will convert it to
        // an appropriate native PHP type dependent upon the associated value
        // given with the key in the pair. Dayle made this comment line up.
        if ($this->hasCast($key)) {
            if (static::preventsAccessingMissingAttributes()
                && ! array_key_exists($key, $this->attributes)
                && ($this->isEnumCastable($key)
                 || in_array($this->getCastType($key), static::$primitiveCastTypes))) {
                $this->throwMissingAttributeExceptionIfApplicable($key);
            }

            return $this->castAttribute($key, $value);
        }

        // If the attribute is listed as a date, we will convert it to a DateTime
        // instance on retrieval, which makes it quite convenient to work with
        // date fields without having to create a mutator for each property.
        if ($value !== null
            && \in_array($key, $this->getDates(), false)) {
            return $this->asDateTime($value);
        }

        return $value;
    }

    /**
     * Append attributes to query when building a query.
     *
     * @param array<string>|string $attributes
     */
    public function append(array|string $attributes): static
    {
        $this->appends = array_values(array_unique(
            array_merge($this->appends, is_string($attributes) ? func_get_args() : $attributes)
        ));

        return $this;
    }

    /**
     * Get the accessors that are being appended to model arrays.
     */
    public function getAppends(): array
    {
        return $this->appends;
    }

    /**
     * Set the accessors to append to model arrays.
     */
    public function setAppends(array $appends): static
    {
        $this->appends = $appends;

        return $this;
    }

    /**
     * Merge new appended attributes with existing appended attributes on the model.
     *
     * @param array<string> $appends
     */
    public function mergeAppends(array $appends): static
    {
        $this->appends = array_values(array_unique(array_merge($this->appends, $appends)));

        return $this;
    }

    /**
     * Return whether the accessor attribute has been appended.
     */
    public function hasAppended(string $attribute): bool
    {
        return in_array($attribute, $this->appends);
    }

    /**
     * Get the mutated attributes for a given instance.
     */
    public function getMutatedAttributes(): array
    {
        if (! isset(static::$mutatorCache[static::class])) {
            static::cacheMutatedAttributes($this);
        }

        return static::$mutatorCache[static::class];
    }

    /**
     * Extract and cache all the mutated attributes of a class.
     */
    public static function cacheMutatedAttributes(object|string $classOrInstance): void
    {
        $reflection = new ReflectionClass($classOrInstance);

        $class = $reflection->getName();

        static::$getAttributeMutatorCache[$class] = (new Collection($attributeMutatorMethods = static::getAttributeMarkedMutatorMethods($classOrInstance)))
            ->mapWithKeys(fn ($match) => [lcfirst(static::$snakeAttributes ? StrCache::snake($match) : $match) => true])
            ->all();

        static::$mutatorCache[$class] = (new Collection(static::getMutatorMethods($class)))
            ->merge($attributeMutatorMethods)
            ->map(fn ($match) => lcfirst(static::$snakeAttributes ? StrCache::snake($match) : $match))
            ->all();
    }

    /**
     * Get all of the attribute mutator methods.
     */
    protected static function getMutatorMethods(mixed $class): array
    {
        preg_match_all('/(?<=^|;)get([^;]+?)Attribute(;|$)/', implode(';', get_class_methods($class)), $matches);

        return $matches[1];
    }

    /**
     * Get all of the "Attribute" return typed attribute mutator methods.
     */
    protected static function getAttributeMarkedMutatorMethods(mixed $class): array
    {
        $instance = is_object($class) ? $class : new $class();

        // @phpstan-ignore method.nonObject (HigherOrderProxy: ->map->name returns Collection, not string)
        return (new Collection((new ReflectionClass($instance))->getMethods()))->filter(function ($method) use ($instance) {
            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType
                && $returnType->getName() === Attribute::class) {
                if (is_callable($method->invoke($instance)->get)) {
                    return true;
                }
            }

            return false;
        })->map->name->values()->all();
    }
}
