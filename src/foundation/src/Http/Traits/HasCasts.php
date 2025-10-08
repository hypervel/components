<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Traits;

use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Hyperf\Database\Exception\InvalidCastException;
use Hyperf\Database\Model\EnumCollector;
use Hypervel\Foundation\Http\Contracts\Castable;
use Hypervel\Foundation\Http\Contracts\CastInputs;
use Hypervel\Support\Collection;
use Hypervel\Support\DataObject;
use RuntimeException;
use UnitEnum;

trait HasCasts
{
    /**
     * The inputs that should be cast.
     */
    protected array $casts = [];

    /**
     * The inputs that have been cast using custom classes.
     */
    protected array $classCastCache = [];

    /**
     * The date format used by date and datetime casts.
     */
    protected ?string $dateFormat = 'Y-m-d H:i:s.u';

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
        'float',
        'int',
        'integer',
        'json',
        'object',
        'real',
        'string',
        'timestamp',
    ];

    /**
     * Get casted inputs from the request.
     *
     * @param bool $validate Whether to use validated data or raw input
     */
    public function casted(array|string|null $key = null, bool $validate = true): mixed
    {
        $data = $validate ? $this->validated() : $this->all();

        if (is_null($key)) {
            return $this->castInputs($data, $validate);
        }

        if (is_array($key)) {
            $results = [];
            foreach ($key as $k) {
                $results[$k] = $this->castInputValue($k, $data[$k] ?? null, $validate);
            }

            return $results;
        }

        return $this->castInputValue($key, $data[$key] ?? null, $validate);
    }

    /**
     * Cast all inputs based on the casts definition.
     */
    protected function castInputs(array $inputs, bool $validate = true): array
    {
        $casted = [];

        foreach ($inputs as $key => $value) {
            $casted[$key] = $this->castInputValue($key, $value, $validate);
        }

        return $casted;
    }

    /**
     * Cast a single input value.
     */
    protected function castInputValue(string $key, mixed $value, bool $validate = true): mixed
    {
        if (! $this->hasCast($key)) {
            return $value;
        }

        return $this->castInput($key, $value, $validate);
    }

    /**
     * Cast an input to a native PHP type.
     */
    protected function castInput(string $key, mixed $value, bool $validate = true): mixed
    {
        $castType = $this->getCastType($key);

        if (is_null($value) && in_array($castType, static::$primitiveCastTypes)) {
            return null;
        }

        // Handle primitive casts
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
                return $this->fromJson($value);
            case 'collection':
                return new Collection($this->fromJson($value));
            case 'date':
                return $this->asDate($value);
            case 'datetime':
            case 'custom_datetime':
                return $this->asDateTime($value);
            case 'timestamp':
                return $this->asTimestamp($value);
        }

        // Handle Enum casts
        if ($this->isEnumCastable($key)) {
            return $this->getEnumCastableInputValue($key, $value);
        }

        // Handle DataObject casts
        if ($this->isDataObjectCastable($key)) {
            return $this->getDataObjectCastableInputValue($key, $value);
        }

        // Handle custom class casts
        if ($this->isClassCastable($key)) {
            return $this->getClassCastableInputValue($key, $value, $validate);
        }

        return $value;
    }

    /**
     * Cast the given input using a custom cast class.
     */
    protected function getClassCastableInputValue(string $key, mixed $value, bool $validate = true): mixed
    {
        $cacheKey = ($validate ? 'validated:' : 'all:') . $key;

        if (isset($this->classCastCache[$cacheKey])) {
            return $this->classCastCache[$cacheKey];
        }

        $caster = $this->resolveCasterClass($key);
        $inputs = $validate ? $this->validated() : $this->all();

        $value = $caster->get($key, $value, $inputs);

        if (is_object($value)) {
            $this->classCastCache[$cacheKey] = $value;
        }

        return $value;
    }

    /**
     * Cast the given input to an enum.
     */
    protected function getEnumCastableInputValue(string $key, mixed $value): mixed
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
     * Cast the given input to a DataObject.
     */
    public function getDataObjectCastableInputValue(string $key, mixed $value): mixed
    {
        if (is_null($value)) {
            return null;
        }

        $castType = $this->getCasts()[$key];

        if (! is_array($value)) {
            throw new InvalidCastException(static::class, $key, $castType);
        }

        // Check if the class has make static method (provided by DataObject)
        if (! method_exists($castType, 'make')) {
            throw new RuntimeException(
                "Class {$castType} must implement static make(array \$data) method"
            );
        }

        return $castType::make($value);
    }

    /**
     * Get an enum case instance from a given class and value.
     */
    protected function getEnumCaseFromValue(string $enumClass, int|string $value): BackedEnum|UnitEnum
    {
        return EnumCollector::getEnumCaseFromValue($enumClass, $value);
    }

    /**
     * Determine whether an input should be cast to a native type.
     */
    public function hasCast(string $key, mixed $types = null): bool
    {
        if (array_key_exists($key, $this->getCasts())) {
            return ! $types || in_array($this->getCastType($key), (array) $types, true);
        }

        return false;
    }

    /**
     * Get the casts array.
     */
    public function getCasts(): array
    {
        return array_merge($this->casts, $this->casts());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    /**
     * Get the type of cast for an input.
     */
    protected function getCastType(string $key): string
    {
        return trim(strtolower($this->getCasts()[$key]));
    }

    /**
     * Determine if the given key is cast using a custom class.
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

        throw new InvalidCastException(static::class, $key, $castType);
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

        return enum_exists($castType);
    }

    /**
     * Determine if the given key is cast using a DataObject.
     */
    public function isDataObjectCastable(string $key): bool
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $casts[$key];

        if (in_array($castType, static::$primitiveCastTypes)) {
            return false;
        }

        return is_subclass_of($castType, DataObject::class);
    }

    /**
     * Resolve the custom caster class for a given key.
     */
    protected function resolveCasterClass(string $key): CastInputs
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
        return ! str_contains($class, ':') ? $class : explode(':', $class, 2)[0];
    }

    /**
     * Decode the given JSON back into an array or object.
     */
    public function fromJson(string $value, bool $asObject = false)
    {
        return json_decode($value, ! $asObject);
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
     * Convert a DateTime to a storable string.
     *
     * @return null|string
     */
    public function fromDateTime(mixed $value): mixed
    {
        return empty($value) ? $value : $this->asDateTime($value)->format(
            $this->getDateFormat()
        );
    }

    /**
     * Get the format for database stored dates.
     */
    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    /**
     * Encode the given value as JSON.
     */
    protected function asJson(mixed $value): false|string
    {
        return json_encode($value);
    }

    /**
     * Return a decimal as string.
     *
     * @param float $value
     * @param int $decimals
     */
    protected function asDecimal(mixed $value, mixed $decimals): string
    {
        return number_format((float) $value, (int) $decimals, '.', '');
    }

    /**
     * Return a timestamp as DateTime object with time set to 00:00:00.
     */
    protected function asDate(mixed $value): CarbonInterface
    {
        return $this->asDateTime($value)->startOfDay();
    }

    /**
     * Return a timestamp as DateTime object.
     */
    protected function asDateTime(mixed $value): CarbonInterface
    {
        // If this value is already a Carbon instance, we shall just return it as is.
        // This prevents us having to re-instantiate a Carbon instance when we know
        // it already is one, which wouldn't be fulfilled by the DateTime check.
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof DateTimeInterface) {
            return Carbon::parse(
                $value->format('Y-m-d H:i:s.u'),
                $value->getTimezone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp($value);
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format. Again, this provides for simple date
        // fields on the database, while still supporting Carbonized conversion.
        if ($this->isStandardDateFormat($value)) {
            return Carbon::instance(Carbon::createFromFormat('Y-m-d', $value)->startOfDay());
        }

        $format = $this->getDateFormat();

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        if (Carbon::hasFormat($value, $format)) {
            return Carbon::createFromFormat($format, $value);
        }

        return Carbon::parse($value);
    }

    /**
     * Determine if the given value is a standard date format.
     */
    protected function isStandardDateFormat(mixed $value): bool|int
    {
        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', (string) $value);
    }

    /**
     * Return a timestamp as unix timestamp.
     */
    protected function asTimestamp(mixed $value): false|int
    {
        return $this->asDateTime($value)->getTimestamp();
    }
}
