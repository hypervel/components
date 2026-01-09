<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Hyperf\Contract\Castable;
use Hyperf\Contract\CastsAttributes;
use Hyperf\Contract\CastsInboundAttributes;
use Hypervel\Encryption\Contracts\Encrypter;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\Hash;
use Hyperf\Collection\Collection as BaseCollection;
use Hypervel\Support\Str;
use RuntimeException;

trait HasAttributes
{
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
     * The cache of the casters.
     */
    protected static array $casterCache = [];

    /**
     * The cache of the casts.
     */
    protected static array $castsCache = [];

    /**
     * The encrypter instance that is used to encrypt attributes.
     */
    protected static ?Encrypter $encrypter = null;

    /**
     * Set the encrypter instance that will be used to encrypt attributes.
     */
    public static function encryptUsing(?Encrypter $encrypter): void
    {
        static::$encrypter = $encrypter;
    }

    /**
     * Get the current encrypter being used by the model.
     */
    public static function currentEncrypter(): Encrypter
    {
        return static::$encrypter ?? Crypt::getFacadeRoot();
    }

    /**
     * Determine whether a value is an encrypted castable for inbound manipulation.
     */
    protected function isEncryptedCastable(string $key): bool
    {
        return $this->hasCast($key, ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
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
    protected function castAttributeAsEncryptedString(string $key, mixed $value): string
    {
        return static::currentEncrypter()->encrypt($value, false);
    }

    /**
     * Cast the given attribute to a hashed string.
     *
     * @throws RuntimeException
     */
    protected function castAttributeAsHashedString(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! Hash::isHashed($value)) {
            return Hash::make($value);
        }

        if (! Hash::verifyConfiguration($value)) {
            throw new RuntimeException("Could not verify the hashed value's configuration.");
        }

        return $value;
    }

    /**
     * Get the type of cast for a model attribute.
     */
    protected function getCastType(string $key): string
    {
        $castType = $this->getCasts()[$key];

        if ($this->isCustomDateTimeCast($castType)) {
            return 'custom_datetime';
        }

        if ($this->isImmutableCustomDateTimeCast($castType)) {
            return 'immutable_custom_datetime';
        }

        if ($this->isDecimalCast($castType)) {
            return 'decimal';
        }

        return trim(strtolower($castType));
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
     * Cast an attribute to a native PHP type.
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        $castType = $this->getCastType($key);

        if (is_null($value) && in_array($castType, static::$primitiveCastTypes)) {
            return null;
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
                return $this->asDecimal($value, explode(':', $this->getCasts()[$key], 2)[1]);
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
     * Resolve the custom caster class for a given key.
     */
    protected function resolveCasterClass(string $key): CastsAttributes|CastsInboundAttributes
    {
        $castType = $this->getCasts()[$key];
        if ($caster = static::$casterCache[static::class][$castType] ?? null) {
            return $caster;
        }

        $arguments = [];

        $castClass = $castType;
        if (is_string($castClass) && str_contains($castClass, ':')) {
            $segments = explode(':', $castClass, 2);

            $castClass = $segments[0];
            $arguments = explode(',', $segments[1]);
        }

        if (is_subclass_of($castClass, Castable::class)) {
            $castClass = $castClass::castUsing();
        }

        if (is_object($castClass)) {
            return static::$casterCache[static::class][$castType] = $castClass;
        }

        return static::$casterCache[static::class][$castType] = new $castClass(...$arguments);
    }

    /**
     * Get the casts array.
     */
    public function getCasts(): array
    {
        if (! is_null($cache = static::$castsCache[static::class] ?? null)) {
            return $cache;
        }

        if ($this->getIncrementing()) {
            return static::$castsCache[static::class] = array_merge([$this->getKeyName() => $this->getKeyType()], $this->casts, $this->casts());
        }

        return static::$castsCache[static::class] = array_merge($this->casts, $this->casts());
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
     * Return a timestamp as DateTime object with time set to 00:00:00.
     *
     * Uses the Date facade to respect any custom date class configured
     * via Date::use() (e.g., CarbonImmutable).
     */
    protected function asDate(mixed $value): CarbonInterface
    {
        return $this->asDateTime($value)->startOfDay();
    }

    /**
     * Return a timestamp as DateTime object.
     *
     * Uses the Date facade to respect any custom date class configured
     * via Date::use() (e.g., CarbonImmutable).
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
        $date = Date::createFromFormat($format, $value);

        return $date ?: Date::parse($value);
    }
}
