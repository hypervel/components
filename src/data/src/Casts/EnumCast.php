<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

use BackedEnum;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\Creation\ValueCaster;
use Hypervel\Data\Support\DataProperty;

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
        return ValueCaster::castEnum($type, $value, $property);
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
