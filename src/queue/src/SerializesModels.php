<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Queue\Attributes\WithoutRelations;
use Hypervel\Support\ClassMetadataCache;
use ReflectionProperty;

trait SerializesModels
{
    use SerializesAndRestoresModelIdentifiers;

    /**
     * Prepare the instance values for serialization.
     */
    public function __serialize(): array
    {
        $values = [];

        // Unlike Laravel, a parent's private properties are serialized too, keyed by their declaring
        // class as PHP keys them, so same-named private properties at different levels stay separate.
        [$properties, $classLevelWithoutRelations] = [
            ClassMetadataCache::allProperties($this),
            ClassMetadataCache::hasClassAttribute($this, WithoutRelations::class, ascend: true),
        ];

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (! $property->isInitialized($this)) {
                continue;
            }

            if ($property->isVirtual()) {
                continue;
            }

            $value = $this->getPropertyValue($property);

            if ($property->hasDefaultValue() && $value === $property->getDefaultValue()) {
                continue;
            }

            $name = $property->getName();

            if ($property->isPrivate()) {
                $name = "\0{$property->class}\0{$name}";
            } elseif ($property->isProtected()) {
                $name = "\0*\0{$name}";
            }

            $values[$name] = $this->getSerializedPropertyValue(
                $value,
                ! $classLevelWithoutRelations
                    && ! ClassMetadataCache::hasPropertyAttribute($property, WithoutRelations::class)
            );
        }

        return $values;
    }

    /**
     * Restore the model after serialization.
     */
    public function __unserialize(array $values): void
    {
        $properties = ClassMetadataCache::allProperties($this);

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();

            if ($property->isPrivate()) {
                $name = "\0{$property->class}\0{$name}";
            } elseif ($property->isProtected()) {
                $name = "\0*\0{$name}";
            }

            if (! array_key_exists($name, $values)) {
                continue;
            }

            // Unlike Laravel, the stored value is restored without running a set hook, as native
            // unserialization does, since the hook already ran when the value was stored.
            $property->setRawValue(
                $this,
                $this->getRestoredPropertyValue($values[$name])
            );
        }
    }

    /**
     * Get the property value for the given property.
     *
     * Unlike Laravel, this reads the stored value without running a get hook, as native
     * serialization does.
     */
    protected function getPropertyValue(ReflectionProperty $property): mixed
    {
        return $property->getRawValue($this);
    }
}
