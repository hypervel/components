<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Concerns\CollectsViewNames;
use Hypervel\Support\Facades\View;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ObjectType;

/** @implements Collector<StaticCall, list<string>> */
#[AutowiredService]
class UsedViewFacadeMakeCollector implements Collector
{
    use CollectsViewNames;

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * Collect the view made through the View facade.
     *
     * @param StaticCall $node
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

        $class = $node->class;

        if (! $class instanceof Name) {
            return null;
        }

        $class = $scope->resolveName($class);

        if (! (new ObjectType(View::class))->isSuperTypeOf(new ObjectType($class))->yes()) {
            return null;
        }

        // Upstream reads the first argument by position, which misses reordered named arguments.
        return $this->getViewNames($node->getArg('view', 0)?->value, $scope);
    }
}
