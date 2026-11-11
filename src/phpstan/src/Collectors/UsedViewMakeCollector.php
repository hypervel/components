<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Contracts\View\Factory;
use Hypervel\View\ViewName;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ObjectType;

/** @implements Collector<MethodCall, string> */
#[AutowiredService]
class UsedViewMakeCollector implements Collector
{
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
     */
    public function processNode(Node $node, Scope $scope): ?string
    {
        $name = $node->name;

        if (! $name instanceof Identifier) {
            return null;
        }

        if ($name->toLowerString() !== 'make') {
            return null;
        }

        // Upstream reads the first argument by position, which misses reordered named arguments.
        $template = $node->getArg('view', 0)?->value;

        if (! $template instanceof String_) {
            return null;
        }

        $class = $node->var;

        if (! (new ObjectType(Factory::class))->isSuperTypeOf($scope->getType($class))->yes()) {
            return null;
        }

        return ViewName::normalize($template->value);
    }
}
