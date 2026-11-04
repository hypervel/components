<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Support\Facades\Route;
use Hypervel\View\ViewName;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ObjectType;

/** @implements Collector<StaticCall, string> */
#[AutowiredService]
final class UsedRouteFacadeViewCollector implements Collector
{
    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * Collect the view registered by a Route::view() call.
     *
     * @param StaticCall $node
     */
    public function processNode(Node $node, Scope $scope): ?string
    {
        $name = $node->name;

        if (! $name instanceof Identifier) {
            return null;
        }

        if ($name->name !== 'view') {
            return null;
        }

        // Upstream reads the second argument by position, which misses reordered named arguments.
        $template = $node->getArg('view', 1)?->value;

        if (! $template instanceof String_) {
            return null;
        }

        $class = $node->class;

        if (! $class instanceof Name) {
            return null;
        }

        $class = $scope->resolveName($class);

        if (! (new ObjectType(Route::class))->isSuperTypeOf(new ObjectType($class))->yes()) {
            return null;
        }

        return ViewName::normalize($template->value);
    }
}
