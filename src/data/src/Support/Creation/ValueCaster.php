<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hypervel\Data\Casts\Uncastable;
use Hypervel\Data\Exceptions\CannotCastDate;
use Hypervel\Data\Exceptions\CannotCastEnum;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Support\Facades\Date;
use Throwable;

class ValueCaster
{
    /**
     * Cast one value to a built-in type.
     *
     * @param 'array'|'bool'|'float'|'int'|'string' $type
     */
    public static function castBuiltin(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => self::castBoolean($value),
            'int' => (int) $value,
            'float' => (float) $value,
            'array' => (array) $value,
            'string' => (string) $value,
        };
    }

    /**
     * Cast one value to a backed enum.
     *
     * @param null|class-string<BackedEnum> $type
     */
    public static function castEnum(
        ?string $type,
        mixed $value,
        DataProperty $property,
    ): BackedEnum|Uncastable {
        if ($type === null) {
            return Uncastable::create();
        }

        if ($value instanceof $type) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        try {
            return $type::from($value);
        } catch (Throwable) {
            throw CannotCastEnum::create($type, $value, $property);
        }
    }

    /**
     * Cast one value to a declared date type.
     *
     * @param null|class-string<DateTimeInterface> $type
     * @param null|non-empty-list<string>|string $format
     */
    public static function castDate(
        ?string $type,
        mixed $value,
        CreationContext $context,
        string|array|null $format = null,
        ?string $setTimeZone = null,
        ?string $timeZone = null,
    ): Uncastable|DateTimeInterface {
        if ($type === null) {
            return Uncastable::create();
        }

        $formats = $format === null
            ? $context->dateFormats
            : (is_array($format) ? $format : [$format]);

        if (is_string($value)) {
            $value = preg_replace('/(\.\d{6})\d+/', '$1', $value);
        }

        $sourceTimeZone = $timeZone === null ? null : new DateTimeZone($timeZone);

        foreach ($formats as $format) {
            try {
                $datetime = self::createDate(
                    $type,
                    $format,
                    $value instanceof DateTimeInterface ? $value->format($format) : (string) $value,
                    $sourceTimeZone,
                );
            } catch (Throwable) {
                $datetime = null;
            }

            if ($datetime === null) {
                continue;
            }

            $targetTimeZone = $setTimeZone ?? $context->dateTimezone;

            return $targetTimeZone === null
                ? $datetime
                : $datetime->setTimezone(new DateTimeZone($targetTimeZone));
        }

        throw CannotCastDate::create($formats, $type, $value);
    }

    /**
     * Cast one value to a boolean.
     */
    protected static function castBoolean(mixed $value): bool
    {
        if (! is_string($value)) {
            return (bool) $value;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => (bool) $value,
        };
    }

    /**
     * Create a date using the declared concrete type or Hypervel's date factory.
     *
     * @param class-string<DateTimeInterface> $type
     */
    protected static function createDate(
        string $type,
        string $format,
        string $value,
        ?DateTimeZone $timeZone,
    ): DateTime|DateTimeImmutable|null {
        $datetime = is_a($type, DateTime::class, true) || is_a($type, DateTimeImmutable::class, true)
            ? $type::createFromFormat($format, $value, $timeZone)
            : Date::createFromFormat($format, $value, $timeZone);

        if ((! $datetime instanceof DateTime && ! $datetime instanceof DateTimeImmutable)
            || ! $datetime instanceof $type
        ) {
            return null;
        }

        return $datetime;
    }
}
