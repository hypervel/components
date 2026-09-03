<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Factories;

use Hypervel\Data\Enums\CustomCreationMethodType;
use Hypervel\Data\Exceptions\InvalidDataDeclaration;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataMethod;
use Hypervel\Data\Support\DataParameter;
use Hypervel\Data\Support\DataType;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

class DataMethodFactory
{
    /**
     * Create a new data method factory.
     */
    public function __construct(
        protected readonly DataParameterFactory $parameterFactory,
        protected readonly DataTypeFactory $typeFactory,
    ) {
    }

    /**
     * Build an immutable method definition.
     *
     * @param ReflectionClass<object> $reflectionClass
     */
    public function build(
        ReflectionMethod $reflectionMethod,
        ReflectionClass $reflectionClass,
    ): DataMethod {
        $parameters = array_map(
            fn (ReflectionParameter $parameter): DataParameter => $this->parameterFactory->build($parameter, $reflectionClass),
            $reflectionMethod->getParameters(),
        );

        $returnType = $reflectionMethod->hasReturnType()
            ? $this->typeFactory->build($reflectionMethod->getReturnType(), $reflectionClass, $reflectionMethod)
            : null;
        $customCreationMethodType = $this->resolveCustomCreationMethodType($reflectionMethod, $returnType);

        if ($reflectionMethod->isPublic()
            && $reflectionMethod->isStatic()
            && $customCreationMethodType !== CustomCreationMethodType::None) {
            foreach ($parameters as $parameter) {
                if ($parameter->isVariadic && $parameter->className === CreationContext::class) {
                    throw InvalidDataDeclaration::variadicCreationContext(
                        $reflectionClass->name,
                        $reflectionMethod->name,
                        $parameter->name,
                    );
                }
            }
        }

        return new DataMethod(
            name: $reflectionMethod->name,
            parameters: $parameters,
            isStatic: $reflectionMethod->isStatic(),
            isPublic: $reflectionMethod->isPublic(),
            customCreationMethodType: $customCreationMethodType,
            returnType: $returnType,
            reflection: $reflectionMethod,
        );
    }

    /**
     * Resolve the method's named creation role.
     */
    protected function resolveCustomCreationMethodType(
        ReflectionMethod $method,
        ?DataType $returnType,
    ): CustomCreationMethodType {
        if (str_starts_with($method->name, 'from')) {
            return CustomCreationMethodType::Object;
        }

        if (str_starts_with($method->name, 'collect') && $returnType !== null) {
            return CustomCreationMethodType::Collection;
        }

        return CustomCreationMethodType::None;
    }
}
