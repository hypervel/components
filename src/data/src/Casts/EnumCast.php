<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

use BackedEnum;
use Hypervel\Data\Exceptions\CannotCastEnum;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;
use Throwable;

use function Hypervel\Support\enum_from;

class EnumCast implements Cast, IterableItemCast
{
    /**
     * Create a backed-enum cast.
     *
     * @param null|class-string<BackedEnum> $type
     */
    public function __construct(
        protected ?string $type = null,
    ) {
    }

    /**
     * Cast a property value to its declared backed enum.
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): BackedEnum|Uncastable {
        return $this->castValue(
            $this->type ?? $property->type->type->findAcceptedTypeForBaseType(BackedEnum::class),
            $value,
            $property,
        );
    }

    /**
     * Cast an iterable item to its declared backed enum.
     */
    public function castIterableItem(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): BackedEnum|Uncastable {
        return $this->castValue(
            $this->type ?? $this->iterableEnumType($property),
            $value,
            $property,
        );
    }

    /**
     * Cast one value to a backed enum.
     *
     * @param null|class-string<BackedEnum> $type
     */
    protected function castValue(
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
            return enum_from($type, $value);
        } catch (Throwable) {
            throw CannotCastEnum::create($type, $value, $property);
        }
    }

    /**
     * Find the backed enum declared for iterable items.
     *
     * @return null|class-string<BackedEnum>
     */
    protected function iterableEnumType(DataProperty $property): ?string
    {
        foreach ($property->type->getIterableTypes() as $type) {
            $enum = $type->iterableItemType?->findAcceptedTypeForBaseType(BackedEnum::class);

            if ($enum !== null) {
                return $enum;
            }
        }

        return null;
    }
}
