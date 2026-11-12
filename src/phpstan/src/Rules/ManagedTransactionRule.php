<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\ConnectionInterface;
use Hypervel\PHPStan\Concerns\InspectsConnections;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
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
use function array_diff_key;
use function is_string;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * Reports a transaction() callback that ends the transaction it runs in.
 *
 * transaction() begins its transaction before the callback and commits or rolls it back afterwards,
 * so a commit() or rollBack() that ends that level leaves the callback's remaining work outside the
 * transaction, and transaction() then throws because the level is gone. The callback's top-level
 * statements are walked in order, counting the transactions it begins itself, and the walk goes
 * silent at anything it cannot follow.
 *
 * @implements Rule<CallLike>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkManagedTransactions%')]
class ManagedTransactionRule implements Rule
{
    use InspectsConnections;

    /**
     * The lowercase names of the methods that control a connection's transactions.
     */
    private const array CONTROL_METHODS = ['begintransaction' => true, 'commit' => true, 'rollback' => true];

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Report the calls in a transaction() callback that end the managed transaction.
     *
     * @param CallLike $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // PHPStan also analyzes a nullsafe call as a method call.
        if (! $node instanceof MethodCall && ! $node instanceof StaticCall) {
            return [];
        }

        $ownerType = $this->getTransactionReceiverType($node, $scope);
        $callback = $node->getArg('callback', 0)?->value;

        if ($ownerType === null || (! $callback instanceof Closure && ! $callback instanceof ArrowFunction)) {
            return [];
        }

        // transaction() passes the connection itself to the callback, so its first parameter is always the owner.
        $parameter = $callback->params[0]->var ?? null;
        $owners = $parameter instanceof Variable && is_string($parameter->name) ? [$parameter->name => true] : [];
        $owner = $this->getOwnerKey($node, $callback, $owners, $scope);
        $statements = $callback instanceof Closure ? $callback->stmts : [new Return_($callback->expr)];
        $depth = 0;
        $errors = [];

        foreach ($statements as $statement) {
            [$calls, $conditional, $written] = $this->inspect($statement);

            // A branch, loop or try makes the depth after it unknown.
            if ($calls !== [] && ($conditional || (! $statement instanceof Expression && ! $statement instanceof Return_))) {
                break;
            }

            foreach ($calls as $call) {
                $match = $this->matchOwner($call, $owner, $owners, $ownerType, $callback, $scope);

                if ($match === null) {
                    continue;
                }

                $method = $call->name instanceof Identifier ? $call->name->toLowerString() : '';
                $level = $method === 'rollback' ? $this->getRollBackLevel($call) : null;

                // Another connection may be this one, and other levels depend on the level the callback starts at.
                if (! $match || array_any($call->getArgs(), static fn (Arg $argument): bool => $argument->unpack) || $level === false) {
                    break 2;
                }

                if ($method === 'begintransaction') {
                    ++$depth;
                } elseif ($level === 0) {
                    $errors[] = $this->buildError($call, 'rollBack(0) rolls back the transaction that transaction() manages, with every enclosing one.');

                    break 2;
                } elseif ($depth > 0) {
                    --$depth;
                } else {
                    $errors[] = $this->buildError($call, sprintf('%s() ends the transaction that transaction() manages before its callback returns.', $call->name instanceof Identifier ? $call->name->toString() : $method));
                }
            }

            $owners = array_diff_key($owners, $written);
            $owner = $owner !== null && str_starts_with($owner, '$') && isset($written[substr($owner, 1)]) ? null : $owner;

            if ($statement instanceof Return_ || ($statement instanceof Expression && $statement->expr instanceof Throw_)) {
                break;
            }
        }

        return $errors;
    }

    /**
     * Get the key that identifies the transaction's connection inside the callback: the DB facade, a
     * literal DB::connection() name, or a variable the callback receives; null when it is unknown.
     *
     * @param array<string, true> $owners
     */
    private function getOwnerKey(MethodCall|StaticCall $call, Closure|ArrowFunction $callback, array $owners, Scope $scope): ?string
    {
        if ($call instanceof StaticCall) {
            return DB::class;
        }

        $receiver = $call->var;

        if ($receiver instanceof Variable && is_string($receiver->name) && ! isset($owners[$receiver->name])) {
            return $this->receives($callback, $receiver->name) ? '$' . $receiver->name : null;
        }

        return $this->getConnectionName($receiver, $scope);
    }

    /**
     * Determine whether the callback sees the variable from the scope it is created in.
     */
    private function receives(Closure|ArrowFunction $callback, string $name): bool
    {
        if ($name === 'this') {
            return ! $callback->static;
        }

        if ($callback instanceof ArrowFunction) {
            return ! array_any($callback->params, static fn (Param $parameter): bool => $parameter->var instanceof Variable && $parameter->var->name === $name);
        }

        return array_any($callback->uses, static fn (ClosureUse $use): bool => $use->var->name === $name);
    }

    /**
     * Collect a statement's transaction-control calls in order, whether any of them runs conditionally,
     * and the variables it assigns. Nested functions and classes are skipped.
     *
     * @return array{list<MethodCall|NullsafeMethodCall|StaticCall>, bool, array<string, true>}
     */
    private function inspect(Stmt $statement): array
    {
        $visitor = new class(self::CONTROL_METHODS) extends NodeVisitorAbstract {
            /** @var list<MethodCall|NullsafeMethodCall|StaticCall> */
            public array $calls = [];

            public bool $conditional = false;

            /** @var array<string, true> */
            public array $written = [];

            private int $conditions = 0;

            /**
             * @param array<string, true> $controlMethods
             */
            public function __construct(
                private array $controlMethods,
            ) {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
                }

                if ($this->isConditional($node)) {
                    ++$this->conditions;
                }

                if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall)
                    && $node->name instanceof Identifier
                    && isset($this->controlMethods[$node->name->toLowerString()])
                    && ! $node->isFirstClassCallable()) {
                    $this->calls[] = $node;
                    $this->conditional = $this->conditional || $this->conditions > 0;
                }

                $targets = match (true) {
                    $node instanceof Assign, $node instanceof AssignRef, $node instanceof AssignOp => [$node->var],
                    $node instanceof Foreach_ => [$node->keyVar, $node->valueVar],
                    default => [],
                };

                foreach ($targets as $target) {
                    if ($target instanceof Variable && is_string($target->name)) {
                        $this->written[$target->name] = true;
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($this->isConditional($node)) {
                    --$this->conditions;
                }

                return null;
            }

            private function isConditional(Node $node): bool
            {
                return $node instanceof Ternary
                    || $node instanceof Match_
                    || $node instanceof BooleanAnd
                    || $node instanceof BooleanOr
                    || $node instanceof LogicalAnd
                    || $node instanceof LogicalOr
                    || $node instanceof Coalesce
                    || $node instanceof AssignOp\Coalesce;
            }
        };

        (new NodeTraverser($visitor))->traverse([$statement]);

        return [$visitor->calls, $visitor->conditional, $visitor->written];
    }

    /**
     * Determine whether a control call runs the framework's control method on the managed transaction's
     * connection (true), may act on another connection or run an override (false), or is not made on a
     * connection (null).
     *
     * @param array<string, true> $owners the callback's variables known to hold the transaction's connection
     * @param Type $ownerType the type of the connection or facade transaction() is called on
     */
    private function matchOwner(MethodCall|NullsafeMethodCall|StaticCall $call, ?string $owner, array $owners, Type $ownerType, Closure|ArrowFunction $callback, Scope $scope): ?bool
    {
        // The inspection collects only calls named by an identifier.
        $method = $call->name instanceof Identifier ? $call->name->toString() : '';

        if ($call instanceof StaticCall) {
            if (! $call->class instanceof Name || ! ReflectionHelper::isFacade($call->class, DB::class, 'DB')) {
                return null;
            }

            return $owner === DB::class && $this->runsConnectionMethod(new ObjectType($scope->resolveName($call->class)), $method, $scope);
        }

        $receiver = $call->var;

        if ($receiver instanceof Variable && is_string($receiver->name)) {
            if (isset($owners[$receiver->name]) || $owner === '$' . $receiver->name) {
                return $this->runsConnectionMethod($ownerType, $method, $scope);
            }

            // A variable the callback receives unchanged has the type it has where the callback is created.
            if (! $this->receives($callback, $receiver->name)) {
                return false;
            }
        } elseif (($name = $this->getConnectionName($receiver, $scope)) !== null) {
            return $owner === $name && $this->runsConnectionMethod($scope->getType($receiver), $method, $scope);
        }

        // A receiver of known classes, none of them a connection, is something else; an unknown one may be a connection.
        $classes = $scope->getType($receiver)->getObjectClassReflections();

        return $classes !== [] && ! array_any($classes, static fn (ClassReflection $class): bool => $class->is(ConnectionInterface::class))
            ? null
            : false;
    }

    /**
     * Get the level a rollBack() call rolls back to: null for the default, 0, or false for any other or unknown level.
     */
    private function getRollBackLevel(MethodCall|NullsafeMethodCall|StaticCall $call): false|int|null
    {
        $level = $call->getArg('toLevel', 0)?->value;

        return match (true) {
            $level === null, $level instanceof ConstFetch && $level->name->toLowerString() === 'null' => null,
            $level instanceof Int_ && $level->value === 0 => 0,
            default => false,
        };
    }

    /**
     * Build the error for a call that ends the managed transaction.
     */
    private function buildError(CallLike $call, string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->line($call->getStartLine())
            ->identifier('hypervel.transactionEndedEarly')
            ->tip('Let transaction() commit when the callback returns, or throw an exception to roll it back.')
            ->build();
    }
}
