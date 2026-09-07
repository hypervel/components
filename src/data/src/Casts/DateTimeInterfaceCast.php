<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

use DateTimeInterface;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\Creation\ValueCaster;
use Hypervel\Data\Support\DataProperty;

class DateTimeInterfaceCast implements Cast, IterableItemCast
{
    /**
     * Create a date cast.
     *
     * @param null|non-empty-list<string>|string $format
     * @param null|class-string<DateTimeInterface> $type
     */
    public function __construct(
        protected readonly string|array|null $format = null,
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
        return ValueCaster::castDate(
            type: $type,
            value: $value,
            context: $context,
            format: $this->format,
            setTimeZone: $this->setTimeZone,
            timeZone: $this->timeZone,
        );
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
