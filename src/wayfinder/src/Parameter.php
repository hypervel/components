<?php

declare(strict_types=1);

namespace Hypervel\Wayfinder;

use Hypervel\Support\Js;
use Hypervel\Support\Reflector;
use ReflectionParameter;

class Parameter
{
    public string $placeholder;

    public string $types;

    /**
     * Create a new Parameter instance.
     */
    public function __construct(
        public string $name,
        public bool $optional,
        public bool $routeOptional,
        public ?string $key,
        public string|int|float|bool|null $default,
        public ?ReflectionParameter $bound = null,
    ) {
        $this->placeholder = $optional ? "{{$name}?}" : "{{$name}}";

        $this->types = implode(' | ', $this->resolveTypes());
    }

    /**
     * Resolve the TypeScript types for this parameter.
     *
     * @return string[]
     */
    protected function resolveTypes(): array
    {
        if (! $this->bound) {
            return ['string', 'number', 'boolean'];
        }

        $model = Reflector::getParameterClassName($this->bound);

        if (! $model) {
            return ['string', 'number', 'boolean'];
        }

        [$types, $this->key] = BindingResolver::resolveTypesAndKey($model, $this->key);

        return $types === [] ? ['string', 'number', 'boolean'] : $types;
    }

    /**
     * Return the parameter name as a TypeScript-safe identifier.
     */
    public function safeName(): string
    {
        return TypeScript::safeMethod($this->name, 'Param');
    }

    /**
     * Return the binding key as a TypeScript object member.
     */
    public function keyName(): ?string
    {
        return $this->key === null ? null : TypeScript::quoteIfNeeded($this->key);
    }

    /**
     * Return the binding key as a TypeScript bracket accessor.
     */
    public function keyAccessor(): ?string
    {
        return $this->key === null ? null : Js::from($this->key)->toHtml();
    }
}
