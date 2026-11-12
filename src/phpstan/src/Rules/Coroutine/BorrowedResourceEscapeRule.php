<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Concurrency\CoroutineDriver;
use Hypervel\Concurrency\ProcessDriver;
use Hypervel\Concurrency\SyncDriver;
use Hypervel\Contracts\Concurrency\Driver;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Parallel;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Coroutine\Waiter;
use Hypervel\Database\Connection;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Filesystem\ClientPooledFilesystem;
use Hypervel\Filesystem\FilesystemPoolProxy;
use Hypervel\PHPStan\Concerns\InspectsConnections;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\Queue\QueuePoolProxy;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Concurrency;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Redis;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\StaticVar;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use Swoole\Coroutine as SwooleCoroutine;

use function array_diff_key;
use function array_intersect_key;
use function array_map;
use function array_values;
use function class_basename;
use function in_array;
use function is_string;
use function spl_object_id;
use function sprintf;
use function str_starts_with;
use function strtolower;

/**
 * Reports a pooled resource that escapes the callback it is lent to.
 *
 * The pool lends the resource to other coroutines once the callback returns, and a pinned database
 * session can return to its pool once its pin ends, so anything still holding them afterwards may
 * act on another coroutine's work. Each callback is read as written: the lent parameter, its direct
 * local aliases and the raw handles taken from them are followed, but not values passed through calls.
 *
 * @implements Rule<CallLike>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkBorrowedResources%')]
class BorrowedResourceEscapeRule implements Rule
{
    use InspectsConnections;

    /**
     * The lowercase names of the lending methods below.
     */
    private const array LENDING_METHODS = [
        'withadapter' => true,
        'withclient' => true,
        'withconnection' => true,
        'withdriver' => true,
        'withpinnedsession' => true,
    ];

    /**
     * The resource each lending method lends its callback, by declaring class and lowercase method.
     */
    private const array LENDERS = [
        RedisProxy::class => ['withconnection' => 'redis'],
        Redis::class => ['withconnection' => 'redis'],
        StoreContext::class => ['withconnection' => 'redis'],
        QueuePoolProxy::class => ['withconnection' => 'sqs'],
        // The classes using InteractsWithPooledFilesystem; an unpooled disk lends its own driver.
        FilesystemPoolProxy::class => ['withdriver' => 'driver', 'withadapter' => 'adapter', 'withclient' => 'client'],
        ClientPooledFilesystem::class => ['withdriver' => 'driver', 'withadapter' => 'adapter', 'withclient' => 'client'],
        Connection::class => ['withpinnedsession' => 'session'],
        DB::class => ['withpinnedsession' => 'session'],
    ];

    /**
     * Each resource's name, and the names of the raw handles its lowercase methods take from it.
     *
     * A pinned session lends nothing, but keeps the PDOs taken from its connection on the pinned session.
     */
    private const array RESOURCES = [
        'redis' => ['Redis connection', ['client' => 'Redis client']],
        'sqs' => ['SQS queue', ['getsqs' => 'SQS client']],
        'driver' => ['Flysystem driver', []],
        'adapter' => ['Flysystem adapter', []],
        'client' => ['filesystem client', []],
        'session' => [null, ['getpdo' => 'PDO', 'getreadpdo' => 'read PDO']],
    ];

    /**
     * The functions that run their callbacks in a coroutine or later, so the callbacks can outlive the caller.
     */
    private const array SPAWNING_FUNCTIONS = [
        'defer' => true,
        'hypervel\coroutine\co' => true,
        'hypervel\coroutine\go' => true,
        'hypervel\coroutine\parallel' => true,
        'hypervel\coroutine\wait' => true,
        'hypervel\support\defer' => true,
        'swoole\coroutine\defer' => true,
        'swoole\coroutine\go' => true,
    ];

    /**
     * The lowercase methods that run their callbacks in a coroutine or later, by exact declaring class.
     *
     * Parallel cancels its children with its owner but does not join them, so a child whose cleanup
     * yields can still run after the callback returns. The Concurrency driver is configuration, so
     * only an explicit sync driver's run() keeps its tasks in place.
     */
    private const array SPAWNING_METHODS = [
        Coroutine::class => ['create', 'createowned', 'fork', 'forkowned', 'defer'],
        EngineCoroutine::class => ['create', 'defer'],
        SwooleCoroutine::class => ['create', 'defer'],
        Parallel::class => ['add'],
        Waiter::class => ['wait'],
        Concurrent::class => ['create', 'fork'],
        WaitConcurrent::class => ['create', 'fork'],
        Concurrency::class => ['run', 'defer'],
        Driver::class => ['run', 'defer'],
        CoroutineDriver::class => ['run', 'defer'],
        ProcessDriver::class => ['run', 'defer'],
        SyncDriver::class => ['defer'],
        Timer::class => ['after', 'tick', 'until'],
    ];

    /**
     * Create a new borrowed resource escape rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
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
     * Report the escapes from a lending method's callback.
     *
     * @param CallLike $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // PHPStan also analyzes a nullsafe call as a method call.
        if (! ($node instanceof MethodCall || $node instanceof StaticCall)
            || ! $node->name instanceof Identifier
            || ! isset(self::LENDING_METHODS[$node->name->toLowerString()])) {
            return [];
        }

        $callback = $node->getArg('callback', 0)?->value;
        $lender = $this->findLender($node, $node->name->toString(), $scope);

        if ((! $callback instanceof Closure && ! $callback instanceof ArrowFunction) || $lender === null) {
            return [];
        }

        [$method, $resource] = $lender;
        $owners = [];

        if ($resource === 'session') {
            $owner = $this->getOwner($node);

            if ($owner === null) {
                return [];
            }

            $tracked = [];
            $owners = $this->getCaptured($callback, [$owner => true]);
        } else {
            $parameter = $callback->params[0]->var ?? null;

            if (! $parameter instanceof Variable || ! is_string($parameter->name)) {
                return [];
            }

            $tracked = [$parameter->name => 'lent'];
        }

        $errors = [];

        $this->analyzeFunction($callback, ['method' => $method, 'resource' => $resource], $tracked, $owners, true, $scope, $errors);

        return array_values($errors);
    }

    /**
     * Find the lending method the call runs and the resource it lends, or null when it is not one.
     *
     * @return null|array{string, string}
     */
    private function findLender(MethodCall|StaticCall $call, string $name, Scope $scope): ?array
    {
        $classNames = match (true) {
            $call instanceof MethodCall => $scope->getType($call->var)->getObjectClassNames(),
            $call->class instanceof Name => [$scope->resolveName($call->class)],
            default => [],
        };

        $lender = null;

        foreach ($classNames as $className) {
            if (! $this->reflectionProvider->hasClass($className) || ! $this->reflectionProvider->getClass($className)->hasMethod($name)) {
                return null;
            }

            // A user override or another implementation is judged by its own declaration.
            $method = $this->reflectionProvider->getClass($className)->getMethod($name, $scope);
            $resource = self::LENDERS[$method->getDeclaringClass()->getName()][strtolower($name)] ?? null;

            if ($resource === null || ($lender !== null && $lender[1] !== $resource)) {
                return null;
            }

            $lender = [$method->getName(), $resource];
        }

        return $lender;
    }

    /**
     * Get the variable holding the pinned connection, or the DB facade class for its default connection.
     */
    private function getOwner(MethodCall|StaticCall $call): ?string
    {
        if ($call instanceof StaticCall) {
            return $call->class instanceof Name && ReflectionHelper::isFacade($call->class, DB::class, 'DB') ? DB::class : null;
        }

        return $call->var instanceof Variable && is_string($call->var->name) ? $call->var->name : null;
    }

    /**
     * Report the escapes in the callback, or in a closure nested in it.
     *
     * Only the callback's own returns and by-reference variables leave it; a nested closure returns
     * to its caller inside the callback.
     *
     * @param array{method: string, resource: string} $borrow
     * @param array<string, string> $tracked the borrowed variables the function receives: "lent", or the name of the raw handle they hold
     * @param array<string, true> $owners the variables holding the pinned connection the function receives, or the DB facade
     * @param array<string, IdentifierRuleError> $errors
     */
    private function analyzeFunction(
        Closure|ArrowFunction $function,
        array $borrow,
        array $tracked,
        array $owners,
        bool $isCallback,
        Scope $scope,
        array &$errors,
    ): void {
        $nodes = $this->getOwnNodes($function instanceof Closure ? $function->stmts : [$function->expr]);
        [$writes, $closures] = $this->collectWrites($nodes);

        // A reassigned variable no longer holds the pinned connection.
        $owners = array_diff_key($owners, $writes);
        $tracked = $this->followAliases($tracked, $writes, $owners, $borrow['resource'], $scope);
        $locals = $writes + $this->getParameterNames($function);

        // The variables the callback receives unchanged from the lending call's scope, which knows their types:
        // a closure's `use` variables, or every outer variable for an arrow function (null). A nested function's are unknown.
        $outer = match (true) {
            ! $isCallback => [],
            $function instanceof Closure => array_diff_key($this->getUseNames($function), $locals),
            default => null,
        };

        $byReference = [];

        if ($isCallback && $function instanceof Closure) {
            foreach ($function->uses as $use) {
                if ($use->byRef && is_string($use->var->name)) {
                    $byReference[$use->var->name] = true;
                }
            }
        }

        $spawned = [];

        foreach ($nodes as $node) {
            $how = null;
            $kind = null;

            if ($node instanceof Return_ && $isCallback && $node->expr !== null) {
                $how = 'is returned';
                $kind = $this->getBorrowedKind($node->expr, $tracked, $owners, $borrow['resource'], $scope);
            } elseif ($node instanceof Assign || $node instanceof AssignRef) {
                $how = $this->getStore($node->var, $byReference);
                $kind = $how === null ? null : $this->getBorrowedKind($node->expr, $tracked, $owners, $borrow['resource'], $scope);
            } elseif ($node instanceof CallLike) {
                $spawn = $this->getSpawn($node, $locals, $writes, $outer, $scope);
                $how = $spawn === null ? null : sprintf('is captured by a closure passed to %s()', $spawn);

                foreach ($spawn === null ? [] : $this->getPassedClosures($node, $closures) as $closure) {
                    $spawned[spl_object_id($closure)] = true;
                    $kind ??= $this->getCapturedKind($closure, $tracked);
                }
            }

            if ($how !== null && $kind !== null) {
                $this->addError($errors, $node, $borrow, $kind, $how);
            }
        }

        if ($isCallback && $function instanceof ArrowFunction) {
            $kind = $this->getBorrowedKind($function->expr, $tracked, $owners, $borrow['resource'], $scope);

            if ($kind !== null) {
                $this->addError($errors, $function->expr, $borrow, $kind, 'is returned');
            }
        }

        foreach ($nodes as $node) {
            if (($node instanceof Closure || $node instanceof ArrowFunction) && ! isset($spawned[spl_object_id($node)])) {
                $this->analyzeFunction($node, $borrow, $this->getCaptured($node, $tracked), $this->getCaptured($node, $owners), false, $scope, $errors);
            }
        }
    }

    /**
     * Get the nodes of a function's own body: nested functions are included but not entered, and classes are skipped.
     *
     * @param array<Node> $nodes
     *
     * @return list<Node>
     */
    private function getOwnNodes(array $nodes): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Node> */
            public array $found = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
                }

                $this->found[] = $node;

                return $node instanceof Closure || $node instanceof ArrowFunction ? NodeVisitor::DONT_TRAVERSE_CHILDREN : null;
            }
        };

        (new NodeTraverser($visitor))->traverse($nodes);

        return $visitor->found;
    }

    /**
     * Collect the values each variable is assigned, null for a write whose value is not followed, and the
     * closures each variable or its elements are assigned.
     *
     * @param list<Node> $nodes
     *
     * @return array{array<string, non-empty-list<?Expr>>, array<string, list<ArrowFunction|Closure>>}
     */
    private function collectWrites(array $nodes): array
    {
        $writes = [];
        $closures = [];

        foreach ($nodes as $node) {
            $targets = match (true) {
                $node instanceof Assign && ($node->var instanceof List_ || $node->var instanceof Array_) => [$node->var],
                $node instanceof AssignOp => [$node->var],
                $node instanceof Foreach_ => [$node->keyVar, $node->valueVar],
                $node instanceof Catch_ => [$node->var],
                $node instanceof Static_ => array_map(static fn (StaticVar $variable): Variable => $variable->var, $node->vars),
                $node instanceof Global_ => $node->vars,
                default => [],
            };

            foreach ($targets as $target) {
                foreach ($this->getTargetNames($target) as $name) {
                    $writes[$name][] = null;
                }
            }

            if (! $node instanceof Assign && ! $node instanceof AssignRef) {
                continue;
            }

            if ($node->var instanceof Variable && is_string($node->var->name)) {
                $writes[$node->var->name][] = $node->expr;
            }

            $root = $node->var;

            while ($root instanceof ArrayDimFetch) {
                $root = $root->var;
            }

            if ($root instanceof Variable && is_string($root->name) && ($node->expr instanceof Closure || $node->expr instanceof ArrowFunction)) {
                $closures[$root->name][] = $node->expr;
            }
        }

        return [$writes, $closures];
    }

    /**
     * Get the names of the variables a write target assigns, through destructuring.
     *
     * @return list<string>
     */
    private function getTargetNames(?Expr $target): array
    {
        if ($target instanceof Variable) {
            return is_string($target->name) ? [$target->name] : [];
        }

        if (! $target instanceof List_ && ! $target instanceof Array_) {
            return [];
        }

        $names = [];

        foreach ($target->items as $item) {
            $names = [...$names, ...$this->getTargetNames($item?->value)];
        }

        return $names;
    }

    /**
     * Add the local variables assigned only borrowed values to the borrowed variables.
     *
     * A received variable stays borrowed only while every assignment in the function keeps it borrowed,
     * so the result does not depend on the order of the statements.
     *
     * @param array<string, string> $tracked
     * @param array<string, non-empty-list<?Expr>> $writes
     * @param array<string, true> $owners
     *
     * @return array<string, string>
     */
    private function followAliases(array $tracked, array $writes, array $owners, string $resource, Scope $scope): array
    {
        $received = $tracked;
        $tracked = array_diff_key($received, $writes);

        do {
            $added = false;

            foreach ($writes as $name => $values) {
                if (isset($tracked[$name])) {
                    continue;
                }

                $kind = $received[$name] ?? null;

                foreach ($values as $value) {
                    $valueKind = $value === null ? null : $this->getBorrowedKind($value, $tracked, $owners, $resource, $scope);

                    if ($valueKind === null) {
                        continue 2;
                    }

                    $kind ??= $valueKind;
                }

                $tracked[$name] = $kind;
                $added = true;
            }
        } while ($added);

        return $tracked;
    }

    /**
     * Get what the expression's value is: "lent" for the lent resource, the name of a raw handle, or null.
     *
     * @param array<string, string> $tracked
     * @param array<string, true> $owners
     */
    private function getBorrowedKind(Expr $expr, array $tracked, array $owners, string $resource, Scope $scope): ?string
    {
        if ($expr instanceof Assign || $expr instanceof AssignRef) {
            return $this->getBorrowedKind($expr->expr, $tracked, $owners, $resource, $scope);
        }

        if ($expr instanceof Variable) {
            return is_string($expr->name) ? $tracked[$expr->name] ?? null : null;
        }

        if (! ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall)
            || ! $expr->name instanceof Identifier
            || $expr->isFirstClassCallable()) {
            return null;
        }

        $handle = self::RESOURCES[$resource][1][$expr->name->toLowerString()] ?? null;

        if ($handle === null) {
            return null;
        }

        if ($resource !== 'session') {
            return ! $expr instanceof StaticCall && $this->getBorrowedKind($expr->var, $tracked, $owners, $resource, $scope) === 'lent' ? $handle : null;
        }

        // A pinned session's PDOs come from the pinned connection itself, through the framework's own getters.
        $ownerType = match (true) {
            $expr instanceof StaticCall => isset($owners[DB::class]) && $expr->class instanceof Name && ReflectionHelper::isFacade($expr->class, DB::class, 'DB')
                ? new ObjectType($scope->resolveName($expr->class))
                : null,
            $expr->var instanceof Variable && is_string($expr->var->name) && isset($owners[$expr->var->name]) => $scope->getType($expr->var),
            default => null,
        };

        return $ownerType !== null && $this->runsConnectionMethod($ownerType, $expr->name->toString(), $scope) ? $handle : null;
    }

    /**
     * Get how an assignment to the target keeps its value beyond the callback, or null when it stays local.
     *
     * @param array<string, true> $byReference the callback's by-reference variables
     */
    private function getStore(Expr $target, array $byReference): ?string
    {
        while ($target instanceof ArrayDimFetch || $target instanceof PropertyFetch) {
            if ($target instanceof PropertyFetch && $target->var instanceof Variable && $target->var->name === 'this') {
                return 'is stored on a property';
            }

            $target = $target->var;
        }

        return match (true) {
            $target instanceof StaticPropertyFetch => 'is stored on a property',
            $target instanceof Variable && is_string($target->name) && isset($byReference[$target->name]) => 'is stored in a by-reference variable',
            default => null,
        };
    }

    /**
     * Get the name of the function or method the call makes when it runs closures that can outlive the callback.
     *
     * @param array<string, mixed> $locals
     * @param array<string, non-empty-list<?Expr>> $writes
     * @param null|array<string, true> $outer
     */
    private function getSpawn(CallLike $call, array $locals, array $writes, ?array $outer, Scope $scope): ?string
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        if ($call instanceof FuncCall) {
            if (! $call->name instanceof Name || ! $this->reflectionProvider->hasFunction($call->name, $scope)) {
                return null;
            }

            $function = strtolower($this->reflectionProvider->getFunction($call->name, $scope)->getName());

            return isset(self::SPAWNING_FUNCTIONS[$function]) && ($function !== 'hypervel\coroutine\wait' || ! $this->waitsForChild($call, $scope))
                ? $call->name->getLast()
                : null;
        }

        if (! ($call instanceof MethodCall || $call instanceof NullsafeMethodCall || $call instanceof StaticCall) || ! $call->name instanceof Identifier) {
            return null;
        }

        $name = $call->name->toString();
        $classNames = match (true) {
            ! $call instanceof StaticCall => $this->getReceiverClassNames($call->var, $locals, $writes, $outer, $scope),
            $call->class instanceof Name => [$scope->resolveName($call->class)],
            default => [],
        };

        foreach ($classNames as $className) {
            if (! $this->reflectionProvider->hasClass($className) || ! $this->reflectionProvider->getClass($className)->hasMethod($name)) {
                continue;
            }

            // An override runs its own body, so only the framework's declarations count.
            $method = $this->reflectionProvider->getClass($className)->getMethod($name, $scope);
            $declaringClass = $method->getDeclaringClass()->getName();
            $lowerName = strtolower($name);

            if (! in_array($lowerName, self::SPAWNING_METHODS[$declaringClass] ?? [], true)
                || ($declaringClass === Waiter::class && $this->waitsForChild($call, $scope))) {
                continue;
            }

            return class_basename($declaringClass) . '::' . $method->getName();
        }

        return null;
    }

    /**
     * Determine whether a wait() call waits for its child to terminate on every exit, which keeps the child inside the callback.
     *
     * The option must be a constant expression: the lending call's scope does not know the callback's own variables.
     */
    private function waitsForChild(CallLike $call, Scope $scope): bool
    {
        $argument = $call->getArg('waitForChildTermination', 3)?->value;

        return $argument !== null
            && (new NodeFinder)->findFirstInstanceOf($argument, Variable::class) === null
            && $scope->getType($argument)->isTrue()->yes();
    }

    /**
     * Get the classes a method call's receiver may be, when the callback shows them.
     *
     * A local variable assigned only `new` expressions is one of those classes. An expression using no
     * other variables than $this and those the callback receives unchanged has the type the lending
     * call's scope gives it.
     *
     * @param array<string, mixed> $locals the function's parameters and assigned variables
     * @param array<string, non-empty-list<?Expr>> $writes
     * @param null|array<string, true> $outer the variables the function receives unchanged from the lending call's scope, or null for all of them
     *
     * @return list<string>
     */
    private function getReceiverClassNames(Expr $receiver, array $locals, array $writes, ?array $outer, Scope $scope): array
    {
        if ($receiver instanceof Variable && is_string($receiver->name) && isset($writes[$receiver->name])) {
            $classNames = [];

            foreach ($writes[$receiver->name] as $value) {
                if (! $value instanceof New_ || ! $value->class instanceof Name) {
                    return [];
                }

                $classNames[] = $scope->resolveName($value->class);
            }

            return $classNames;
        }

        foreach ((new NodeFinder)->findInstanceOf($receiver, Variable::class) as $variable) {
            if ($variable->name !== 'this'
                && (! is_string($variable->name) || isset($locals[$variable->name]) || ($outer !== null && ! isset($outer[$variable->name])))) {
                return [];
            }
        }

        return $scope->getType($receiver)->getObjectClassNames();
    }

    /**
     * Get the closures a spawning call is passed: directly, in an array, or through a variable assigned them.
     *
     * @param array<string, list<ArrowFunction|Closure>> $closures
     *
     * @return list<ArrowFunction|Closure>
     */
    private function getPassedClosures(CallLike $call, array $closures): array
    {
        $passed = [];

        foreach ($call->getArgs() as $argument) {
            $values = [$argument->value];

            if ($argument->value instanceof Array_) {
                $values = array_map(static fn (ArrayItem $item): Expr => $item->value, $argument->value->items);
            }

            foreach ($values as $value) {
                if ($value instanceof Closure || $value instanceof ArrowFunction) {
                    $passed[] = $value;
                } elseif ($value instanceof Variable && is_string($value->name)) {
                    $passed = [...$passed, ...$closures[$value->name] ?? []];
                }
            }
        }

        return $passed;
    }

    /**
     * Get what the first borrowed variable a closure captures holds, or null when it captures none.
     *
     * @param array<string, string> $tracked
     */
    private function getCapturedKind(Closure|ArrowFunction $closure, array $tracked): ?string
    {
        $captured = $this->getCaptured($closure, $tracked);

        // An arrow function captures only the outer variables it uses.
        if ($closure instanceof ArrowFunction) {
            $used = [];

            foreach ((new NodeFinder)->findInstanceOf($closure->expr, Variable::class) as $variable) {
                if (is_string($variable->name)) {
                    $used[$variable->name] = true;
                }
            }

            $captured = array_intersect_key($captured, $used);
        }

        foreach ($captured as $kind) {
            return $kind;
        }

        return null;
    }

    /**
     * Get the entries for the variables a nested function receives from its enclosing one: a closure's
     * `use` variables and $this, or an arrow function's variables other than its parameters. The DB
     * facade is reachable everywhere.
     *
     * @template TValue
     *
     * @param array<string, TValue> $variables
     *
     * @return array<string, TValue>
     */
    private function getCaptured(Closure|ArrowFunction $function, array $variables): array
    {
        $received = $function instanceof Closure ? $this->getUseNames($function) : null;
        $parameters = $this->getParameterNames($function);
        $captured = [];

        foreach ($variables as $name => $value) {
            $receives = match (true) {
                $name === DB::class => true,
                $name === 'this' => ! $function->static,
                $received !== null => isset($received[$name]),
                default => ! isset($parameters[$name]),
            };

            if ($receives) {
                $captured[$name] = $value;
            }
        }

        return $captured;
    }

    /**
     * Get a closure's `use` variable names.
     *
     * @return array<string, true>
     */
    private function getUseNames(Closure $closure): array
    {
        $names = [];

        foreach ($closure->uses as $use) {
            if (is_string($use->var->name)) {
                $names[$use->var->name] = true;
            }
        }

        return $names;
    }

    /**
     * Get a function's parameter names.
     *
     * @return array<string, true>
     */
    private function getParameterNames(Closure|ArrowFunction $function): array
    {
        $names = [];

        foreach ($function->params as $parameter) {
            if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $names[$parameter->var->name] = true;
            }
        }

        return $names;
    }

    /**
     * Add the error for a borrowed value escaping, once per line and message.
     *
     * @param array<string, IdentifierRuleError> $errors
     * @param array{method: string, resource: string} $borrow
     */
    private function addError(array &$errors, Node $node, array $borrow, string $kind, string $how): void
    {
        $lent = self::RESOURCES[$borrow['resource']][0];

        $subject = match (true) {
            $lent === null => sprintf('The %s taken inside %s()', $kind, $borrow['method']),
            $kind === 'lent' => sprintf('The %s borrowed by %s()', $lent, $borrow['method']),
            default => sprintf('The %s of the %s borrowed by %s()', $kind, $lent, $borrow['method']),
        };

        $end = $lent === null ? 'pin' : 'borrow';
        $message = sprintf('%s %s, so it can be used after the %s ends.', $subject, $how, $end);
        $tip = sprintf('Finish using it inside the callback, which holds the %s.', $end);

        if (str_starts_with($how, 'is captured')) {
            $tip .= ' A child coroutine that must use it can run through wait() with waitForChildTermination: true.';
        }

        $errors[$node->getStartLine() . $message] ??= RuleErrorBuilder::message($message)
            ->line($node->getStartLine())
            ->identifier('hypervel.borrowedResourceEscape')
            ->addTip($tip)
            ->build();
    }
}
