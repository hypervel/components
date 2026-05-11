<?php

declare(strict_types=1);

namespace Hypervel\Wayfinder;

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
        public ?string $key,
        public ?string $default,
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
            return ['string', 'number'];
        }

        $model = Reflector::getParameterClassName($this->bound);

        if (! $model) {
            return ['string', 'number'];
        }

        [$type, $this->key] = BindingResolver::resolveTypeAndKey($model, $this->key);

        if (! $type) {
            return ['string', 'number'];
        }

        return [$this->typeToTypeScript($type)];
    }

    /**
     * Map a database column type to a TypeScript primitive.
     */
    protected function typeToTypeScript(string $type): string
    {
        $mapping = [
            'number' => [
                'int',
                'integer',
                'bigint',
                'int4',
                'int8',
                'serial',
                'bigserial',
                'number',
                'float',
                'double',
                'decimal',
            ],
            'string' => ['string', 'text', 'varchar', 'char', 'json', 'jsonb'],
            'boolean' => ['bool', 'boolean'],
        ];

        foreach ($mapping as $tsType => $types) {
            if (in_array($type, $types)) {
                return $tsType;
            }
        }

        return 'string';
    }

    /**
     * Return the parameter name as a TypeScript-safe identifier.
     */
    public function safeName(): string
    {
        return TypeScript::safeMethod($this->name, 'Param');
    }
}
