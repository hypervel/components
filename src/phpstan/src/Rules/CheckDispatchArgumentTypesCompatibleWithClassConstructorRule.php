<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Events\Dispatchable as EventDispatchable;
use Hypervel\PHPStan\Reflection\ProxiedParameterReflection;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name\FullyQualified;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Node\Expr\TypeExpr;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Type\ClosureType;

use function array_all;
use function array_filter;
use function array_find;
use function array_values;
use function in_array;
use function strcasecmp;

/** @implements Rule<StaticCall> */
class CheckDispatchArgumentTypesCompatibleWithClassConstructorRule implements Rule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private string $dispatchableClass,
    ) {
    }

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * Check the dispatch arguments against the dispatched class's constructor.
     *
     * @param NodeCallbackInvoker&Scope $scope
     * @return array{}
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier) {
            return [];
        }

        // Unlike upstream, the method name matches in any case, as PHP calls it.
        $calledName = $node->name->name;
        $methodName = array_find($this->getAvailableMethods(), static fn (string $method): bool => strcasecmp($method, $calledName) === 0);

        if ($methodName === null) {
            return [];
        }

        if (! $node->class instanceof Node\Name) {
            return [];
        }

        $className = $scope->resolveName($node->class);

        if (! $this->reflectionProvider->hasClass($className)) {
            return [];
        }

        $calledClass = $this->reflectionProvider->getClass($className);
        $dispatchableFile = $this->reflectionProvider->getClass($this->dispatchableClass)->getFileName();

        // Unlike upstream, a class's own dispatch method decides its arguments itself.
        if (
            ! $calledClass->hasNativeMethod($methodName)
            || $calledClass->getNativeReflection()->getMethod($methodName)->getFileName() !== $dispatchableFile
        ) {
            return [];
        }

        $jobClassReflection = $calledClass;

        // Unlike upstream, a self, static or parent call forwards late static binding, so the method
        // constructs the calling class, or a subclass with another constructor unless the calling class
        // is final. PHPStan's new static check also follows final and consistent constructors; this does not.
        if ($node->class->isSpecialClassName()) {
            $jobClassReflection = $scope->getClassReflection();

            if ($jobClassReflection === null || ! $jobClassReflection->isFinal()) {
                return [];
            }
        }

        // Unlike upstream, which checks only a boolean condition, the constructor also runs before a
        // closure condition. The condition is the first positional argument, or the named one.
        if (in_array($methodName, ['dispatchIf', 'dispatchUnless'], true)) {
            $args = $node->getArgs();

            if ($args === [] || $args[0]->unpack) {
                return [];
            }

            $conditionArg = $args[0]->name === null ? $args[0] : null;

            foreach ($args as $arg) {
                if ($arg->name?->name === 'boolean') {
                    $conditionArg = $arg;
                }
            }

            // PHPStan reports the missing condition.
            if ($conditionArg === null) {
                return [];
            }

            // The constructor receives the other arguments, checked at the call's position.
            $node = new StaticCall(
                $node->class,
                $node->name,
                array_values(array_filter($args, static fn (Node\Arg $arg): bool => $arg !== $conditionArg)),
                $node->getAttributes(),
            );
        }

        $args = $node->getArgs();
        $class = new FullyQualified($jobClassReflection->getName());

        // dispatchAfterResponse() calls dispatch() from inside the class, so an override decides the arguments.
        // The trait's own dispatch(), whatever its visibility, constructs the class from them.
        if ($methodName === 'dispatchAfterResponse' && $jobClassReflection->getNativeReflection()->getMethod('dispatch')->getFileName() !== $dispatchableFile) {
            $this->checkForwardedCall($scope, $jobClassReflection->getNativeMethod('dispatch'), new StaticCall($class, 'dispatch', $args, $node->getAttributes()), $args);

            return [];
        }

        if (! $jobClassReflection->hasConstructor()) {
            $scope->invokeNodeCallback(new New_($class, $args, $node->getAttributes()));

            return [];
        }

        $this->checkForwardedCall($scope, $jobClassReflection->getConstructor(), new New_($class, $args, $node->getAttributes()), $args);

        return [];
    }

    /**
     * Check the arguments the trait passes to a method from inside the class.
     *
     * @param NodeCallbackInvoker&Scope $scope
     * @param Node\Arg[] $args
     */
    private function checkForwardedCall(Scope $scope, ExtendedMethodReflection $method, Expr $directCall, array $args): void
    {
        $variant = ParametersAcceptorSelector::selectFromArgs($scope, $args, $method->getVariants(), $method->getNamedArgumentsVariants());

        if ($method->isPublic() && array_all($variant->getParameters(), static fn (ParameterReflection $parameter): bool => $parameter->passedByReference()->no())) {
            $scope->invokeNodeCallback($directCall);

            return;
        }

        // The trait calls the method from inside the class, so a non-public one is callable. It passes on
        // the dispatch arguments' values, so, unlike upstream, a reference parameter takes any expression.
        $scope->invokeNodeCallback(new FuncCall(new TypeExpr(new ClosureType(
            parameters: ProxiedParameterReflection::fromTargetParameters($variant->getParameters()),
            variadic: $variant->isVariadic(),
            templateTypeMap: $variant->getTemplateTypeMap(),
            resolvedTemplateTypeMap: $variant->getResolvedTemplateTypeMap(),
            acceptsNamedArguments: $method->acceptsNamedArguments(),
        )), $args, $directCall->getAttributes()));
    }

    /**
     * Get the dispatch methods that construct the class from their arguments.
     *
     * @return non-empty-string[]
     */
    private function getAvailableMethods(): array
    {
        // Upstream's dispatchNow() is omitted: the trait has no such method.
        if ($this->dispatchableClass === Dispatchable::class) {
            return [
                'dispatch',
                'dispatchIf',
                'dispatchUnless',
                'dispatchSync',
                'dispatchAfterResponse',
            ];
        }

        // Unlike upstream, broadcast() is checked too: it constructs the event the same way.
        if ($this->dispatchableClass === EventDispatchable::class) {
            return [
                'dispatch',
                'dispatchIf',
                'dispatchUnless',
                'broadcast',
            ];
        }

        return [];
    }
}
