<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\PHPStan\Reflection\DynamicMethodReflection;
use Hypervel\PHPStan\Reflection\DynamicWhereParameterReflection;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\StaticType;

use function array_fill;
use function count;
use function str_starts_with;

#[AutowiredService]
class QueryBuilderMethodsExtension implements MethodsClassReflectionExtension
{
    /**
     * Create a query builder methods extension.
     */
    public function __construct(
        private MacroMethodsClassReflectionExtension $macros,
        private BuilderHelper $builderHelper,
    ) {
    }

    /**
     * Determine whether the method is a dynamic where on a query builder.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        // Query\Builder::__call() runs a registered macro, else turns a where*() call into where clauses.
        // A subclass's @method tag declares its own handling.
        return str_starts_with($methodName, 'where')
            && $classReflection->is(QueryBuilder::class)
            && ! ReflectionHelper::hasMethodTag($classReflection, $methodName)
            && ! $this->macros->hasMacro($classReflection, $methodName);
    }

    /**
     * Get the reflection of the dynamic where.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        // One value per column, as in whereNameAndEmail($name, $email).
        return new DynamicMethodReflection($classReflection, $methodName, [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                null,
                array_fill(0, count($this->builderHelper->dynamicWhereColumns($methodName)), new DynamicWhereParameterReflection),
                false,
                new StaticType($classReflection),
            ),
        ]);
    }
}
