<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\View\ViewName;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ReflectionProvider;

use function count;
use function strtolower;

/** @implements Collector<FuncCall, string> */
#[AutowiredService]
final class UsedViewFunctionCollector implements Collector
{
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
     */
    public function processNode(Node $node, Scope $scope): ?string
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
        $template = $node->getArg('view', 0)?->value;

        if ($template instanceof String_) {
            return ViewName::normalize($template->value);
        }

        if ($template instanceof Concat) {
            $template = $scope->getType($template);

            $constantStrings = $template->getConstantStrings();

            if (count($constantStrings) !== 1) {
                return null;
            }

            return ViewName::normalize($constantStrings[0]->getValue());
        }

        return null;
    }
}
