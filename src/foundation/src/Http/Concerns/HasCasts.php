<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Concerns;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException as BrickMathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Hypervel\Contracts\Http\CastsRequestInput;
use Hypervel\Contracts\Http\RequestCastable;
use Hypervel\Foundation\Http\InvalidCastException;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Exceptions\MathException;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Json;
use Hypervel\Validation\ValidationData;
use InvalidArgumentException;
use stdClass;

use function Hypervel\Support\enum_from;

trait HasCasts
{
    /**
     * The built-in request cast types.
     *
     * @var array<'array'|'bool'|'boolean'|'collection'|'date'|'datetime'|'decimal'|'double'|'float'|'int'|'integer'|'json'|'object'|'real'|'string'|'timestamp', true>
     */
    private const array PRIMITIVE_CAST_TYPES = [
        'array' => true,
        'bool' => true,
        'boolean' => true,
        'collection' => true,
        'date' => true,
        'datetime' => true,
        'decimal' => true,
        'double' => true,
        'float' => true,
        'int' => true,
        'integer' => true,
        'json' => true,
        'object' => true,
        'real' => true,
        'string' => true,
        'timestamp' => true,
    ];

    /**
     * Get the casts for the validated request input.
     *
     * @return array<array-key, string>
     */
    protected function casts(): array
    {
        return [];
    }

    /**
     * Cast the validated request input.
     *
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    protected function castValidatedInput(array $input): array
    {
        $casts = $this->casts();

        if ($casts === []) {
            return $input;
        }

        $usesCastPaths = false;

        foreach ($casts as $attribute => $_) {
            $attribute = (string) $attribute;

            if (strpbrk($attribute, '.*') !== false) {
                $usesCastPaths = true;

                break;
            }
        }

        if (! $usesCastPaths) {
            return $this->castExactValidatedInput($input, $casts);
        }

        $usesWildcardPaths = false;
        $usesEncodedKeys = false;

        foreach ($casts as $attribute => $_) {
            $attribute = (string) $attribute;
            $usesWildcardPaths = $usesWildcardPaths || str_contains($attribute, '*');

            // Escaped paths and dotted declarations that collide with literal keys require placeholders.
            if (str_contains($attribute, '\*')
                || str_contains($attribute, '\.')
                || (str_contains($attribute, '.') && array_key_exists($attribute, $input))
            ) {
                $usesEncodedKeys = true;
            }
        }

        // Wildcard expansion embeds data keys in dot paths, so literal dots or asterisks still need placeholders.
        if (! $usesEncodedKeys
            && $usesWildcardPaths
            && $this->inputContainsPathCharacters($input)
        ) {
            $usesEncodedKeys = true;
        }

        $shouldCheckForOverlappingPaths = false;
        $hasMultipleCasts = count($casts) > 1;
        $castRoots = [];

        foreach ($casts as $attribute => $_) {
            $attribute = (string) $attribute;

            // Only declarations sharing a root, or using a wildcard root, can overlap.
            if ($hasMultipleCasts && ! $shouldCheckForOverlappingPaths) {
                $root = $this->getCastPathRoot($attribute);
                $hasWildcardRoot = str_contains(str_replace('\*', '', $root), '*');

                $shouldCheckForOverlappingPaths = $hasWildcardRoot || isset($castRoots[$root]);
                $castRoots[$root] = true;
            }
        }

        $sourceInput = $usesEncodedKeys ? ValidationData::encodeKeys($input) : $input;
        $castedInput = $sourceInput;
        $missing = new stdClass;
        $castPathOwners = [];
        $castDescendantOwners = [];

        foreach ($casts as $attribute => $cast) {
            $attribute = (string) $attribute;
            $castAttribute = $usesEncodedKeys ? ValidationData::encodeAttribute($attribute) : $attribute;
            $keys = str_contains($castAttribute, '*')
                ? ValidationData::expandWildcardKeys($castAttribute, $sourceInput)
                : [$castAttribute];
            $values = [];

            foreach ($keys as $key) {
                $value = Arr::get($sourceInput, $key, $missing);

                if ($value !== $missing) {
                    $values[] = [$key, $value];
                }
            }

            if ($values === []) {
                continue;
            }

            if ($shouldCheckForOverlappingPaths) {
                foreach ($values as [$key]) {
                    $this->ensureCastPathDoesNotOverlap(
                        $key,
                        $attribute,
                        $usesEncodedKeys,
                        $castPathOwners,
                        $castDescendantOwners,
                    );
                }
            }

            $segments = explode(':', $cast, 2);
            $castType = $segments[0];
            $argumentString = $segments[1] ?? null;
            $normalizedCastType = strtolower(trim($castType));
            $primitiveCastType = isset(self::PRIMITIVE_CAST_TYPES[$normalizedCastType])
                ? $normalizedCastType
                : null;

            if ($primitiveCastType === 'decimal') {
                $this->ensureValidDecimalScale($argumentString, $attribute);
            }

            $isEnumCast = $primitiveCastType === null && enum_exists($castType);
            $caster = $primitiveCastType === null && ! $isEnumCast
                ? $this->resolveRequestCaster(
                    $castType,
                    $argumentString === null ? [] : explode(',', $argumentString),
                    $attribute,
                )
                : null;

            foreach ($values as [$key, $value]) {
                // No cast may see internal placeholder keys, including native collection and object conversions.
                if ($usesEncodedKeys && is_array($value)) {
                    $value = ValidationData::decodeKeys($value);
                }

                $value = $caster === null
                    ? $this->castNativeInput($castType, $primitiveCastType, $argumentString, $value)
                    : $caster->cast(
                        $usesEncodedKeys ? ValidationData::replacePlaceholderInString($key) : $key,
                        $value,
                        $input,
                    );

                Arr::set($castedInput, $key, $value);
            }
        }

        return $usesEncodedKeys ? ValidationData::decodeKeys($castedInput) : $castedInput;
    }

    /**
     * Cast validated input with top-level exact declarations.
     *
     * These declarations cannot overlap because PHP array keys are unique and
     * none contains path syntax.
     *
     * @param array<array-key, mixed> $input
     * @param array<array-key, string> $casts
     * @return array<array-key, mixed>
     */
    private function castExactValidatedInput(array $input, array $casts): array
    {
        $castedInput = $input;

        foreach ($casts as $attribute => $cast) {
            $attribute = (string) $attribute;

            if (! array_key_exists($attribute, $input)) {
                continue;
            }

            $segments = explode(':', $cast, 2);
            $castType = $segments[0];
            $argumentString = $segments[1] ?? null;
            $normalizedCastType = strtolower(trim($castType));
            $primitiveCastType = isset(self::PRIMITIVE_CAST_TYPES[$normalizedCastType])
                ? $normalizedCastType
                : null;

            if ($primitiveCastType === 'decimal') {
                $this->ensureValidDecimalScale($argumentString, $attribute);
            }

            $isEnumCast = $primitiveCastType === null && enum_exists($castType);
            $caster = $primitiveCastType === null && ! $isEnumCast
                ? $this->resolveRequestCaster(
                    $castType,
                    $argumentString === null ? [] : explode(',', $argumentString),
                    $attribute,
                )
                : null;
            $value = $input[$attribute];

            $castedInput[$attribute] = $caster === null
                ? $this->castNativeInput($castType, $primitiveCastType, $argumentString, $value)
                : $caster->cast($attribute, $value, $input);
        }

        return $castedInput;
    }

    /**
     * Determine whether input keys contain dot-path characters.
     *
     * @param array<array-key, mixed> $input
     */
    private function inputContainsPathCharacters(array $input): bool
    {
        foreach ($input as $key => $value) {
            if ((is_string($key) && strpbrk($key, '.*') !== false)
                || (is_array($value) && $this->inputContainsPathCharacters($value))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the root segment from a cast path.
     */
    private function getCastPathRoot(string $attribute): string
    {
        $offset = 0;

        while (($position = strpos($attribute, '.', $offset)) !== false) {
            if ($position === 0 || $attribute[$position - 1] !== '\\') {
                return substr($attribute, 0, $position);
            }

            $offset = $position + 1;
        }

        return $attribute;
    }

    /**
     * Ensure the cast path does not overlap another declaration.
     *
     * @param array<string, string> $castPathOwners
     * @param array<string, string> $castDescendantOwners
     */
    private function ensureCastPathDoesNotOverlap(
        string $key,
        string $attribute,
        bool $usesEncodedKeys,
        array &$castPathOwners,
        array &$castDescendantOwners,
    ): void {
        $overlappingAttribute = $castPathOwners[$key] ?? $castDescendantOwners[$key] ?? null;
        $ancestorPaths = [];
        $position = strpos($key, '.');

        while ($overlappingAttribute === null && $position !== false) {
            $ancestorPath = substr($key, 0, $position);
            $ancestorPaths[] = $ancestorPath;
            $overlappingAttribute = $castPathOwners[$ancestorPath] ?? null;
            $position = strpos($key, '.', $position + 1);
        }

        if ($overlappingAttribute !== null) {
            $concreteInput = $usesEncodedKeys
                ? ValidationData::replacePlaceholderInString($key)
                : $key;

            throw new InvalidArgumentException(
                "Cast declarations [{$overlappingAttribute}] and [{$attribute}] overlap at input [{$concreteInput}]."
            );
        }

        $castPathOwners[$key] = $attribute;

        foreach ($ancestorPaths as $ancestorPath) {
            $castDescendantOwners[$ancestorPath] ??= $attribute;
        }
    }

    /**
     * Cast one input through a primitive or enum declaration.
     */
    protected function castNativeInput(
        string $castType,
        ?string $primitiveCastType,
        ?string $argumentString,
        mixed $value,
    ): mixed {
        if ($value === null) {
            return null;
        }

        if ($primitiveCastType !== null) {
            return $this->castPrimitiveInput($primitiveCastType, $argumentString, $value);
        }

        if ($value instanceof $castType) {
            return $value;
        }

        return is_subclass_of($castType, BackedEnum::class)
            ? enum_from($castType, $value)
            : constant($castType . '::' . $value);
    }

    /**
     * Cast one input through a primitive declaration.
     *
     * @param 'array'|'bool'|'boolean'|'collection'|'date'|'datetime'|'decimal'|'double'|'float'|'int'|'integer'|'json'|'object'|'real'|'string'|'timestamp' $castType
     */
    protected function castPrimitiveInput(string $castType, ?string $argumentString, mixed $value): mixed
    {
        return match ($castType) {
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => $this->fromFloat($value),
            'decimal' => $this->asDecimal($value, (int) $argumentString),
            'string' => (string) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'array', 'json' => is_array($value) ? $value : Json::decode($value),
            'collection' => $value instanceof Collection
                ? $value
                : new Collection(is_array($value) ? $value : Json::decode($value)),
            'object' => $this->asObject($value),
            'date' => $this->asDate($value, $argumentString),
            'datetime' => $this->asDateTime($value, $argumentString),
            'timestamp' => $this->asDateTime($value)->getTimestamp(),
        };
    }

    /**
     * Ensure the decimal cast has a valid scale.
     */
    protected function ensureValidDecimalScale(?string $scale, string $input): void
    {
        if (filter_var($scale, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new InvalidArgumentException(
                "The decimal cast for input [{$input}] requires a non-negative integer scale."
            );
        }
    }

    /**
     * Resolve a custom request caster.
     *
     * @param string[] $arguments
     */
    protected function resolveRequestCaster(string $castType, array $arguments, string $input): CastsRequestInput
    {
        if (! is_subclass_of($castType, CastsRequestInput::class)
            && ! is_subclass_of($castType, RequestCastable::class)
        ) {
            throw new InvalidCastException($this, $input, $castType);
        }

        $caster = is_subclass_of($castType, RequestCastable::class)
            ? $castType::castRequestUsing($arguments)
            : $castType;

        return is_string($caster) ? new $caster(...$arguments) : $caster;
    }

    /**
     * Decode the given float.
     */
    protected function fromFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        return match ((string) $value) {
            'Infinity' => INF,
            '-Infinity' => -INF,
            'NaN' => NAN,
            default => (float) $value,
        };
    }

    /**
     * Return a decimal as a string.
     */
    protected function asDecimal(mixed $value, int $decimals): string
    {
        try {
            return (string) BigDecimal::of((string) $value)->toScale($decimals, RoundingMode::HalfUp);
        } catch (BrickMathException $exception) {
            throw new MathException('Unable to cast value to a decimal.', previous: $exception);
        }
    }

    /**
     * Decode the given JSON value as an object.
     */
    protected function asObject(mixed $value): object
    {
        if (is_object($value)) {
            return $value;
        }

        return Json::decode(
            is_array($value) ? Json::encode($value) : $value,
            assoc: false,
        );
    }

    /**
     * Return a date with its time set to the start of the day.
     */
    protected function asDate(mixed $value, ?string $format = null): CarbonInterface
    {
        return $this->asDateTime($value, $format)->startOfDay();
    }

    /**
     * Return a date-time instance.
     */
    protected function asDateTime(mixed $value, ?string $format = null): CarbonInterface
    {
        if ($value instanceof DateTimeInterface) {
            return Date::instance($value);
        }

        if ($format !== null) {
            return Date::createFromFormat($format, $value);
        }

        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return Date::createFromTimestamp($value, date_default_timezone_get());
        }

        return Date::parse($value);
    }
}
