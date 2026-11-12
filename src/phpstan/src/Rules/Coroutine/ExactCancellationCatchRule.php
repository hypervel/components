<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Coalesce;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\TryCatch;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function array_any;
use function count;
use function in_array;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * Reports an exact CanceledException catch backed by a later catch of every exception.
 *
 * A cleanup or callback failure can carry cancellation as its previous exception. The exact
 * catch misses it, so the later catch handles the canceled operation as an ordinary failure.
 *
 * @implements Rule<TryCatch>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkCancellationHandling%')]
class ExactCancellationCatchRule implements Rule
{
    /**
     * The native wait methods, keyed by declaring class, that throw cancellation directly and so may keep an exact catch.
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
     * Create a new exact cancellation catch rule instance.
     */
    public function __construct(
        private CoroutineRuleHelper $coroutineRuleHelper,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return TryCatch::class;
    }

    /**
     * Report exact cancellation catches that rethrow or keep the cancellation, followed by a catch of every exception.
     *
     * @param TryCatch $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

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

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Catching CanceledException misses cancellation carried by another exception, which the catch on line %d handles as an ordinary failure. Check Coroutine::causedByCancellation() in that catch instead.',
                $broadCatch->getStartLine(),
            ))
                ->line($catch->getStartLine())
                ->identifier('hypervel.cancellation.exactType')
                ->addTip('A cleanup or callback failure in the try block can carry the cancellation as its previous exception.')
                ->build();
        }

        return $errors;
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
            $catchesCancellation = $catchesCancellation || $this->coroutineRuleHelper->isCancellation($type);
        }

        $variable = $catch->var->name;

        return $catchesCancellation && $this->coroutineRuleHelper->find(
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
     * Coroutine::causedByCancellation() guard that throws or that is the catch's only statement.
     */
    private function keepsCancellationApart(Catch_ $catch): bool
    {
        if ($catch->var === null || ! is_string($catch->var->name)) {
            return false;
        }

        $variable = $catch->var->name;
        $first = $catch->stmts[0] ?? null;

        if ($first instanceof Expression) {
            return count($catch->stmts) === 1
                && $first->expr instanceof Throw_
                && $first->expr->expr instanceof Variable
                && $first->expr->expr->name === $variable;
        }

        if (! $first instanceof If_) {
            return false;
        }

        $polarity = $this->coroutineRuleHelper->guardPolarity($first->cond, $variable);

        return $polarity !== null
            && (count($catch->stmts) === 1
                || ($polarity && $this->coroutineRuleHelper->endsWith($first->stmts, [Throw_::class])));
    }

    /**
     * Determine whether every call the try block makes is a native channel, WaitGroup, Waiter or join wait.
     *
     * @param array<Node> $statements
     */
    private function onlyWaitsNatively(array $statements, Scope $scope): bool
    {
        $calls = $this->coroutineRuleHelper->find(
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
     * Get the type a receiver has in the try block, or null when it is unknown.
     *
     * The type at the try's start holds for a local variable that the block only calls methods on. One
     * construction, as a top-level statement before every call, gives it the constructed class; any other
     * write leaves it unknown.
     *
     * @param array<Node> $statements
     */
    private function getReceiverType(Expr $receiver, array $statements, Scope $scope): ?Type
    {
        if (! $receiver instanceof Variable || ! is_string($name = $receiver->name)) {
            return $scope->getType($receiver);
        }

        $nodes = $this->coroutineRuleHelper->find(
            $statements,
            static fn (Node $node): bool => ($node instanceof Variable && $node->name === $name)
                || (($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof Assign)
                    && $node->var instanceof Variable
                    && $node->var->name === $name),
        );

        $mentions = [];
        $calls = [];
        $assignments = [];

        foreach ($nodes as $node) {
            if ($node instanceof Variable) {
                $mentions[] = $node;
            } elseif ($node instanceof Assign) {
                $assignments[] = $node;
            } else {
                $calls[] = $node;
            }
        }

        if (count($mentions) !== count($calls) + count($assignments)) {
            return null;
        }

        if ($assignments === []) {
            return $scope->getType($receiver);
        }

        $assignment = $assignments[0];
        $construction = $assignment->expr;

        if (count($assignments) > 1
            || ! $construction instanceof New_
            || ! $construction->class instanceof Name
            || ! array_any($statements, static fn (Node $statement): bool => $statement instanceof Expression && $statement->expr === $assignment)) {
            return null;
        }

        foreach ($calls as $call) {
            if ($call->getStartFilePos() < $assignment->getEndFilePos()) {
                return null;
            }
        }

        return new ObjectType($scope->resolveName($construction->class));
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
