<?php

declare(strict_types=1);

namespace Hypervel\Container;

use LogicException;
use ReflectionAttribute;
use ReflectionParameter;
use UnitEnum;

/**
 * Cached dependency parameter metadata.
 *
 * Stores the result of analyzing one constructor, method, or function
 * parameter. Recipes are reused for the worker lifetime, avoiding repeated
 * reflection on every container build or method call.
 *
 * @internal
 */
readonly class ParameterRecipe
{
    /**
     * @param ReflectionAttribute[] $attributes
     */
    public function __construct(
        public string $name,
        public string $declaringClassName,
        public ?string $className,
        public bool $hasType,
        public bool $hasDefault,
        public mixed $default,
        public bool $refreshDefault,
        public bool $isVariadic,
        public bool $isOptional,
        public bool $allowsNull,
        public array $attributes,
        public ?ReflectionAttribute $contextualAttribute,
        public ?ReflectionParameter $reflectionParameter,
        public string $reflectionString = '',
    ) {
    }

    /**
     * Create a cached recipe from a reflected parameter.
     */
    public static function fromParameter(
        ReflectionParameter $parameter,
        string $declaringClassName,
        bool $includeReflectionString = false,
    ): self {
        $hasDefault = $parameter->isDefaultValueAvailable();
        $default = $hasDefault ? $parameter->getDefaultValue() : null;
        $refreshDefault = $hasDefault && self::containsRefreshableObject($default);
        $contextualAttribute = Util::getContextualAttributeFromDependency($parameter);

        return new self(
            name: $parameter->getName(),
            declaringClassName: $parameter->getDeclaringClass()?->getName() ?? $declaringClassName,
            className: Util::getParameterClassName($parameter),
            hasType: $parameter->hasType(),
            hasDefault: $hasDefault,
            default: $refreshDefault ? null : $default,
            refreshDefault: $refreshDefault,
            isVariadic: $parameter->isVariadic(),
            isOptional: $parameter->isOptional(),
            allowsNull: $parameter->allowsNull(),
            attributes: $parameter->getAttributes(),
            contextualAttribute: $contextualAttribute,
            reflectionParameter: $refreshDefault || $contextualAttribute !== null ? $parameter : null,
            reflectionString: $includeReflectionString ? (string) $parameter : '',
        );
    }

    /**
     * Get the parameter's default value.
     */
    public function getDefaultValue(): mixed
    {
        return $this->refreshDefault
            ? $this->getReflectionParameter()->getDefaultValue()
            : $this->default;
    }

    /**
     * Get the reflected parameter retained for dynamic resolution.
     */
    public function getReflectionParameter(): ReflectionParameter
    {
        return $this->reflectionParameter
            ?? throw new LogicException('No reflected parameter is retained for this recipe.');
    }

    /**
     * Determine whether a default contains a non-enum object.
     */
    protected static function containsRefreshableObject(mixed $value): bool
    {
        if ($value instanceof UnitEnum) {
            return false;
        }

        if (is_object($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (self::containsRefreshableObject($item)) {
                return true;
            }
        }

        return false;
    }
}
