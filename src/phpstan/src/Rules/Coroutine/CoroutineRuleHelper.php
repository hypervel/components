<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Closure;
use Hypervel\Coroutine\Coroutine;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignOp\Coalesce;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\Expr\TypeExpr;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Swoole\Coroutine\CanceledException;

use function array_any;
use function count;
use function end;
use function in_array;
use function is_string;
use function strtolower;

#[AutowiredService]
class CoroutineRuleHelper
{
    /**
     * The native channel, WaitGroup, Waiter and join methods, keyed by declaring class, that a try may call and still
     * keep an exact catch: each either doesn't wait or throws cancellation directly.
     */
    private const array NATIVE_WAITS = [
        'Swoole\Coroutine\Channel' => ['push', 'pop', 'close'],
        'Hypervel\Engine\Channel' => ['push', 'pop', 'close'],
        'Hypervel\Contracts\Engine\ChannelInterface' => ['push', 'pop', 'close'],
        'Swoole\Coroutine\WaitGroup' => ['add', 'done', 'wait'],
        'Hypervel\Coroutine\WaitGroup' => ['add', 'done', 'wait'],
        'Hypervel\Coroutine\Waiter' => ['wait'],
        'Swoole\Coroutine' => ['join'],
        'Hypervel\Coroutine\Coroutine' => ['join'],
        'Hypervel\Engine\Coroutine' => ['join'],
    ];

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
     * Determine whether the node writes the variable: =, =& or a compound assignment, including through
     * destructuring, a foreach key or value, or a catch binding.
     */
    public function assigns(Node $node, string $variable): bool
    {
        return array_any(self::getWriteTargets($node), static fn (Expr $target): bool => $target instanceof Variable && $target->name === $variable);
    }

    /**
     * Get the targets the node writes, such as variables and properties: through =, =& or a compound
     * assignment, including destructuring, a foreach key or value, or a catch binding.
     *
     * @return list<Expr>
     */
    private static function getWriteTargets(Node $node): array
    {
        return match (true) {
            $node instanceof Assign, $node instanceof AssignRef, $node instanceof AssignOp => self::getTargetLeaves($node->var),
            $node instanceof Foreach_ => [...self::getTargetLeaves($node->keyVar), ...self::getTargetLeaves($node->valueVar)],
            $node instanceof Catch_ => self::getTargetLeaves($node->var),
            default => [],
        };
    }

    /**
     * Get the targets a write assigns, such as variables and properties, through destructuring.
     *
     * @return list<Expr>
     */
    public static function getTargetLeaves(?Expr $target): array
    {
        if ($target === null) {
            return [];
        }

        if (! $target instanceof List_ && ! $target instanceof Array_) {
            return [$target];
        }

        $leaves = [];

        foreach ($target->items as $item) {
            $leaves = [...$leaves, ...self::getTargetLeaves($item?->value)];
        }

        return $leaves;
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

    /**
     * Get the type a call's receiver, or a static call's class expression, has in the try block, or null when it is unknown.
     *
     * The receiver is typed in the scope at the try's start, so it must not take a value the block may change
     * from there. A chain rooted at a local variable that the block only calls methods on keeps the variable's
     * type. One assignment, as a top-level statement before every call on the variable, gives it the assigned
     * value, so the chain is typed with that expression in the variable's place; a `??=` one keeps a value
     * that is always set and never null, without reading the expression it never assigns, and otherwise gives
     * it the non-null value it held or the assigned one. Any other write leaves it unknown. A call argument
     * that reads a local the block may change is typed as unknown, so a call that selects its result's class
     * by it, such as the container's make(), gives no class, while a fluent where() keeps its own. Any other
     * read of such a local leaves the receiver unknown.
     *
     * A `$this` property the block writes directly follows the same rules, and calls that change a property
     * aren't followed. A call on null fails or doesn't run, so null is left out of the type.
     *
     * @param array<Node> $statements
     */
    public function getReceiverType(Expr $receiver, array $statements, Scope $scope): ?Type
    {
        $root = $receiver;

        while ($root instanceof MethodCall || $root instanceof NullsafeMethodCall) {
            $root = $root->var;
        }

        $name = $root instanceof Variable && is_string($root->name) && $root->name !== 'this' ? $root->name : null;
        $property = $name === null ? self::getThisPropertyName($root) : null;

        $matches = static fn (?Node $node): bool => $name !== null
            ? $node instanceof Variable && $node->name === $name
            : $property !== null && self::getThisPropertyName($node) === $property;

        if ($name === null) {
            $written = $property !== null && $this->find(
                $statements,
                static fn (Node $node): bool => array_any(self::getWriteTargets($node), $matches),
            ) !== [];

            if (! $written) {
                $typed = $this->getEntryExpression($receiver, $statements);

                return $typed === null ? null : TypeCombinator::removeNull($scope->getType($typed));
            }

            // A hook can return or store another object than the one assigned.
            $class = $scope->getClassReflection();
            $reflection = $class !== null && $class->hasNativeProperty($property) ? $class->getNativeProperty($property) : null;

            if ($reflection === null
                || $reflection->isStatic()
                || ! $reflection->isVirtual()->no()
                || $reflection->hasHook('get')
                || $reflection->hasHook('set')) {
                return null;
            }
        }

        $nodes = $this->find(
            $statements,
            static fn (Node $node): bool => $matches($node)
                || $matches(self::getCalledReceiver($node))
                || (($node instanceof Assign || $node instanceof Coalesce) && $matches($node->var)),
        );

        $mentions = [];
        $calls = [];
        $assignments = [];

        foreach ($nodes as $node) {
            if ($matches($node)) {
                $mentions[] = $node;
            } elseif ($node instanceof Assign || $node instanceof Coalesce) {
                $assignments[] = $node;
            } else {
                $calls[] = $node;
            }
        }

        if (count($mentions) !== count($calls) + count($assignments)) {
            return null;
        }

        $assignment = $assignments[0] ?? null;

        if ($assignment !== null) {
            if (count($assignments) > 1
                || ! array_any($statements, static fn (Node $statement): bool => $statement instanceof Expression && $statement->expr === $assignment)) {
                return null;
            }

            foreach ($calls as $call) {
                if ($call->getStartFilePos() < $assignment->getEndFilePos()) {
                    return null;
                }
            }
        }

        $value = $assignment?->expr;

        if ($assignment instanceof Coalesce) {
            // A typed property may be uninitialized.
            $defined = $name !== null ? $scope->hasVariableType($name) : TrinaryLogic::createMaybe();

            // The assignment never runs when the variable always holds a value, so its expression is never read.
            if ($defined->yes() && $scope->getType($root)->isNull()->no()) {
                $value = null;
            } else {
                $assigned = $this->getEntryExpression($assignment->expr, $statements);

                if ($assigned === null) {
                    return null;
                }

                $value = new TypeExpr($defined->no()
                    ? $scope->getType($assigned)
                    : TypeCombinator::union(TypeCombinator::removeNull($scope->getType($root)), $scope->getType($assigned)));
            }
        }

        $typed = $this->getEntryExpression($receiver, $statements, $root, $value);

        return $typed === null ? null : TypeCombinator::removeNull($scope->getType($typed));
    }

    /**
     * Find the try's exact CanceledException catches that rethrow or keep the cancellation, each with the
     * later catch of every exception that handles cancellation carried by another exception as an ordinary
     * failure.
     *
     * @return list<array{Catch_, Catch_}>
     */
    public function findExactCancellationMisses(TryCatch $node, Scope $scope): array
    {
        $misses = [];

        foreach ($node->catches as $index => $catch) {
            if (! $this->passesCancellationOn($catch)) {
                continue;
            }

            $broadCatch = $this->findBroadCatch($node, $index);

            if ($broadCatch === null
                || $this->keepsCancellationApart($broadCatch)
                || $this->onlyWaitsNatively($node->stmts, $scope)) {
                continue;
            }

            $misses[] = [$catch, $broadCatch];
        }

        return $misses;
    }

    /**
     * Determine whether the catch takes CanceledException and rethrows it, or keeps it in another variable for later.
     */
    private function passesCancellationOn(Catch_ $catch): bool
    {
        if ($catch->var === null || ! is_string($catch->var->name)) {
            return false;
        }

        $catchesCancellation = false;

        foreach ($catch->types as $type) {
            $catchesCancellation = $catchesCancellation || $this->isCancellation($type);
        }

        $variable = $catch->var->name;

        return $catchesCancellation && $this->find(
            $catch->stmts,
            static fn (Node $node): bool => ($node instanceof Throw_ || $node instanceof Assign || $node instanceof Coalesce)
                && $node->expr instanceof Variable
                && $node->expr->name === $variable,
            $variable,
        ) !== [];
    }

    /**
     * Find the first catch after the given one that takes every exception.
     */
    private function findBroadCatch(TryCatch $node, int $index): ?Catch_
    {
        foreach ($node->catches as $laterIndex => $catch) {
            if ($laterIndex <= $index) {
                continue;
            }

            foreach ($catch->types as $type) {
                if (in_array($type->toLowerString(), ['throwable', 'exception'], true)) {
                    return $catch;
                }
            }
        }

        return null;
    }

    /**
     * Determine whether the catch only rethrows its exception, or handles cancellation behind a
     * Coroutine::causedByCancellation() guard that throws, that is the catch's only statement, or
     * that is negated and followed only by rethrowing the exception.
     */
    private function keepsCancellationApart(Catch_ $catch): bool
    {
        if ($catch->var === null || ! is_string($catch->var->name)) {
            return false;
        }

        $variable = $catch->var->name;
        $first = $catch->stmts[0] ?? null;

        if ($first instanceof Expression) {
            return count($catch->stmts) === 1 && $this->rethrows($first, $variable);
        }

        if (! $first instanceof If_) {
            return false;
        }

        $polarity = $this->guardPolarity($first->cond, $variable);

        return $polarity !== null
            && (count($catch->stmts) === 1
                || ($polarity && $this->endsWith($first->stmts, [Throw_::class]))
                || (! $polarity
                    && $first->elseifs === []
                    && $first->else === null
                    && count($catch->stmts) === 2
                    && $this->rethrows($catch->stmts[1], $variable)));
    }

    /**
     * Determine whether the statement throws the variable unchanged.
     */
    private function rethrows(Stmt $statement, string $variable): bool
    {
        return $statement instanceof Expression
            && $statement->expr instanceof Throw_
            && $statement->expr->expr instanceof Variable
            && $statement->expr->expr->name === $variable;
    }

    /**
     * Determine whether every call the try block makes is a native channel, WaitGroup, Waiter or join wait.
     *
     * @param array<Node> $statements
     */
    private function onlyWaitsNatively(array $statements, Scope $scope): bool
    {
        $calls = $this->find(
            $statements,
            static fn (Node $node): bool => $node instanceof CallLike && ! $node->isFirstClassCallable(),
        );

        foreach ($calls as $call) {
            $native = match (true) {
                $call instanceof MethodCall, $call instanceof NullsafeMethodCall => $call->name instanceof Identifier
                    && ($receiverType = $this->getReceiverType($call->var, $statements, $scope)) !== null
                    && $this->isNativeWait($receiverType->getObjectClassReflections(), $call->name->toString()),
                $call instanceof StaticCall => $call->class instanceof Name
                    && $call->name instanceof Identifier
                    && $this->isNativeWait($scope->resolveTypeByName($call->class)->getObjectClassReflections(), $call->name->toString()),
                $call instanceof New_ => $call->class instanceof Name
                    && isset(self::NATIVE_WAITS[$scope->resolveName($call->class)]),
                default => false,
            };

            if (! $native) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rebuild a call chain for typing in the scope at the try, with each call argument that reads a local the
     * block may change replaced by an unknown value. Return null when another part of it reads such a local.
     * The chain's root variable or property has been checked by the caller: it keeps its value from the try, or
     * takes the value the block assigns it. New nodes are built rather than cloned, since a clone keeps the
     * original's cached expression key.
     *
     * @param array<Node> $statements
     */
    private function getEntryExpression(Expr $expression, array $statements, ?Expr $root = null, ?Expr $value = null): ?Expr
    {
        if ($expression === $root) {
            return $value === null ? $root : $this->getEntryExpression($value, $statements);
        }

        if ($expression instanceof MethodCall || $expression instanceof NullsafeMethodCall) {
            $var = $this->getEntryExpression($expression->var, $statements, $root, $value);

            if ($var === null || ($expression->name instanceof Expr && $this->mayChangeInputs([$expression->name], $statements))) {
                return null;
            }

            $args = $this->generalizeArguments($expression->args, $statements);

            return $expression instanceof MethodCall
                ? new MethodCall($var, $expression->name, $args)
                : new NullsafeMethodCall($var, $expression->name, $args);
        }

        return match (true) {
            $expression instanceof StaticCall && $expression->class instanceof Name && $expression->name instanceof Identifier => new StaticCall($expression->class, $expression->name, $this->generalizeArguments($expression->args, $statements)),
            $expression instanceof New_ && $expression->class instanceof Name => new New_($expression->class, $this->generalizeArguments($expression->args, $statements)),
            $expression instanceof FuncCall && $expression->name instanceof Name => new FuncCall($expression->name, $this->generalizeArguments($expression->args, $statements)),
            $this->mayChangeInputs([$expression], $statements) => null,
            default => $expression,
        };
    }

    /**
     * Replace each argument that reads a local the block may change with an unknown value.
     *
     * @param array<Arg|VariadicPlaceholder> $args
     * @param array<Node> $statements
     * @return array<Arg|VariadicPlaceholder>
     */
    private function generalizeArguments(array $args, array $statements): array
    {
        foreach ($args as $index => $arg) {
            if ($arg instanceof Arg && $this->mayChangeInputs([$arg], $statements)) {
                $args[$index] = new Arg(new TypeExpr(new MixedType), $arg->byRef, $arg->unpack, [], $arg->name);
            }
        }

        return $args;
    }

    /**
     * Determine whether the block may change a local variable the nodes read: it mentions the variable
     * outside them, other than to call a method on it. A `$this` property they read changes when the block
     * writes it directly.
     *
     * @param array<Node> $nodes
     * @param array<Node> $statements
     */
    private function mayChangeInputs(array $nodes, array $statements): bool
    {
        $properties = [];

        foreach ($this->find($nodes, static fn (Node $node): bool => $node instanceof PropertyFetch) as $fetch) {
            $property = self::getThisPropertyName($fetch);

            if ($property !== null) {
                $properties[$property] = true;
            }
        }

        $writesProperty = static fn (Expr $target): bool => ($property = self::getThisPropertyName($target)) !== null && isset($properties[$property]);

        if ($properties !== [] && $this->find($statements, static fn (Node $node): bool => array_any(self::getWriteTargets($node), $writesProperty)) !== []) {
            return true;
        }

        $inputs = $this->find($nodes, static fn (Node $node): bool => $node instanceof Variable && is_string($node->name) && $node->name !== 'this');

        foreach ($inputs as $input) {
            $name = $input instanceof Variable ? $input->name : null;
            $receivers = [];
            $mentions = [];
            $matches = static fn (?Node $node): bool => $node instanceof Variable && $node->name === $name;

            $nodes = $this->find(
                $statements,
                static fn (Node $node): bool => $matches($node) || $matches(self::getCalledReceiver($node)),
            );

            foreach ($nodes as $node) {
                if ($matches($node)) {
                    $mentions[] = $node;
                } else {
                    $receivers[] = self::getCalledReceiver($node);
                }
            }

            foreach ($mentions as $mention) {
                if (! in_array($mention, $inputs, true) && ! in_array($mention, $receivers, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the expression a method or static call is made on.
     */
    private static function getCalledReceiver(Node $node): ?Node
    {
        return match (true) {
            $node instanceof MethodCall, $node instanceof NullsafeMethodCall => $node->var,
            $node instanceof StaticCall => $node->class,
            default => null,
        };
    }

    /**
     * Get the name of the `$this` property the node reads or writes, or null for any other node.
     */
    private static function getThisPropertyName(?Node $node): ?string
    {
        return $node instanceof PropertyFetch
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
                ? $node->name->toString()
                : null;
    }

    /**
     * Determine whether the method, on every class it may be called on, is declared as a native wait.
     *
     * @param list<ClassReflection> $classReflections
     */
    private function isNativeWait(array $classReflections, string $method): bool
    {
        foreach ($classReflections as $classReflection) {
            if (! $classReflection->hasNativeMethod($method)) {
                return false;
            }

            $declaringClass = $classReflection->getNativeMethod($method)->getDeclaringClass()->getName();

            if (! in_array(strtolower($method), self::NATIVE_WAITS[$declaringClass] ?? [], true)) {
                return false;
            }
        }

        return $classReflections !== [];
    }
}
