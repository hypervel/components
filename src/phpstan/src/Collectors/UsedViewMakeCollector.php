<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Contracts\View\Factory;
use Hypervel\PHPStan\Concerns\CollectsViewNames;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ObjectType;

/** @implements Collector<MethodCall, list<string>> */
#[AutowiredService]
class UsedViewMakeCollector implements Collector
{
    use CollectsViewNames;

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * Collect the view made through a view factory.
     *
     * @param MethodCall $node
     * @return null|list<string>
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $name = $node->name;

        if (! $name instanceof Identifier) {
            return null;
        }

        if ($name->toLowerString() !== 'make') {
            return null;
        }

        $class = $node->var;

        if (! (new ObjectType(Factory::class))->isSuperTypeOf($scope->getType($class))->yes()) {
            return null;
        }

        // Upstream reads the first argument by position, which misses reordered named arguments.
        return $this->getViewNames($node->getArg('view', 0)?->value, $scope);
    }
}
