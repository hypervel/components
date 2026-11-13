<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Rules\Coroutine\CoroutineRuleHelper;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Coalesce;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\Expr\NativeTypeExpr;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Node\InClosureNode;
use PHPStan\Node\InFunctionNode;
use PHPStan\Node\PropertyAssignNode;
use PHPStan\Node\VariableAssignNode;

use function array_fill_keys;
use function array_intersect_key;
use function is_string;
use function sprintf;

/**
 * Collects writes to global variables, which the whole worker shares like static properties: a
 * `$GLOBALS[...]` write wherever it sits, and a write through a name a function's top-level `global`
 * statement binds. Detecting the second walks the function's own statements, since PHPStan's scope
 * doesn't say which variables are global. A request, user or model value written through a plain
 * variable is collected too, so the rule can tell what such a global write keeps.
 *
 * Writes include assignments, foreach keys and values, catch bindings, increments, decrements, unset() of
 * an offset or property, and arguments a by-reference parameter writes. unset(), a static declaration or
 * binding the variable itself by reference detaches it instead, and a top-level reference assignment between
 * a plain variable and global storage binds that variable as `global` does.
 *
 * @phpstan-import-type FunctionFacts from WorkerStateHelper
 *
 * @phpstan-type GlobalWrite array{type: 'global'|'bound', line: int, name: string, target: string, state: null|string, argument: bool, function: FunctionFacts}
 * @phpstan-type StateWrite array{type: 'value', target: string, state: string}
 * @phpstan-type ArgumentWrite array{type: 'argument', target: string}
 *
 * @implements Collector<Node, list<ArgumentWrite|GlobalWrite|StateWrite>>
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
     * @return null|list<ArgumentWrite|GlobalWrite|StateWrite>
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $this->helper->checkStaticStateMutation) {
            return null;
        }

        $writes = match (true) {
            // @phpstan-ignore phpstanApi.instanceofAssumption (PHPStan's function nodes carry the scope inside the function, and a collector takes one node type.), phpstanApi.instanceofAssumption (PHPStan's function nodes carry the scope inside the function, and a collector takes one node type.), phpstanApi.instanceofAssumption (PHPStan's function nodes carry the scope inside the function, and a collector takes one node type.)
            $node instanceof InClassMethodNode, $node instanceof InFunctionNode, $node instanceof InClosureNode => $this->findBoundWrites($node->getOriginalNode()->stmts ?? [], $scope),
            // @phpstan-ignore phpstanApi.class (PHPStan's normalized write, which also covers by-reference arguments; no API node covers them.), phpstanApi.method (PHPStan's normalized write, which also covers by-reference arguments; no API node covers them.), phpstanApi.method (PHPStan's normalized write, which also covers by-reference arguments; no API node covers them.)
            $node instanceof VariableAssignNode => $this->getArgumentWrites($node->getVariable(), $node->getAssignedExpr(), $scope),
            // @phpstan-ignore phpstanApi.class (PHPStan's normalized write, which also covers by-reference arguments; no API node covers them.), phpstanApi.method (PHPStan's normalized write, which also covers by-reference arguments; no API node covers them.), phpstanApi.method (PHPStan's normalized write, which also covers by-reference arguments; no API node covers them.)
            $node instanceof PropertyAssignNode => $this->getArgumentWrites($node->getPropertyFetch(), $node->getAssignedExpr(), $scope),
            default => $this->getWrites($node, $scope),
        };

        return $writes === [] ? null : $writes;
    }

    /**
     * Get the evidence that PHPStan's normalized write of a target may be a by-reference argument's: it writes a
     * by-reference parameter's type, or writes `$GLOBALS`, whose offset arguments PHPStan reports like any offset
     * write. PHPStan writes such types elsewhere too, such as foreach values, so the rule keeps an argument only
     * where such a write is at the argument's own position.
     *
     * @return list<ArgumentWrite>
     */
    private function getArgumentWrites(Expr $target, Expr $value, Scope $scope): array
    {
        // @phpstan-ignore phpstanApi.instanceofAssumption (PHPStan's call handling writes a by-reference argument with this expression.)
        return $value instanceof NativeTypeExpr || ($target instanceof Variable && $target->name === 'GLOBALS')
            ? [['type' => 'argument', 'target' => $this->getTargetKey($target, $scope)]]
            : [];
    }

    /**
     * Get the `$GLOBALS` writes the node makes, the `$GLOBALS` storage it passes to a call, and the request
     * state it writes through a variable.
     *
     * @return list<GlobalWrite|StateWrite>
     */
    private function getWrites(Node $node, Scope $scope): array
    {
        $writes = [];

        // PHPStan also passes a nullsafe call as a method call.
        $arguments = $node instanceof NullsafeMethodCall ? [] : $this->getArgumentTargets($node);

        foreach ($arguments['GLOBALS'] ?? [] as $target) {
            $function = $this->helper->getFunctionFacts($scope, $target);

            if ($function !== null) {
                $writes[] = ['type' => 'global', 'line' => $target->getStartLine(), 'name' => $this->getGlobalsName($target), 'target' => $this->getTargetKey($target, $scope), 'state' => null, 'argument' => true, 'function' => $function];
            }
        }

        foreach ($this->getTargets($node) as $root => $targets) {
            // A function can't bind $this to a global variable, so its writes never join a bound one.
            if ($root === 'this') {
                continue;
            }

            foreach ($targets as $target) {
                // Only a whole value says what the target holds; a destructured leaf gets part of it.
                $state = match (true) {
                    ($node instanceof Assign || $node instanceof AssignRef || $node instanceof Coalesce) && $node->var === $target => $this->helper->getState($scope->getType($node->expr)),
                    $node instanceof Foreach_ && $node->valueVar === $target => $this->helper->getState($scope->getType($node->expr)->getIterableValueType()),
                    $node instanceof Foreach_ && $node->keyVar === $target => $this->helper->getState($scope->getType($node->expr)->getIterableKeyType()),
                    default => null,
                };

                if ($root === 'GLOBALS') {
                    $function = $this->helper->getFunctionFacts($scope, $target);

                    if ($function !== null) {
                        $writes[] = ['type' => 'global', 'line' => $target->getStartLine(), 'name' => $this->getGlobalsName($target), 'target' => $this->getTargetKey($target, $scope), 'state' => $state, 'argument' => false, 'function' => $function];
                    }
                } elseif ($state !== null) {
                    $writes[] = ['type' => 'value', 'target' => $this->getTargetKey($target, $scope), 'state' => $state];
                }
            }
        }

        return $writes;
    }

    /**
     * Find the writes through names the statements bind to global storage, in order: with a top-level `global`
     * statement, or a top-level reference assignment between a plain variable and global storage. One under a
     * condition may not run, so it binds nothing. Nested functions have their own bindings.
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

            // Read before the statement detaches the alias from what it was bound to.
            $alias = $statement instanceof Expression && $statement->expr instanceof AssignRef
                ? CoroutineRuleHelper::getStorageAlias($statement->expr, $bound + ['GLOBALS' => true])
                : null;

            foreach ($bound === [] ? [] : $this->getOwnNodes($statement) as $node) {
                foreach (CoroutineRuleHelper::getDetachedNames($node) as $name) {
                    unset($bound[$name]);
                }

                foreach ([[false, $this->getTargets($node)], [true, $this->getArgumentTargets($node)]] as [$argument, $named]) {
                    foreach (array_intersect_key($named, $bound) as $name => $targets) {
                        foreach ($targets as $target) {
                            $function = $this->helper->getFunctionFacts($scope, $target);

                            if ($function !== null) {
                                $writes[] = ['type' => 'bound', 'line' => $target->getStartLine(), 'name' => '$' . $name, 'target' => $this->getTargetKey($target, $scope), 'state' => null, 'argument' => $argument, 'function' => $function];
                            }
                        }
                    }
                }
            }

            if ($alias !== null) {
                $bound[$alias[0]] = true;
            }
        }

        return $writes;
    }

    /**
     * Get the targets the node writes, keyed by the name of the variable they're rooted at: an assignment's,
     * a foreach key's or value's and a catch binding's (CoroutineRuleHelper::getWriteTargets()), an
     * increment's or decrement's, and each offset or property unset() removes. A variable the node unsets
     * or binds by reference points its name elsewhere rather than being written, and a chain rooted at a
     * call or a static property isn't a variable's storage.
     *
     * @return array<string, list<Expr>>
     */
    private function getTargets(Node $node): array
    {
        $targets = match (true) {
            $node instanceof PreInc, $node instanceof PreDec, $node instanceof PostInc, $node instanceof PostDec => [$node->var],
            $node instanceof Unset_ => $node->vars,
            default => CoroutineRuleHelper::getWriteTargets($node),
        };

        $rebound = array_fill_keys(CoroutineRuleHelper::getDetachedNames($node), true);
        $named = [];

        foreach ($targets as $target) {
            $root = $this->getRootName($target);

            if ($root !== null && ! ($target instanceof Variable && isset($rebound[$root]))) {
                $named[$root][] = $target;
            }
        }

        return $named;
    }

    /**
     * Get the arguments a call passes that a by-reference parameter could write, keyed by the name of the variable
     * they're rooted at. Creating a first-class callable passes nothing.
     *
     * @return array<string, list<Expr>>
     */
    private function getArgumentTargets(Node $node): array
    {
        if (! ($node instanceof FuncCall || $node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall || $node instanceof New_)
            || $node->isFirstClassCallable()) {
            return [];
        }

        $named = [];

        foreach ($node->getArgs() as $argument) {
            $root = $this->getRootName($argument->value);

            if (! $argument->unpack && $root !== null) {
                $named[$root][] = $argument->value;
            }
        }

        return $named;
    }

    /**
     * Get the name of the variable an offset or property chain is rooted at, or null when a call or a static
     * property holds it rather than a variable.
     */
    private function getRootName(Expr $target): ?string
    {
        while ($target instanceof ArrayDimFetch || $target instanceof PropertyFetch) {
            $target = $target->var;
        }

        return $target instanceof Variable && is_string($target->name) ? $target->name : null;
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
