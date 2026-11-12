<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\PHPStan\Support\FacadeHelper;
use Hypervel\Support\Facades\Facade;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;

use function array_any;
use function array_find;
use function array_find_key;
use function array_values;
use function count;
use function is_string;

/**
 * Collects the calls to methods marked as boot-time or test-only, with the receiver evidence a
 * worker-shared restriction needs. The rule judges them by the operation the call runs in.
 *
 * @phpstan-import-type FunctionFacts from WorkerStateHelper
 *
 * @phpstan-type Receiver array{abstracts: list<string>}|array{parameter: string}|array{property: array{string, string}}
 * @phpstan-type RestrictedCall array{line: int, method: string, attribute: string, condition: null|array{string, string}, may: bool, whenShared: bool, receiver: null|Receiver, function: FunctionFacts}
 *
 * @implements Collector<CallLike, list<RestrictedCall>>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkRestrictedCalls%')]
class RestrictedCallCollector implements Collector
{
    /**
     * Create a new collector instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private WorkerStateHelper $helper,
        private FacadeHelper $facadeHelper,
    ) {
    }

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Collect each restricted method the call runs whose restriction may apply.
     *
     * @param CallLike $node
     * @param DependencyTracker&Scope $scope
     *
     * @return null|list<RestrictedCall>
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        // PHPStan also analyzes a nullsafe call as a method call. Creating a first-class callable runs nothing.
        if (! ($node instanceof MethodCall || $node instanceof StaticCall)
            || ! $node->name instanceof Identifier
            || $node->isFirstClassCallable()) {
            return null;
        }

        $calls = [];
        $function = null;

        foreach ($this->facadeHelper->getCalledMethods($node, $node->name->toString(), $scope) as $method) {
            $attribute = $this->helper->getLifetimeAttribute($method);
            $declaration = $method->getDeclaringClass()->getName() . '::' . $method->getName();

            if ($attribute === null || isset($calls[$declaration])) {
                continue;
            }

            // Code that is itself boot-time or test-only may call restricted methods, and
            // noRuntimeConfigMutation owns configuration writes.
            $function ??= $this->helper->getFunctionFacts($scope, $node);

            if ($function === null || $this->isConfigWrite($node, $scope)) {
                return null;
            }

            // Exported nodes keep the marker's argument expressions, not the values of constants they read.
            $this->trackFiles(SourceFiles::forAttributeArguments($this->reflectionProvider, $method->getDeclaringClass(), $attribute->getName(), $method->getName()), $scope);

            $arguments = $attribute->getArgumentTypes();
            $condition = $this->getCondition($arguments['whenNotDefault'] ?? null, $arguments['whenProvided'] ?? null);
            $matches = $condition === null ? TrinaryLogic::createYes() : $this->matchesCondition($node, $method, $condition, $scope);
            $whenShared = ($arguments['whenShared'] ?? null)?->isTrue()->yes() ?? false;

            if ($matches->no()) {
                continue;
            }

            $receiver = $whenShared ? $this->getReceiver($node, $scope) : null;

            if ($whenShared && $receiver === null) {
                continue;
            }

            $calls[$declaration] = [
                'line' => $node->getStartLine(),
                'method' => $declaration,
                'attribute' => $attribute->getName(),
                'condition' => $condition,
                'may' => $matches->maybe(),
                'whenShared' => $whenShared,
                'receiver' => $receiver,
                'function' => $function,
            ];
        }

        return $calls === [] ? null : array_values($calls);
    }

    /**
     * Determine whether the call changes configuration, as noRuntimeConfigMutation recognizes it.
     */
    private function isConfigWrite(MethodCall|StaticCall $call, Scope $scope): bool
    {
        return $this->helper->isConfigWrite($call, $scope)
            && (! $call instanceof MethodCall || $this->helper->isConfigRepositoryType($scope->getType($call->var)));
    }

    /**
     * Get the marker's parameter condition, as its argument and the parameter it names.
     *
     * @return null|array{string, string}
     */
    private function getCondition(?Type $whenNotDefault, ?Type $whenProvided): ?array
    {
        foreach (['whenNotDefault' => $whenNotDefault, 'whenProvided' => $whenProvided] as $argument => $type) {
            $names = $type?->getConstantStrings() ?? [];

            if (count($names) === 1) {
                return [$argument, $names[0]->getValue()];
            }
        }

        return null;
    }

    /**
     * Determine whether the call meets the marker's parameter condition.
     *
     * An unpacked argument may bind the parameter unless a named argument or an earlier positional
     * one does, so it leaves the binding unknown.
     *
     * @param array{string, string} $condition
     * @param DependencyTracker&Scope $scope
     */
    private function matchesCondition(MethodCall|StaticCall $call, ExtendedMethodReflection $method, array $condition, Scope $scope): TrinaryLogic
    {
        [$kind, $name] = $condition;
        $parameters = $method->getOnlyVariant()->getParameters();
        $position = array_find_key($parameters, static fn (ParameterReflection $parameter): bool => $parameter->getName() === $name);

        // RestrictedAttributeRule reports a marker naming a missing parameter.
        if ($position === null) {
            return TrinaryLogic::createNo();
        }

        $argument = $call->getArg($name, $position);

        if ($argument === null) {
            return array_any($call->getArgs(), static fn (Arg $argument): bool => $argument->unpack)
                ? TrinaryLogic::createMaybe()
                : TrinaryLogic::createNo();
        }

        $default = $parameters[$position]->getDefaultValue();

        if ($kind === 'whenProvided' || $default === null) {
            return TrinaryLogic::createYes();
        }

        // Exported nodes record only that a default exists, so a changed default would not reanalyze the caller.
        $this->trackFiles(SourceFiles::forParameterDefault($this->reflectionProvider, $method->getDeclaringClass(), $method->getName(), $name), $scope);

        $type = $scope->getType($argument->value);
        $isDefault = $default->isSuperTypeOf($type);

        return match (true) {
            $isDefault->no() => TrinaryLogic::createYes(),
            $isDefault->yes() && $type->isConstantValue()->yes() => TrinaryLogic::createNo(),
            default => TrinaryLogic::createMaybe(),
        };
    }

    /**
     * Make the call's file depend on the source files, so it is analyzed again when they change.
     *
     * @param list<string> $files
     * @param DependencyTracker&Scope $scope
     */
    private function trackFiles(array $files, Scope $scope): void
    {
        foreach ($files as $file) {
            if ($file !== $scope->getFile()) {
                $scope->trackFileDependency($file);
            }
        }
    }

    /**
     * Get the evidence that the call's receiver may be the instance the container shares for the
     * worker, which the rule checks against the booted lifetimes, or null when there is none.
     *
     * The evidence is a facade's accessor or a literal resolution from the application container, an
     * entry method's parameter the container may inject, or a readonly property its constructor promotes.
     *
     * @param DependencyTracker&Scope $scope
     *
     * @return null|Receiver
     */
    private function getReceiver(MethodCall|StaticCall $call, Scope $scope): ?array
    {
        if ($call instanceof StaticCall) {
            $name = $call->class instanceof Name ? $scope->resolveName($call->class) : null;
            $class = $name !== null && $this->reflectionProvider->hasClass($name) ? $this->reflectionProvider->getClass($name) : null;
            $accessor = $class !== null && $class->is(Facade::class) ? $this->facadeHelper->getAccessor($class, $scope) : null;

            return $accessor === null ? null : ['abstracts' => [$accessor]];
        }

        $receiver = $call->var;

        if ($receiver instanceof CallLike) {
            $abstracts = $this->helper->getApplicationResolvedAbstracts($receiver, $scope);

            return $abstracts === null ? null : ['abstracts' => $abstracts];
        }

        // A closure's own parameters are never supplied by the container.
        if ($receiver instanceof Variable && is_string($receiver->name) && ! $scope->isInAnonymousFunction()) {
            $parameter = array_find(
                $this->helper->getParameters($scope),
                static fn (ParameterReflection $parameter): bool => $parameter->getName() === $receiver->name,
            );

            return $parameter === null ? null : ['parameter' => $parameter->getName()];
        }

        $property = $this->helper->getPromotedProperty($receiver, $scope);

        return $property === null ? null : ['property' => $property];
    }
}
