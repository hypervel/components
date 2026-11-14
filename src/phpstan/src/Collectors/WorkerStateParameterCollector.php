<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\Expr\SetExistingOffsetValueTypeExpr;
use PHPStan\Node\Expr\SetOffsetValueTypeExpr;
use PHPStan\Node\Expr\UnsetOffsetExpr;
use PHPStan\Node\VariableAssignNode;
use PHPStan\Reflection\ParameterReflection;

use function array_find;
use function is_string;

/**
 * Collects the reassigned parameters whose injected values the worker-state checks rely on: a
 * constructor's request or operation state, a configuration repository, and, for restricted calls,
 * any method parameter that may hold an object.
 *
 * @phpstan-import-type FunctionFacts from WorkerStateHelper
 *
 * @implements Collector<VariableAssignNode, array{function: FunctionFacts, parameter: string}>
 */
#[AutowiredService]
class WorkerStateParameterCollector implements Collector
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
        // @phpstan-ignore phpstanApi.classConstant (PHPStan's normalized variable write, covering destructuring, foreach and catch targets; no API node covers them.)
        return VariableAssignNode::class;
    }

    /**
     * Collect the parameter the node reassigns.
     *
     * @param VariableAssignNode $node
     *
     * @return null|array{function: FunctionFacts, parameter: string}
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $this->helper->isEnabled()) {
            return null;
        }

        // @phpstan-ignore phpstanApi.method (PHPStan's normalized variable write, covering destructuring, foreach and catch targets; no API node covers them.)
        $value = $node->getAssignedExpr();

        // An offset write on an ArrayAccess variable changes the object it holds, not the variable.
        // @phpstan-ignore phpstanApi.class (The offset chain PHPStan builds inside a variable write.), phpstanApi.class (The offset chain PHPStan builds inside a variable write.), phpstanApi.class (The offset chain PHPStan builds inside a variable write.)
        if ($value instanceof SetOffsetValueTypeExpr || $value instanceof SetExistingOffsetValueTypeExpr || $value instanceof UnsetOffsetExpr) {
            return null;
        }

        // @phpstan-ignore phpstanApi.method (PHPStan's normalized variable write, covering destructuring, foreach and catch targets; no API node covers them.)
        $variable = $node->getVariable();
        $parameter = is_string($variable->name)
            ? array_find($this->helper->getParameters($scope), static fn (ParameterReflection $parameter): bool => $parameter->getName() === $variable->name)
            : null;

        if ($parameter === null) {
            return null;
        }

        $function = $this->helper->getFunctionFacts($scope, $variable);

        if ($function === null) {
            return null;
        }

        $constructor = ! $function['closure'] && $function['method'] === '__construct';

        // A method's object parameter may be the evidence that a restricted call reaches the worker-shared instance.
        if (! $this->helper->isConfigRepositoryType($parameter->getType())
            && ! ($constructor && $this->helper->getState($parameter->getType()) !== null)
            && ! ($this->helper->checkRestrictedCalls && ! $function['closure'] && ! $parameter->getType()->isObject()->no())) {
            return null;
        }

        return ['function' => $function, 'parameter' => $parameter->getName()];
    }
}
