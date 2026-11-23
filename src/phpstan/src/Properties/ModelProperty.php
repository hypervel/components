<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;

class ModelProperty implements PropertyReflection
{
    /**
     * Create a new model property instance.
     *
     * @param bool $canChangeTypeAfterAssignment whether reading the property returns the assigned value unchanged, as for a raw database attribute
     */
    public function __construct(
        private ClassReflection $declaringClass,
        private Type $readableType,
        private Type $writableType,
        private bool $writeable = true,
        private bool $canChangeTypeAfterAssignment = false,
    ) {
    }

    /**
     * Get the class that declares the property.
     */
    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    /**
     * Determine whether the property is static.
     */
    public function isStatic(): bool
    {
        return false;
    }

    /**
     * Determine whether the property is private.
     */
    public function isPrivate(): bool
    {
        return false;
    }

    /**
     * Determine whether the property is public.
     */
    public function isPublic(): bool
    {
        return true;
    }

    /**
     * Determine whether the property is readable.
     */
    public function isReadable(): bool
    {
        return true;
    }

    /**
     * Determine whether the property is writable.
     */
    public function isWritable(): bool
    {
        return $this->writeable;
    }

    /**
     * Get the property's doc comment.
     */
    public function getDocComment(): ?string
    {
        return null;
    }

    /**
     * Get the type the property reads as.
     */
    public function getReadableType(): Type
    {
        return $this->readableType;
    }

    /**
     * Get the type the property accepts.
     */
    public function getWritableType(): Type
    {
        return $this->writableType;
    }

    /**
     * Determine whether an assignment narrows the property's type.
     */
    public function canChangeTypeAfterAssignment(): bool
    {
        // Unlike upstream, a raw attribute narrows: reading it returns the assigned value.
        return $this->canChangeTypeAfterAssignment;
    }

    /**
     * Determine whether the property is deprecated.
     */
    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    /**
     * Get the property's deprecation description.
     */
    public function getDeprecatedDescription(): ?string
    {
        return null;
    }

    /**
     * Determine whether the property is internal.
     */
    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }
}
