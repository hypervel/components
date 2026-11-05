<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\DynamicMethodThrowTypeExtension;
use PHPStan\Type\DynamicStaticMethodThrowTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_key_exists;

/**
 * Add a registered macro's exception to a method its @method tag types.
 *
 * The tag owns the signature (see MacroMethodsClassReflectionExtension) but cannot declare
 * exceptions, such as the ValidationException that Request::validate() throws. Only methods
 * whose magic handler already declares an exception are claimed, because a supported
 * extension replaces the method's exception, and returning none would drop it.
 */
#[AutowiredService]
class MacroThrowTypeExtension implements DynamicMethodThrowTypeExtension, DynamicStaticMethodThrowTypeExtension
{
    /**
     * Create a macro throw type extension.
     */
    public function __construct(
        private readonly MacroMethodsClassReflectionExtension $macros,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Determine whether the method is a tag-typed macro with a known exception.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $this->isTaggedMacroWithThrowType($methodReflection);
    }

    /**
     * Get the exception of the macro registered on the call's receiver.
     */
    public function getThrowTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        return $this->resolveThrowType(
            $methodReflection,
            $scope->getType($methodCall->var)->getObjectClassReflections(),
        );
    }

    /**
     * Determine whether the static method is a tag-typed macro with a known exception.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $this->isTaggedMacroWithThrowType($methodReflection);
    }

    /**
     * Get the exception of the macro registered on the called class, or on a facade's root.
     */
    public function getThrowTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, Scope $scope): ?Type
    {
        if (! $methodCall->class instanceof Name) {
            return $this->resolveThrowType(
                $methodReflection,
                $scope->getType($methodCall->class)->getObjectClassReflections(),
            );
        }

        $className = $scope->resolveName($methodCall->class);

        return $this->resolveThrowType(
            $methodReflection,
            $this->reflectionProvider->hasClass($className) ? [$this->reflectionProvider->getClass($className)] : [],
        );
    }

    /**
     * Determine whether an @method tag types the method, its class registers a macro of that name
     * with a known exception, and its magic handler declares an exception the extension may replace.
     */
    private function isTaggedMacroWithThrowType(MethodReflection $methodReflection): bool
    {
        $classReflection = $methodReflection->getDeclaringClass();
        $methodName = $methodReflection->getName();

        return $methodReflection->getThrowType() !== null
            && ! $classReflection->hasNativeMethod($methodName)
            && array_key_exists($methodName, $classReflection->getMethodTags())
            && $this->macros->getThrowType($classReflection, $methodName) !== null;
    }

    /**
     * Resolve the exception of the macros registered on the given hosts.
     *
     * A host without a macro exception keeps the method's own exception.
     *
     * @param list<ClassReflection> $hosts
     */
    private function resolveThrowType(MethodReflection $methodReflection, array $hosts): Type
    {
        // isTaggedMacroWithThrowType() requires the method's own exception.
        /** @var Type $throwType */
        $throwType = $methodReflection->getThrowType();

        $types = [];

        foreach ($hosts as $host) {
            $types[] = $this->macros->getThrowType($host, $methodReflection->getName()) ?? $throwType;
        }

        return $types === [] ? $throwType : TypeCombinator::union(...$types);
    }
}
