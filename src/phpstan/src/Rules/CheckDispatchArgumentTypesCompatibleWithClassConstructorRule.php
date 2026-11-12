<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Events\Dispatchable as EventDispatchable;
use Hypervel\PHPStan\Reflection\ProxiedMethodReflection;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\FunctionCallParametersCheck;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\TrinaryLogic;

use function array_filter;
use function array_find;
use function array_values;
use function count;
use function in_array;
use function sprintf;
use function str_replace;
use function strcasecmp;
use function ucfirst;

/** @implements Rule<StaticCall> */
class CheckDispatchArgumentTypesCompatibleWithClassConstructorRule implements Rule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private FunctionCallParametersCheck $check,
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

        // Unlike upstream, a class's own dispatch method decides its arguments itself.
        if (
            ! $calledClass->hasNativeMethod($methodName)
            || $calledClass->getNativeReflection()->getMethod($methodName)->getFileName() !== $this->reflectionProvider->getClass($this->dispatchableClass)->getFileName()
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

        $jobOrEvent = $this->dispatchableClass === Dispatchable::class ? 'job' : 'event';

        if (! $jobClassReflection->hasConstructor()) {
            if (count($node->getArgs()) > 0) {
                return [
                    RuleErrorBuilder::message(sprintf(
                        ucfirst($jobOrEvent) . ' class %s does not have a constructor and must be dispatched without any parameters.',
                        $jobClassReflection->getDisplayName(),
                    ))
                        ->identifier(sprintf('hypervel.%s.noConstructor', $jobOrEvent . 's'))
                        ->build(),
                ];
            }

            return [];
        }

        $constructorReflection = $jobClassReflection->getConstructor();

        $classDisplayName = str_replace('%', '%%', $jobClassReflection->getDisplayName());

        $variant = ParametersAcceptorSelector::selectFromArgs($scope, $node->getArgs(), $constructorReflection->getVariants());

        // @phpstan-ignore phpstanApi.method (PHPStan's own argument check, which new and static calls use.)
        return $this->check->check(
            // Unlike upstream, a reference parameter takes any expression: the dispatch method passes its
            // variadic arguments' values to the constructor, never the caller's variables.
            ProxiedMethodReflection::proxyVariant($variant, $variant->getReturnType()),
            $scope,
            $constructorReflection->getDeclaringClass()->isBuiltin(),
            $node,
            'staticMethod',
            TrinaryLogic::createYes(),
            ucfirst($jobOrEvent) . ' class ' . $classDisplayName . ' constructor invoked with %d parameter in ' . $classDisplayName . '::' . $methodName . '(), %d required.',
            ucfirst($jobOrEvent) . ' class ' . $classDisplayName . ' constructor invoked with %d parameters in ' . $classDisplayName . '::' . $methodName . '(), %d required.',
            ucfirst($jobOrEvent) . ' class ' . $classDisplayName . ' constructor invoked with %d parameter in ' . $classDisplayName . '::' . $methodName . '(), at least %d required.',
            ucfirst($jobOrEvent) . ' class ' . $classDisplayName . ' constructor invoked with %d parameters in ' . $classDisplayName . '::' . $methodName . '(), at least %d required.',
            ucfirst($jobOrEvent) . ' class ' . $classDisplayName . ' constructor invoked with %d parameter in ' . $classDisplayName . '::' . $methodName . '(), %d-%d required.',
            ucfirst($jobOrEvent) . ' class ' . $classDisplayName . ' constructor invoked with %d parameters in ' . $classDisplayName . '::' . $methodName . '(), %d-%d required.',
            '%s of ' . $jobOrEvent . ' class ' . $classDisplayName . ' constructor expects %s in ' . $classDisplayName . '::' . $methodName . '(), %s given.',
            '', // constructor does not have a return type
            '%s of ' . $jobOrEvent . ' class ' . $classDisplayName . ' constructor is passed by reference, so it expects variables only',
            'Unable to resolve the template type %s in instantiation of ' . $jobOrEvent . ' class ' . $classDisplayName,
            'Missing parameter $%s in call to ' . $classDisplayName . ' constructor.',
            'Unknown parameter $%s in call to ' . $classDisplayName . ' constructor.',
            'Return type of call to ' . $classDisplayName . ' constructor contains unresolvable type.',
            '',
            '', // no named arguments
            '',
            '',
            '',
            null,
            null, // no callee reflection for parameter closure type extensions
        );
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
