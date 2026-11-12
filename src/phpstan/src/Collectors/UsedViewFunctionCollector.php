<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Concerns\CollectsViewNames;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ReflectionProvider;

use function strtolower;

/** @implements Collector<FuncCall, list<string>> */
#[AutowiredService]
class UsedViewFunctionCollector implements Collector
{
    use CollectsViewNames;

    /**
     * Create a new collector instance.
     */
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * Collect the view rendered through the view() helper.
     *
     * @param FuncCall $node
     * @return null|list<string>
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $funcName = $node->name;

        if (! $funcName instanceof Name) {
            return null;
        }

        // Upstream compares the name as written, but function names are case-insensitive.
        if (strtolower((string) $this->reflectionProvider->resolveFunctionName($funcName, $scope)) !== 'view') {
            return null;
        }

        // Upstream reads the first argument by position, which misses reordered named arguments.
        return $this->getViewNames($node->getArg('view', 0)?->value, $scope);
    }
}
