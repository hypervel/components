<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Testing;

use JMac\Testing\Double;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_push;
use function count;
use function in_array;
use function is_string;
use function property_exists;
use function spl_object_id;
use function strtolower;

/**
 * Follow the factory result through chained calls, ternaries and match arms, or through
 * a local variable or property configured in the same statement list before use.
 * Mode changes through aliases, helpers and conditional statements are not inferred.
 *
 * @implements Rule<FileNode>
 */
class RequireExplicitDoubleModeRule implements Rule
{
    /**
     * Get the node type handled by the rule.
     */
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * Report doubles that can be used without choosing a mode.
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $finder = new NodeFinder;
        $nodes = $node->getNodes();
        $pending = [];

        $calls = $finder->find($nodes, static fn (Node $node): bool => $node instanceof StaticCall || $node instanceof MethodCall || $node instanceof NullsafeMethodCall);
        foreach ($calls as $call) {
            if ($call instanceof StaticCall && $call->class instanceof Name && $call->class->toLowerString() === strtolower(Double::class)
                && $call->name instanceof Identifier && $call->name->toLowerString() === 'for'
                && ! $call->isFirstClassCallable()) {
                $pending[spl_object_id($call)] = $call;
            }
        }

        if ($pending === []) {
            return [];
        }

        foreach ($calls as $call) {
            if (($call instanceof MethodCall || $call instanceof NullsafeMethodCall) && $this->isModeCall($call)) {
                foreach ($this->pendingFactories($call->var, $pending) as $factory) {
                    unset($pending[spl_object_id($factory)]);
                }
            }
        }

        if ($pending !== []) {
            $this->checkAssignments($nodes, $pending, $finder);
            $blocks = $finder->find($nodes, static fn (Node $node): bool => property_exists($node, 'stmts'));
            foreach ($blocks as $block) {
                $this->checkAssignments($block->stmts ?? [], $pending, $finder);
                if ($pending === []) {
                    break;
                }
            }
        }

        $errors = [];
        foreach ($pending as $factory) {
            $errors[] = RuleErrorBuilder::message('Choose strict() or passthru() before using this double.')
                ->identifier('hypervel.implicitDoubleMode')
                ->tip('For an intentionally lenient double, use @phpstan-ignore hypervel.implicitDoubleMode (reason).')
                ->line($factory->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * Find bare factories whose result becomes the value of this expression.
     *
     * @param array<int, StaticCall> $pending
     * @return list<StaticCall>
     */
    private function pendingFactories(Expr $value, array $pending): array
    {
        if ($value instanceof Ternary) {
            return [...$this->pendingFactories($value->if ?? $value->cond, $pending), ...$this->pendingFactories($value->else, $pending)];
        }

        if ($value instanceof Match_) {
            $factories = [];
            foreach ($value->arms as $arm) {
                array_push($factories, ...$this->pendingFactories($arm->body, $pending));
            }
            return $factories;
        }

        $id = spl_object_id($value);
        return isset($pending[$id]) ? [$pending[$id]] : [];
    }

    /**
     * Check split setup only within the statement list that created the double.
     *
     * @param Node[] $statements
     * @param array<int, StaticCall> $pending
     */
    private function checkAssignments(array $statements, array &$pending, NodeFinder $finder): void
    {
        foreach ($statements as $index => $statement) {
            if (! $statement instanceof Expression || ! $statement->expr instanceof Assign) {
                continue;
            }

            $factories = $this->pendingFactories($statement->expr->expr, $pending);
            if ($factories === [] || ($key = $this->binding($statement->expr->var)) === null) {
                continue;
            }

            if ($this->selectsModeBeforeUse($statements, $index + 1, $key, $finder)) {
                foreach ($factories as $factory) {
                    unset($pending[spl_object_id($factory)]);
                }
            }
        }
    }

    /**
     * Allow expectation setup and unrelated statements before the first use.
     *
     * @param Node[] $statements
     */
    private function selectsModeBeforeUse(array $statements, int $next, string $key, NodeFinder $finder): bool
    {
        $mentionsBinding = fn (Node $node): bool => $this->binding($node) === $key;

        for (; isset($statements[$next]); ++$next) {
            $statement = $statements[$next];
            $mentions = $finder->find($statement, $mentionsBinding);
            if ($mentions === []) {
                continue;
            }

            $call = $statement instanceof Expression || $statement instanceof Return_ ? $statement->expr : null;
            while (($call instanceof MethodCall || $call instanceof NullsafeMethodCall) && $call->var !== $mentions[0]) {
                $call = $call->var;
            }
            if ((! $call instanceof MethodCall && ! $call instanceof NullsafeMethodCall) || $call->isFirstClassCallable()) {
                return false;
            }
            if ($this->isModeCall($call)) {
                // The arguments to passthru() run before it changes the mode.
                return $finder->findFirst($call->args, $mentionsBinding) === null;
            }
            if (count($mentions) !== 1 || ! $call->name instanceof Identifier || ! in_array($call->name->toLowerString(), ['allows', 'expects'], true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Determine whether the method selects a mode on its receiver.
     */
    private function isModeCall(MethodCall|NullsafeMethodCall $call): bool
    {
        return $call->name instanceof Identifier
            && in_array($call->name->toLowerString(), ['strict', 'passthru'], true)
            && ! $call->isFirstClassCallable();
    }

    /**
     * Identify a local variable or a property on the test itself.
     */
    private function binding(Node $node): ?string
    {
        if ($node instanceof Variable && is_string($node->name)) {
            return '$' . $node->name;
        }

        if ($node instanceof PropertyFetch && $node->var instanceof Variable && $node->var->name === 'this' && $node->name instanceof Identifier) {
            return '$this->' . $node->name->name;
        }

        if ($node instanceof StaticPropertyFetch && $node->class instanceof Name && $node->name instanceof Identifier
            && in_array($node->class->toLowerString(), ['self', 'static'], true)) {
            return $node->class->toLowerString() . '::$' . $node->name->name;
        }

        return null;
    }
}
