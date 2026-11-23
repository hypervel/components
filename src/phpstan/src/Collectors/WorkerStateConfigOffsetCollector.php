<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Stmt\Unset_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;

/**
 * Collects the assignments and unset() calls that set or unset an offset of the global configuration
 * repository, such as config()['app.locale'] = 'fr', which change it like Repository::set(). Each is
 * read with the scope at the write, wherever it sits, such as inside a return or an arrow function.
 *
 * @phpstan-import-type ConfigWrite from WorkerStateHelper
 *
 * @implements Collector<Node, ConfigWrite>
 */
#[AutowiredService]
class WorkerStateConfigOffsetCollector implements Collector
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
        // Assignments are expressions and unset() is a statement.
        return Node::class;
    }

    /**
     * Collect the node's config offset write.
     *
     * @return null|ConfigWrite
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if ((! $node instanceof Assign && ! $node instanceof AssignOp && ! $node instanceof Unset_)
            || ! $this->helper->noRuntimeConfigMutation) {
            return null;
        }

        foreach ($node instanceof Unset_ ? $node->vars : [$node->var] as $target) {
            if (! $target instanceof ArrayDimFetch) {
                continue;
            }

            $receiver = $this->helper->getConfigReceiver($target->var, $scope);

            if ($receiver === null) {
                continue;
            }

            $function = $this->helper->getFunctionFacts($scope, $target);

            return $function === null
                ? null
                : ['type' => 'config', 'line' => $target->getStartLine(), ...$receiver, 'function' => $function];
        }

        return null;
    }
}
