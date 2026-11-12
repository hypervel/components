<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Unset_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;

/**
 * Collects the statements that set or unset an offset of the global configuration repository, such as
 * config()['app.locale'] = 'fr', which change it like Repository::set().
 *
 * @phpstan-import-type ConfigWrite from WorkerStateHelper
 *
 * @implements Collector<Stmt, ConfigWrite>
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
        return Stmt::class;
    }

    /**
     * Collect the statement's config offset write.
     *
     * @param Stmt $node
     *
     * @return null|ConfigWrite
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $this->helper->noRuntimeConfigMutation) {
            return null;
        }

        $targets = match (true) {
            $node instanceof Expression && ($node->expr instanceof Assign || $node->expr instanceof AssignOp) => [$node->expr->var],
            $node instanceof Unset_ => $node->vars,
            default => [],
        };

        foreach ($targets as $target) {
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
                : ['type' => 'config', 'line' => $node->getStartLine(), ...$receiver, 'function' => $function];
        }

        return null;
    }
}
