<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Container\Attributes\Singleton;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\InClassNode;

use function is_string;

/**
 * Collects the classes that are worker-lifetime roots by declaration, and the request or operation
 * state their promoted constructor parameters keep.
 *
 * @implements Collector<InClassNode, array{class: string, singleton: bool, queued: bool, captures: list<array{property: string, line: int, state: string}>}>
 */
#[AutowiredService]
class WorkerStateClassCollector implements Collector
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
        return InClassNode::class;
    }

    /**
     * Collect the class's root declarations and promoted state captures.
     *
     * @param InClassNode $node
     *
     * @return null|array{class: string, singleton: bool, queued: bool, captures: list<array{property: string, line: int, state: string}>}
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $node->getClassReflection();

        if (! $this->helper->isEnabled() || ! $class->isClass()) {
            return null;
        }

        $concrete = ! $class->isAbstract();
        $singleton = $concrete && $class->getNativeReflection()->getAttributes(Singleton::class) !== [];
        $queued = $concrete && $class->is(ShouldQueue::class);
        $captures = [];

        $constructor = $node->getOriginalNode()->getMethod('__construct');

        // A constructor documented as boot-time or test-only keeps its parameters by design.
        $parameters = $this->helper->hasLifetimeTag($constructor?->getDocComment()?->getText()) ? [] : $constructor->params ?? [];

        foreach ($parameters as $parameter) {
            if ($parameter->flags === 0 || ! $parameter->var instanceof Variable || ! is_string($parameter->var->name)) {
                continue;
            }

            $state = $this->helper->getState($class->getNativeProperty($parameter->var->name)->getReadableType());

            if ($state !== null) {
                $captures[] = ['property' => $parameter->var->name, 'line' => $parameter->getStartLine(), 'state' => $state];
            }
        }

        if (! $singleton && ! $queued && $captures === []) {
            return null;
        }

        return [
            'class' => $class->getName(),
            'singleton' => $singleton,
            'queued' => $queued,
            'captures' => $captures,
        ];
    }
}
