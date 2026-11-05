<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;

/**
 * Describe a read-only property that a proxy reads from its target, typed as what the proxy
 * returns, with the target property's deprecation.
 */
final class ProxiedPropertyReflection implements PropertyReflection
{
    /**
     * The type the proxy returns.
     */
    private readonly Type $type;

    /**
     * Create a proxied property reflection.
     *
     * @param null|PropertyReflection $targetProperty the target's property, or null for an array offset
     */
    public function __construct(
        private readonly ClassReflection $declaringClass,
        Type $type,
        private readonly ?PropertyReflection $targetProperty,
    ) {
        $this->type = ProxiedMethodReflection::resolveStaticTypes($type);
    }

    /**
     * Return the proxy class.
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
     * Return the property docblock.
     */
    public function getDocComment(): ?string
    {
        return null;
    }

    /**
     * Get the type read from the property.
     */
    public function getReadableType(): Type
    {
        return $this->type;
    }

    /**
     * Get the type written to the property.
     */
    public function getWritableType(): Type
    {
        return $this->type;
    }

    /**
     * Determine whether an assignment can change the property's type.
     */
    public function canChangeTypeAfterAssignment(): bool
    {
        return false;
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
        return false;
    }

    /**
     * Determine whether the target property is deprecated.
     */
    public function isDeprecated(): TrinaryLogic
    {
        return $this->targetProperty?->isDeprecated() ?? TrinaryLogic::createNo();
    }

    /**
     * Get the target property's deprecation description.
     */
    public function getDeprecatedDescription(): ?string
    {
        return $this->targetProperty?->getDeprecatedDescription();
    }

    /**
     * Determine whether the target property is internal.
     */
    public function isInternal(): TrinaryLogic
    {
        return $this->targetProperty?->isInternal() ?? TrinaryLogic::createNo();
    }
}
