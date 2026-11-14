<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Hypervel\Concurrency\ConcurrencyManager;
use Hypervel\Concurrency\CoroutineDriver;
use Hypervel\Concurrency\SyncDriver;
use Hypervel\Contracts\Concurrency\Driver;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Parallel;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Coroutine\Waiter;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\PHPStan\ReturnTypes\ConcurrencyDriverHelper;
use Hypervel\PHPStan\Support\CallStack;
use Hypervel\Support\Facades\Concurrency;
use Hypervel\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use Swoole\Coroutine as SwooleCoroutine;

use function array_map;
use function in_array;
use function strtolower;

/**
 * Reports child coroutines started inside a database transaction callback.
 *
 * A child coroutine borrows its own pooled connection, so its queries run outside the
 * transaction the parent coroutine owns.
 *
 * @implements Rule<CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%noCoroutinesInTransactions%')]
class NoCoroutinesInTransactionsRule implements Rule
{
    /**
     * The lowercase short names of the functions and methods below.
     */
    private const array SPAWNING_NAMES = [
        'co' => true,
        'create' => true,
        'createowned' => true,
        'fork' => true,
        'forkowned' => true,
        'go' => true,
        'parallel' => true,
        'run' => true,
        'wait' => true,
    ];

    /**
     * The functions that start child coroutines.
     */
    private const array SPAWNING_FUNCTIONS = [
        'hypervel\coroutine\co',
        'hypervel\coroutine\go',
        'hypervel\coroutine\parallel',
        'hypervel\coroutine\wait',
    ];

    /**
     * The lowercase methods that start child coroutines, by declaring class.
     */
    private const array SPAWNING_METHODS = [
        Coroutine::class => ['create', 'createowned', 'fork', 'forkowned'],
        EngineCoroutine::class => ['create'],
        SwooleCoroutine::class => ['create'],
        Parallel::class => ['wait'],
        Concurrent::class => ['create', 'fork'],
        WaitConcurrent::class => ['create', 'fork'],
        Waiter::class => ['wait'],
        CoroutineDriver::class => ['run'],
    ];

    /**
     * The names of the task-list parameters that spawning functions and methods take.
     */
    private const array TASK_PARAMETERS = ['callables' => true, 'tasks' => true];

    /**
     * Create a new coroutines in transactions rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private ConcurrencyDriverHelper $concurrencyDriverHelper,
        private CoroutineRuleHelper $coroutineRuleHelper,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Report coroutine-starting calls that run inside a transaction callback.
     *
     * @param CallLike $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // PHPStan also analyzes a nullsafe call as a method call.
        if ($node instanceof NullsafeMethodCall) {
            return [];
        }

        // Every call reaches this rule, so cheap name and call-stack checks come before reflection.
        $name = $this->getCalledName($node);

        if ((! isset(self::SPAWNING_NAMES[$name]) && $name !== 'aftercommit') || ! $this->runsInTransaction($scope)) {
            return [];
        }

        $reflection = $this->getCalledReflection($node, $scope);

        if ($reflection === null) {
            return [];
        }

        $spawns = [];

        if ($this->callStartsCoroutine($node, $reflection, $scope)) {
            $spawns[] = $node;
        } elseif (! $this->runsTasksElsewhere($reflection, $scope)
            && ($name !== 'aftercommit' || ! $reflection instanceof MethodReflection || ! $this->isDatabaseClass($reflection))) {
            return [];
        }

        // The arguments run now, in the transaction's coroutine, before the child starts, the tasks run or the
        // callback is deferred. Calls in them find this call on their stack, so they are checked here instead.
        $argumentCalls = $this->coroutineRuleHelper->find(
            $node->getArgs(),
            fn (Node $node): bool => $node instanceof CallLike
                && ! $node->isFirstClassCallable()
                && isset(self::SPAWNING_NAMES[$this->getCalledName($node)]),
        );

        foreach ($argumentCalls as $argumentCall) {
            /** @var CallLike $argumentCall */
            $argumentReflection = $this->getCalledReflection($argumentCall, $scope);

            if ($argumentReflection !== null && $this->callStartsCoroutine($argumentCall, $argumentReflection, $scope)) {
                $spawns[] = $argumentCall;
            }
        }

        return array_map(
            static fn (CallLike $spawn): IdentifierRuleError => RuleErrorBuilder::message('A coroutine started inside a database transaction runs outside it: the child borrows its own pooled connection.')
                ->line($spawn->getStartLine())
                ->identifier('hypervel.coroutineInTransaction')
                ->addTip('Start the coroutine after the transaction commits, or run the work in the transaction\'s own coroutine.')
                ->build(),
            $spawns,
        );
    }

    /**
     * Get the lowercase short name of the function or method the call makes, or an empty string.
     */
    private function getCalledName(CallLike $node): string
    {
        return strtolower(match (true) {
            $node instanceof FuncCall && $node->name instanceof Name => $node->name->getLast(),
            ($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall)
                && $node->name instanceof Identifier => $node->name->toString(),
            default => '',
        });
    }

    /**
     * Get the function or method the call makes, when it is known.
     */
    private function getCalledReflection(CallLike $node, Scope $scope): FunctionReflection|MethodReflection|null
    {
        if ($node instanceof FuncCall) {
            return $node->name instanceof Name && $this->reflectionProvider->hasFunction($node->name, $scope)
                ? $this->reflectionProvider->getFunction($node->name, $scope)
                : null;
        }

        if (! ($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall)
            || ! $node->name instanceof Identifier) {
            return null;
        }

        if ($node instanceof StaticCall) {
            if (! $node->class instanceof Name) {
                return null;
            }

            $type = new ObjectType($scope->resolveName($node->class));
        } else {
            $type = $scope->getType($node->var);
        }

        return $scope->getMethodReflection($type, $node->name->toString());
    }

    /**
     * Determine whether the call starts a child coroutine.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function callStartsCoroutine(CallLike $node, FunctionReflection|MethodReflection $reflection, Scope $scope): bool
    {
        return ! $this->passesNoTasks($node, $scope) && $this->startsCoroutine($reflection, $scope);
    }

    /**
     * Determine whether the call passes a task list known to be empty, which starts no coroutine.
     */
    private function passesNoTasks(CallLike $node, Scope $scope): bool
    {
        $tasks = null;

        foreach ($node->getArgs() as $index => $argument) {
            if ($argument->name === null ? $index === 0 : isset(self::TASK_PARAMETERS[$argument->name->toString()])) {
                $tasks = $argument;
            }
        }

        if ($tasks === null || $tasks->unpack) {
            return false;
        }

        $type = $scope->getType($tasks->value);

        return $type->isArray()->yes() && $type->isIterableAtLeastOnce()->no();
    }

    /**
     * Determine whether the function or method starts a child coroutine. The Concurrency facade's and
     * manager's run() start one only when the default instance is known to use the coroutine driver.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function startsCoroutine(FunctionReflection|MethodReflection $reflection, Scope $scope): bool
    {
        if ($reflection instanceof FunctionReflection) {
            return in_array(strtolower($reflection->getName()), self::SPAWNING_FUNCTIONS, true);
        }

        $declaringClass = $reflection->getDeclaringClass();
        $method = strtolower($reflection->getName());

        if ($method === 'run' && ($declaringClass->is(Concurrency::class) || $declaringClass->is(ConcurrencyManager::class))) {
            return $this->concurrencyDriverHelper->getDriverClass(null, $scope) === CoroutineDriver::class;
        }

        foreach (self::SPAWNING_METHODS as $class => $methods) {
            if ($declaringClass->is($class) && in_array($method, $methods, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the code runs in a transaction callback in its own coroutine.
     *
     * The innermost relevant call decides: an after-commit callback may run once the transaction
     * commits, a child coroutine's own calls belong to the spawn reported around them, and tasks of
     * a Concurrency run() not known to stay in place may run anywhere. A call whose arguments are
     * being evaluated is on the stack too, so processNode() checks those itself.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function runsInTransaction(Scope $scope): bool
    {
        foreach (CallStack::enclosing($scope) as $reflection) {
            if ($this->startsCoroutine($reflection, $scope) || $this->runsTasksElsewhere($reflection, $scope)) {
                return false;
            }

            if (! $reflection instanceof MethodReflection || ! $this->isDatabaseClass($reflection)) {
                continue;
            }

            $method = strtolower($reflection->getName());

            if ($method === 'aftercommit') {
                return false;
            }

            if ($method === 'transaction') {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the call is a Concurrency driver's run() that is not known to run its tasks in the
     * calling coroutine. Only the sync driver runs them in place; a coroutine driver's run() starts children.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function runsTasksElsewhere(FunctionReflection|MethodReflection $reflection, Scope $scope): bool
    {
        if (! $reflection instanceof MethodReflection || strtolower($reflection->getName()) !== 'run') {
            return false;
        }

        $declaringClass = $reflection->getDeclaringClass();

        if ($declaringClass->is(Concurrency::class) || $declaringClass->is(ConcurrencyManager::class)) {
            return $this->concurrencyDriverHelper->getDriverClass(null, $scope) !== SyncDriver::class;
        }

        return $declaringClass->is(Driver::class) && ! $declaringClass->is(SyncDriver::class);
    }

    /**
     * Determine whether the method belongs to a database connection, the manager or the DB facade.
     */
    private function isDatabaseClass(MethodReflection $method): bool
    {
        $class = $method->getDeclaringClass();

        return $class->is(ConnectionInterface::class) || $class->is(DatabaseManager::class) || $class->is(DB::class);
    }
}
