<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Closure;
use Hypervel\Coroutine\Coroutine;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ReflectionProvider;
use Swoole\Coroutine\CanceledException;

use function end;
use function strtolower;

#[AutowiredService]
class CoroutineRuleHelper
{
    /**
     * Create a new coroutine rule helper instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Determine whether the name is Swoole's cancellation exception.
     */
    public function isCancellation(Name $name): bool
    {
        return $name->toLowerString() === strtolower(CanceledException::class);
    }

    /**
     * Get whether the condition is a Coroutine::causedByCancellation() guard on the variable (true), the guard
     * negated (false), or neither (null).
     */
    public function guardPolarity(Expr $condition, string $variable): ?bool
    {
        if ($condition instanceof BooleanNot) {
            return $this->guardPolarity($condition->expr, $variable) === true ? false : null;
        }

        if (! $condition instanceof StaticCall
            || $condition->isFirstClassCallable()
            || ! $condition->class instanceof Name
            || ! $condition->name instanceof Identifier
            || $condition->name->toLowerString() !== 'causedbycancellation') {
            return null;
        }

        $argument = $condition->getArgs()[0] ?? null;
        $className = $condition->class->toString();

        return $argument !== null
            && ! $argument->unpack
            && $argument->value instanceof Variable
            && $argument->value->name === $variable
            && $this->reflectionProvider->hasClass($className)
            && $this->reflectionProvider->getClass($className)->is(Coroutine::class)
                ? true
                : null;
    }

    /**
     * Determine whether the statements end by leaving their block through one of the given statement or expression types.
     *
     * @param array<Stmt> $statements
     * @param list<class-string<Node>> $exits
     */
    public function endsWith(array $statements, array $exits): bool
    {
        $last = end($statements);
        $node = $last instanceof Expression ? $last->expr : $last;

        foreach ($exits as $exit) {
            if ($node instanceof $exit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the matching nodes in the statements, skipping the bodies of nested functions and classes,
     * and of nested catches that bind the given variable again, since it names another exception there.
     *
     * @param array<Node> $statements
     * @param Closure(Node): bool $filter
     *
     * @return list<Node>
     */
    public function find(array $statements, Closure $filter, ?string $variable = null): array
    {
        $visitor = new class($filter, $variable) extends NodeVisitorAbstract {
            /** @var list<Node> */
            public array $found = [];

            /**
             * @param Closure(Node): bool $filter
             */
            public function __construct(
                private Closure $filter,
                private ?string $variable,
            ) {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof FunctionLike
                    || $node instanceof ClassLike
                    || ($node instanceof Catch_ && $this->variable !== null && $node->var?->name === $this->variable)) {
                    return NodeVisitor::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
                }

                if (($this->filter)($node)) {
                    $this->found[] = $node;
                }

                return null;
            }
        };

        (new NodeTraverser($visitor))->traverse($statements);

        return $visitor->found;
    }
}
