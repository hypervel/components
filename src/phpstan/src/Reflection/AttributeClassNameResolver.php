<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\InitializerExprContext;
use PHPStan\Reflection\InitializerExprTypeResolver;

use function count;
use function ltrim;

/**
 * Read the class name an attribute argument gives, as instantiating the attribute evaluates it.
 */
#[AutowiredService]
class AttributeClassNameResolver
{
    /**
     * Create an attribute class name resolver.
     */
    public function __construct(
        private readonly InitializerExprTypeResolver $initializerExprTypeResolver,
        private readonly DeclarationDependencyTracker $declarationDependencyTracker,
    ) {
    }

    /**
     * Resolve the class name that an argument of an attribute on the source class gives, such as `Foo::class`
     * or a class constant holding one, for the class whose declarations depend on it.
     */
    public function resolve(?Expr $argument, ClassReflection $sourceReflection, ClassReflection $dependentReflection): ?string
    {
        if ($argument === null) {
            return null;
        }

        // Another class's constant can change the class name without the dependent class changing.
        if ($argument instanceof ClassConstFetch
            && $argument->class instanceof Name
            && ! $argument->class->isSpecialClassName()
            && $argument->name instanceof Identifier
            && $argument->name->toLowerString() !== 'class') {
            $this->declarationDependencyTracker->trackClassDependency($dependentReflection, $argument->class->toString());
        }

        $classNames = $this->initializerExprTypeResolver
            ->getType($argument, InitializerExprContext::fromClassReflection($sourceReflection))
            ->getConstantStrings();

        return count($classNames) === 1 ? ltrim($classNames[0]->getValue(), '\\') : null;
    }
}
