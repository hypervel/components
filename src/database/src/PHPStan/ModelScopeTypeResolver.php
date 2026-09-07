<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;

/**
 * Bind model-relative types resolved for an Eloquent receiver.
 */
class ModelScopeTypeResolver
{
    /**
     * Bind late-static types to the concrete model receiving the scope.
     *
     * PHPStan binds inherited method types to their declaring class until they are explicitly rebased.
     */
    public static function bindToModel(Type $type, ClassReflection $modelClass): Type
    {
        return TypeTraverser::map(
            $type,
            static fn (Type $nestedType, callable $traverse): Type => $nestedType instanceof StaticType
                ? $nestedType->changeBaseClass($modelClass)->getStaticObjectType()
                : $traverse($nestedType),
        );
    }
}
