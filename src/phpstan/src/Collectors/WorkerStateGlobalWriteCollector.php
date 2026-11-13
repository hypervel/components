<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Rules\Coroutine\CoroutineRuleHelper;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Node\InClosureNode;
use PHPStan\Node\InFunctionNode;

use function is_string;
use function sprintf;

/**
 * Collects writes to global variables, which the whole worker shares like static properties: a
 * `$GLOBALS[...]` write wherever it sits, and a write through a name a function's top-level `global`
 * statement binds. Detecting the second walks the function's own statements, since PHPStan's scope
 * doesn't say which variables are global. A request, user or model value written through a plain
 * variable is collected too, so the rule can tell what such a global write keeps.
 *
 * Writes include assignments, increments, decrements and unset() of an offset or property. unset(),
 * a static declaration or a by-reference assignment of the variable itself detaches it instead.
 *
 * @phpstan-import-type FunctionFacts from WorkerStateHelper
 *
 * @phpstan-type GlobalWrite array{type: 'global'|'bound', line: int, name: string, target: string, state: null|string, function: FunctionFacts}
 * @phpstan-type StateWrite array{type: 'value', target: string, state: string}
 *
 * @implements Collector<Node, list<GlobalWrite|StateWrite>>
 */
#[AutowiredService]
class WorkerStateGlobalWriteCollector implements Collector
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
        // Writes are expressions, unset() is a statement, and bindings are read from each function's body.
        return Node::class;
    }

    /**
     * Collect the node's global writes and the request state written through a variable.
     *
     * @return null|list<GlobalWrite|StateWrite>
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $this->helper->checkStaticStateMutation) {
            return null;
        }

        $writes = match (true) {
            // @phpstan-ignore phpstanApi.instanceofAssumption (PHPStan's function nodes carry the scope inside the function, and a collector takes one node type.), phpstanApi.instanceofAssumption (PHPStan's function nodes carry the scope inside the function, and a collector takes one node type.), phpstanApi.instanceofAssumption (PHPStan's function nodes carry the scope inside the function, and a collector takes one node type.)
            $node instanceof InClassMethodNode, $node instanceof InFunctionNode, $node instanceof InClosureNode => $this->findBoundWrites($node->getOriginalNode()->stmts ?? [], $scope),
            default => $this->getWrites($node, $scope),
        };

        return $writes === [] ? null : $writes;
    }

    /**
     * Get the `$GLOBALS` writes the node makes, and the request state it writes through a variable.
     *
     * @return list<GlobalWrite|StateWrite>
     */
    private function getWrites(Node $node, Scope $scope): array
    {
        $writes = [];

        foreach ($this->getTargets($node) as $root => $targets) {
            // A function can't bind $this to a global variable, so its writes never join a bound one.
            if ($root === 'this') {
                continue;
            }

            foreach ($targets as $target) {
                // Only a whole-value assignment says what the target holds; a destructured leaf gets part of it.
                $state = ($node instanceof Assign || $node instanceof AssignRef) && $node->var === $target
                    ? $this->helper->getState($scope->getType($node->expr))
                    : null;

                if ($root === 'GLOBALS') {
                    $function = $this->helper->getFunctionFacts($scope, $target);

                    if ($function !== null) {
                        $writes[] = ['type' => 'global', 'line' => $target->getStartLine(), 'name' => $this->getGlobalsName($target), 'target' => $this->getTargetKey($target, $scope), 'state' => $state, 'function' => $function];
                    }
                } elseif ($state !== null) {
                    $writes[] = ['type' => 'value', 'target' => $this->getTargetKey($target, $scope), 'state' => $state];
                }
            }
        }

        return $writes;
    }

    /**
     * Find the writes through names the statements bind with a top-level `global` statement, in order. A
     * `global` under a condition may not run, so it binds nothing. Nested functions have their own bindings.
     *
     * @param array<Node\Stmt> $statements
     *
     * @return list<GlobalWrite>
     */
    private function findBoundWrites(array $statements, Scope $scope): array
    {
        $bound = [];
        $writes = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Global_) {
                foreach ($statement->vars as $variable) {
                    if ($variable instanceof Variable && is_string($variable->name)) {
                        $bound[$variable->name] = true;
                    }
                }

                continue;
            }

            if ($bound === []) {
                continue;
            }

            foreach ($this->getOwnNodes($statement) as $node) {
                foreach (CoroutineRuleHelper::getDetachedNames($node) as $name) {
                    unset($bound[$name]);
                }

                foreach ($this->getTargets($node) as $name => $targets) {
                    if (! isset($bound[$name])) {
                        continue;
                    }

                    foreach ($targets as $target) {
                        $function = $this->helper->getFunctionFacts($scope, $target);

                        if ($function !== null) {
                            $writes[] = ['type' => 'bound', 'line' => $target->getStartLine(), 'name' => '$' . $name, 'target' => $this->getTargetKey($target, $scope), 'state' => null, 'function' => $function];
                        }
                    }
                }
            }
        }

        return $writes;
    }

    /**
     * Get the targets the node writes, keyed by the name of the variable they're rooted at: an assignment's,
     * through destructuring, an increment's or decrement's, and each offset or property unset() removes. A
     * by-reference assignment to the variable itself rebinds the name rather than writing it, and a chain
     * rooted at a call or a static property isn't a variable's storage.
     *
     * @return array<string, list<Expr>>
     */
    private function getTargets(Node $node): array
    {
        $targets = match (true) {
            $node instanceof Assign, $node instanceof AssignOp => CoroutineRuleHelper::getTargetLeaves($node->var),
            $node instanceof AssignRef => $node->var instanceof Variable ? [] : [$node->var],
            $node instanceof PreInc, $node instanceof PreDec, $node instanceof PostInc, $node instanceof PostDec => [$node->var],
            $node instanceof Unset_ => $node->vars,
            default => [],
        };

        $named = [];

        foreach ($targets as $target) {
            $root = $target;

            while ($root instanceof ArrayDimFetch || $root instanceof PropertyFetch) {
                $root = $root->var;
            }

            if ($root instanceof Variable && is_string($root->name) && ! ($node instanceof Unset_ && $root === $target)) {
                $named[$root->name][] = $target;
            }
        }

        return $named;
    }

    /**
     * Get the key the rule joins a bound write and the state written at its target on. Trait code is collected under
     * the file using the trait, with positions in the trait's own file, so the key includes the enclosing class and
     * function.
     */
    private function getTargetKey(Expr $target, Scope $scope): string
    {
        return sprintf('%s::%s@%d', $scope->getClassReflection()?->getName() ?? '', $scope->getFunction()?->getName() ?? '', $target->getStartFilePos());
    }

    /**
     * Get how a diagnostic names the `$GLOBALS` entry the target writes.
     */
    private function getGlobalsName(Expr $target): string
    {
        $key = null;

        while ($target instanceof ArrayDimFetch || $target instanceof PropertyFetch) {
            if ($target instanceof ArrayDimFetch && $target->var instanceof Variable) {
                $key = $target->dim instanceof String_ ? $target->dim->value : null;
            }

            $target = $target->var;
        }

        return $key === null ? '$GLOBALS' : sprintf("\$GLOBALS['%s']", $key);
    }

    /**
     * Get the nodes of a statement, in order, without entering nested functions or classes.
     *
     * @return list<Node>
     */
    private function getOwnNodes(Node $statement): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Node> */
            public array $found = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
                }

                $this->found[] = $node;

                return null;
            }
        };

        (new NodeTraverser($visitor))->traverse([$statement]);

        return $visitor->found;
    }
}
