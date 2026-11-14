<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\Expr\SetExistingOffsetValueTypeExpr;
use PHPStan\Node\Expr\SetOffsetValueTypeExpr;
use PHPStan\Node\Expr\UnsetOffsetExpr;
use PHPStan\Node\PropertyAssignNode;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\ObjectType;

use function array_any;
use function is_string;

/**
 * Collects the property writes the worker-state checks may report: writes that keep request or
 * operation state, request-keyed cache entries, and writes in methods that may run while handling
 * a request or job.
 *
 * Only static properties and properties of $this count: any other object's lifetime is unknown.
 *
 * @phpstan-import-type FunctionFacts from WorkerStateHelper
 *
 * @implements Collector<PropertyAssignNode, array{static: bool, class: string, property: string, line: int, function: FunctionFacts, state: null|string, parameter: null|string, cacheKey: bool, copied: bool}>
 */
#[AutowiredService]
class WorkerStateWriteCollector implements Collector
{
    /**
     * Create a new collector instance.
     */
    public function __construct(private WorkerStateHelper $helper)
    {
    }

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        // @phpstan-ignore phpstanApi.classConstant (PHPStan's normalized property write, which its own property rules read; no API node covers static and offset writes.)
        return PropertyAssignNode::class;
    }

    /**
     * Collect the write's property, function and value facts.
     *
     * @param PropertyAssignNode $node
     *
     * @return null|array{static: bool, class: string, property: string, line: int, function: FunctionFacts, state: null|string, parameter: null|string, cacheKey: bool, copied: bool}
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $this->helper->hasStateChecks()) {
            return null;
        }

        // @phpstan-ignore phpstanApi.method (PHPStan's normalized property write, which its own property rules read; no API node covers static and offset writes.)
        $fetch = $node->getPropertyFetch();

        if (! $fetch->name instanceof Identifier) {
            return null;
        }

        $static = $fetch instanceof StaticPropertyFetch;

        if ($static) {
            $type = $fetch->class instanceof Name ? new ObjectType($scope->resolveName($fetch->class)) : $scope->getType($fetch->class);
            $property = $scope->getStaticPropertyReflection($type, $fetch->name->name);
        } elseif ($fetch->var instanceof Variable && $fetch->var->name === 'this') {
            $property = $scope->getInstancePropertyReflection($scope->getType($fetch->var), $fetch->name->name);
        } else {
            return null;
        }

        $class = $static ? $property?->getDeclaringClass() : $scope->getClassReflection();

        if ($property === null || $class === null || $property->isVirtual()->yes()) {
            return null;
        }

        $function = $this->helper->getFunctionFacts($scope, $fetch);

        if ($function === null) {
            return null;
        }

        // @phpstan-ignore phpstanApi.method (PHPStan's normalized property write, which its own property rules read; no API node covers static and offset writes.)
        $value = $node->getAssignedExpr();
        $cacheKey = false;
        $storage = $scope->getType($fetch);

        // An offset write on a property WorkerStateConfigOffsetCollector recognizes as the global repository
        // changes configuration, as its set() does, which noRuntimeConfigMutation owns. Other repository
        // properties are ordinary object properties.
        // @phpstan-ignore phpstanApi.class (The offset chain PHPStan builds inside a property write.), phpstanApi.class (The offset chain PHPStan builds inside a property write.), phpstanApi.class (The offset chain PHPStan builds inside a property write.)
        if (($value instanceof SetOffsetValueTypeExpr || $value instanceof SetExistingOffsetValueTypeExpr || $value instanceof UnsetOffsetExpr)
            && $this->helper->getConfigReceiver($fetch, $scope) !== null) {
            return null;
        }

        // Storage the method just reset holds only what the method itself adds.
        $growingStorage = $storage->isArray()->yes() && ! $storage->isConstantArray()->yes();

        // A write that replaces the property, or changes only values such as arrays and strings (including an
        // array it creates from null), changes only a copied instance's own value. One into an object the
        // property holds, or may hold, changes the object a clone shares with the container's instance.
        $copied = true;

        // @phpstan-ignore phpstanApi.class (The offset chain PHPStan builds inside a property write.), phpstanApi.class (The offset chain PHPStan builds inside a property write.)
        while ($value instanceof SetOffsetValueTypeExpr || $value instanceof SetExistingOffsetValueTypeExpr) {
            // @phpstan-ignore phpstanApi.method (The offset chain PHPStan builds inside a property write.)
            $dim = $value->getDim();

            $cacheKey = $cacheKey || ($growingStorage
                && $dim !== null
                && $this->helper->readsRequestState($dim, $scope)
                && ! $this->helper->isBoundedKey($scope->getType($dim)));

            // @phpstan-ignore phpstanApi.method (The offset chain PHPStan builds inside a property write.)
            $copied = $copied && $scope->getType($value->getVar())->isObject()->no();

            // @phpstan-ignore phpstanApi.method (The offset chain PHPStan builds inside a property write.)
            $value = $value->getValue();
        }

        // @phpstan-ignore phpstanApi.class (The offset chain PHPStan builds inside a property write.)
        if ($value instanceof UnsetOffsetExpr) {
            // @phpstan-ignore phpstanApi.method (The offset chain PHPStan builds inside a property write.)
            $copied = $copied && $scope->getType($value->getVar())->isObject()->no();
        }

        // @phpstan-ignore phpstanApi.class (The offset chain PHPStan builds inside a property write.)
        $state = $value instanceof UnsetOffsetExpr ? null : $this->helper->getState($scope->getType($value));

        // A constructor parameter holds what the container injects unless the constructor reassigns it;
        // the rule checks both.
        $parameter = $state !== null
            && $function['method'] === '__construct'
            && ! $function['closure']
            && $value instanceof Variable
            && is_string($value->name)
            && array_any(
                $scope->getFunction()?->getOnlyVariant()->getParameters() ?? [],
                static fn (ParameterReflection $parameter): bool => $parameter->getName() === $value->name,
            ) ? $value->name : null;

        // A write in a closure may run in a route action, and one in an instance method in a booted
        // middleware or controller action, or in a helper such a method or a job calls.
        $method = $scope->getFunction();
        $candidate = $function['context'] !== null
            || $function['closure']
            || ($method instanceof MethodReflection
                && ! $method->isStatic()
                && $method->getName() !== '__construct');

        if ($state === null && ! $cacheKey && ! $candidate) {
            return null;
        }

        return [
            'static' => $static,
            'class' => $class->getName(),
            'property' => $fetch->name->name,
            'line' => $fetch->getStartLine(),
            'function' => $function,
            'state' => $state,
            'parameter' => $parameter,
            'cacheKey' => $cacheKey,
            'copied' => $copied,
        ];
    }
}
