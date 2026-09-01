<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Data\Enums\CustomCreationMethodType;
use Hypervel\Data\Support\Creation\CreationContext;
use ReflectionMethod;

class DataMethod
{
    /**
     * Create a new data method definition.
     *
     * @param list<DataParameter> $parameters
     */
    public function __construct(
        public readonly string $name,
        public readonly array $parameters,
        public readonly bool $isStatic,
        public readonly bool $isPublic,
        public readonly CustomCreationMethodType $customCreationMethodType,
        public readonly ?DataType $returnType,
        public readonly ReflectionMethod $reflection,
    ) {
    }

    /**
     * Match creation payloads to this method's parameters.
     */
    public function matchPayloads(CreationContext $context, mixed ...$payloads): ?DataMethodMatch
    {
        $positionalPayloads = [];
        $namedPayloads = [];

        foreach ($payloads as $key => $payload) {
            if (is_int($key)) {
                $positionalPayloads[] = $payload;
            } else {
                $namedPayloads[$key] = $payload;
            }
        }

        $namedArguments = [];
        $positionalArguments = [];
        $consumedNamedPayloads = [];
        $declaredParameterNames = [];
        $positionalIndex = 0;
        $requiresContainerCall = false;
        $hasSkippedParameter = false;

        foreach ($this->parameters as $parameter) {
            if ($parameter->isVariadic) {
                $variadicPayloads = array_slice($positionalPayloads, $positionalIndex);

                foreach ($namedPayloads as $name => $payload) {
                    if (isset($consumedNamedPayloads[$name])) {
                        continue;
                    }

                    if (isset($declaredParameterNames[$name])) {
                        return null;
                    }

                    $variadicPayloads[] = $payload;
                }

                foreach ($variadicPayloads as $payload) {
                    if (! $parameter->type->acceptsValue($payload)) {
                        return null;
                    }
                }

                if ($variadicPayloads === []) {
                    return new DataMethodMatch($namedArguments, $requiresContainerCall);
                }

                if (! $requiresContainerCall && ! $hasSkippedParameter) {
                    return new DataMethodMatch(
                        [...$positionalArguments, ...$variadicPayloads],
                        false,
                    );
                }

                if ($parameter->className !== null) {
                    $namedArguments[$parameter->className] = array_shift($variadicPayloads);
                }

                foreach ($variadicPayloads as $payload) {
                    $namedArguments[] = $payload;
                }

                return new DataMethodMatch($namedArguments, true);
            }

            $declaredParameterNames[$parameter->name] = true;

            if ($parameter->className === CreationContext::class) {
                $namedArguments[$parameter->name] = $context;
                $positionalArguments[] = $context;
                $requiresContainerCall = $requiresContainerCall || $parameter->hasAttributes;

                continue;
            }

            if ($parameter->contextualAttribute !== null) {
                $requiresContainerCall = true;
                $hasSkippedParameter = true;

                continue;
            }

            if (array_key_exists($parameter->name, $namedPayloads)) {
                $payload = $namedPayloads[$parameter->name];

                if (! $parameter->type->acceptsValue($payload)) {
                    return null;
                }

                $consumedNamedPayloads[$parameter->name] = true;
                $namedArguments[$parameter->name] = $payload;
                $positionalArguments[] = $payload;
                $requiresContainerCall = $requiresContainerCall || $parameter->hasAttributes;

                continue;
            }

            if (array_key_exists($positionalIndex, $positionalPayloads)
                && $parameter->type->acceptsValue($positionalPayloads[$positionalIndex])) {
                $payload = $positionalPayloads[$positionalIndex++];
                $namedArguments[$parameter->name] = $payload;
                $positionalArguments[] = $payload;
                $requiresContainerCall = $requiresContainerCall || $parameter->hasAttributes;

                continue;
            }

            if ($parameter->className !== null) {
                $requiresContainerCall = true;
                $hasSkippedParameter = true;

                continue;
            }

            if ($parameter->hasDefaultValue) {
                $requiresContainerCall = $requiresContainerCall || $parameter->hasAttributes;
                $hasSkippedParameter = true;

                continue;
            }

            return null;
        }

        if ($positionalIndex !== count($positionalPayloads)
            || count($consumedNamedPayloads) !== count($namedPayloads)) {
            return null;
        }

        return new DataMethodMatch($namedArguments, $requiresContainerCall);
    }

    /**
     * Determine if the method can return the requested type.
     */
    public function returns(string $type): bool
    {
        return $this->returnType?->acceptsType($type) ?? false;
    }

}
