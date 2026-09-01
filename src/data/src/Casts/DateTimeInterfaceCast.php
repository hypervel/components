<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

use DateTimeInterface;
use DateTimeZone;
use Hypervel\Data\Exceptions\CannotCastDate;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Support\ClassMetadataCache;
use Hypervel\Support\Facades\Date;
use Throwable;

class DateTimeInterfaceCast implements Cast, IterableItemCast
{
    /**
     * Create a date cast.
     *
     * @param null|string|non-empty-list<string> $format
     * @param null|class-string<DateTimeInterface> $type
     */
    public function __construct(
        protected readonly null|string|array $format = null,
        protected readonly ?string $type = null,
        protected readonly ?string $setTimeZone = null,
        protected readonly ?string $timeZone = null,
    ) {
    }

    /**
     * Cast a property value to its declared date type.
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): DateTimeInterface|Uncastable {
        return $this->castValue(
            $this->type ?? $property->type->type->findAcceptedTypeForBaseType(DateTimeInterface::class),
            $value,
            $context,
        );
    }

    /**
     * Cast an iterable item to its declared date type.
     */
    public function castIterableItem(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): DateTimeInterface|Uncastable {
        return $this->castValue(
            $this->type ?? $this->iterableDateType($property),
            $value,
            $context,
        );
    }

    /**
     * Cast one value to a date.
     *
     * @param null|class-string<DateTimeInterface> $type
     */
    protected function castValue(
        ?string $type,
        mixed $value,
        CreationContext $context,
    ): Uncastable|DateTimeInterface {
        if ($type === null) {
            return Uncastable::create();
        }

        $formats = $this->format === null
            ? $context->dateFormats
            : (is_array($this->format) ? $this->format : [$this->format]);

        if (is_string($value)) {
            $value = preg_replace('/(\.\d{6})\d+/', '$1', $value);
        }

        $sourceTimeZone = $this->timeZone === null ? null : new DateTimeZone($this->timeZone);

        foreach ($formats as $format) {
            try {
                $datetime = $this->createDate(
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

            $targetTimeZone = $this->setTimeZone ?? $context->dateTimezone;

            return $targetTimeZone === null
                ? $datetime
                : $datetime->setTimezone(new DateTimeZone($targetTimeZone));
        }

        throw CannotCastDate::create($formats, $type, $value);
    }

    /**
     * Create a date using the declared concrete type or Hypervel's date factory.
     *
     * @param class-string<DateTimeInterface> $type
     */
    protected function createDate(
        string $type,
        string $format,
        string $value,
        ?DateTimeZone $timeZone,
    ): ?DateTimeInterface {
        $reflection = ClassMetadataCache::reflectClass($type);
        $datetime = $reflection->isInstantiable()
            ? $type::createFromFormat($format, $value, $timeZone)
            : Date::createFromFormat($format, $value, $timeZone);

        if (! $datetime instanceof DateTimeInterface || ! $datetime instanceof $type) {
            return null;
        }

        return $datetime;
    }

    /**
     * Find the date type declared for iterable items.
     *
     * @return null|class-string<DateTimeInterface>
     */
    protected function iterableDateType(DataProperty $property): ?string
    {
        foreach ($property->type->getIterableTypes() as $type) {
            $date = $type->iterableItemType?->findAcceptedTypeForBaseType(DateTimeInterface::class);

            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }
}
