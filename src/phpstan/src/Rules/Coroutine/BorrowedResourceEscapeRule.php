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
use PhpParser\Node\Stmt\Expression;
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
use function array_filter;
use function array_intersect_key;
use function array_keys;
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
 * act on another coroutine's work. Each callback is read as written, in the order of its top-level
 * statements: the lent parameter, the raw handles taken from it, and the local variables, arrays and
 * closures holding them are followed, but not values passed through calls.
 *
 * @phpstan-type HeldClosure array{ArrowFunction|Closure, ?string, array<int, array<string, true>>}
 * @phpstan-type Environment array{tracked: array<string, string>, appended: array<string, string>, callables: array<string, array<int, HeldClosure>>, defined: array<string, true>, referenced: array<string, true>}
 * @phpstan-type Fact array{tracked: ?string, appended: ?string, callables: array<int, HeldClosure>}
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
            $owner = $this->getOwner($node, $scope);

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

        // A closure callback receives its `use` variables from outside; an arrow function every variable it reads.
        $received = $callback instanceof Closure ? $this->getUseNames($callback) : null;
        $environment = [
            'tracked' => $tracked,
            'appended' => [],
            'callables' => [],
            'defined' => ($received ?? $this->getCapturedNames($callback)) + $this->getParameterNames($callback),
            'referenced' => [],
        ];

        $this->analyzeFunction(
            $callback,
            ['method' => $method, 'resource' => $resource],
            $environment,
            $owners,
            $received,
            $callback instanceof Closure ? $this->getReferenceNames($callback) : [],
            true,
            $scope,
            $errors,
        );

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
     * Get the variable or `$this` property holding the pinned connection, or the DB facade class for its
     * default connection.
     */
    private function getOwner(MethodCall|StaticCall $call, Scope $scope): ?string
    {
        if ($call instanceof StaticCall) {
            return $call->class instanceof Name && ReflectionHelper::isFacade($call->class, DB::class, 'DB') ? DB::class : null;
        }

        return $call->var instanceof Variable && is_string($call->var->name) ? $call->var->name : $this->getPropertyKey($call->var, $scope);
    }

    /**
     * Report the escapes in the callback, or in a closure nested in it.
     *
     * Only the callback's own returns and by-reference variables leave it; a nested closure returns
     * to its caller inside the callback. The top-level statements are walked in order. A statement that
     * is exactly `$variable = value;` replaces what the variable holds once its value has been checked.
     * Any other statement's writes may run in any order, any number of times or not at all, so they are
     * applied conservatively before its escapes are checked.
     *
     * @param array{method: string, resource: string} $borrow
     * @param Environment $environment what the function receives: each borrowed variable's value ("lent", or the name of the raw handle), what its appended arrays hold, the closures each variable may hold, and the variables already defined
     * @param array<string, true> $owners the variables or `$this` properties holding the pinned connection the function receives, or the DB facade
     * @param null|array<string, true> $received the variables the function receives from outside the callback, or null for every variable it doesn't own
     * @param array<string, true> $byReference the variables the function shares by reference with the code calling the lending method
     * @param array<string, IdentifierRuleError> $errors
     */
    private function analyzeFunction(
        Closure|ArrowFunction $function,
        array $borrow,
        array $environment,
        array $owners,
        ?array $received,
        array $byReference,
        bool $isCallback,
        Scope $scope,
        array &$errors,
    ): void {
        $statements = $function instanceof Closure ? $function->stmts : [$function->expr];
        [$writes, $propertyWrites] = $this->collectWrites($this->getOwnNodes($statements));

        // A reassigned variable or property no longer holds the pinned connection.
        $owners = array_diff_key($owners, $writes, $propertyWrites);
        $locals = $writes + $this->getParameterNames($function);
        $resource = $borrow['resource'];

        // The variables still holding what they received from outside the callback. An object they hold
        // outlives the callback, so a property written through one keeps its value.
        $external = $received === null ? null : array_diff_key($received, $locals);

        // The callback's external variables are unchanged from the lending call's scope, which knows their
        // types. A nested function's are unknown.
        $outer = $isCallback ? $external : [];

        // Each statement's own nodes, and the last statement writing each variable.
        $ownNodes = [];
        $lastWritten = [];

        foreach ($statements as $index => $statement) {
            $ownNodes[$index] = $this->getOwnNodes([$statement]);

            foreach (array_keys($this->collectWrites($ownNodes[$index])[0]) as $name) {
                $lastWritten[$name] = $index;
            }
        }

        // Each nested function with the environment it captured and the statement that created it, and whether each
        // closure passed to a spawn was reported there.
        $created = [];
        $spawned = [];

        foreach ($statements as $index => $statement) {
            $nodes = $ownNodes[$index];

            $reset = $statement instanceof Expression
                && $statement->expr instanceof Assign
                && $statement->expr->var instanceof Variable
                && is_string($statement->expr->var->name)
                    ? [$statement->expr, $statement->expr->var->name]
                    : null;

            $this->applyWrites($nodes, $reset[0] ?? null, $environment, $owners, $resource, $scope);

            foreach ($nodes as $node) {
                $how = null;
                $kind = null;

                if ($node instanceof Return_ && $isCallback && $node->expr !== null) {
                    $how = 'is returned';
                    $kind = $this->getBorrowedKind($node->expr, $environment, $owners, $resource, $scope);
                } elseif ($node instanceof Assign || $node instanceof AssignRef) {
                    $how = $this->getStore($node->var, $byReference, $external, $locals);
                    $kind = $how === null ? null : $this->getBorrowedKind($node->expr, $environment, $owners, $resource, $scope);
                } elseif ($node instanceof CallLike) {
                    $spawn = $this->getSpawn($node, $locals, $writes, $outer, $scope);
                    $how = $spawn === null ? null : sprintf('is captured by a closure passed to %s()', $spawn);

                    foreach ($spawn === null ? [] : $this->getPassedClosures($node, $environment) as $held) {
                        $heldKind = $held[1] ?? $this->getLiveKind($held, $environment);
                        $id = spl_object_id($held[0]);

                        $spawned[$id] = ($spawned[$id] ?? false) || $heldKind !== null;
                        $kind ??= $heldKind;
                    }
                } elseif ($node instanceof Closure || $node instanceof ArrowFunction) {
                    $created[spl_object_id($node)] = [$node, [
                        'tracked' => $this->getCaptured($node, $environment['tracked']),
                        'appended' => $this->getCaptured($node, $environment['appended']),
                        'callables' => $this->getCarriedCallables($node, $environment, $lastWritten, $index),
                        'defined' => $this->getCaptured($node, $environment['defined']) + $this->getParameterNames($node),
                        'referenced' => [],
                    ], $index];
                }

                if ($how !== null && $kind !== null) {
                    $this->addError($errors, $node, $borrow, $kind, $how);
                }
            }

            if ($reset !== null) {
                $this->setFact($environment, $reset[1], $this->getFact($reset[0]->expr, $environment, $owners, $resource, $scope));
            }

            // A reference binding lets a later write through either side change both, so neither value is known
            // after this statement. Its own escapes, such as a by-reference store, are checked above first.
            foreach ($nodes as $node) {
                foreach ($node instanceof AssignRef ? $this->getReferenceRoots($node) : [] as $name) {
                    $this->setFact($environment, $name, ['tracked' => null, 'appended' => null, 'callables' => []]);
                    $environment['referenced'][$name] = true;
                }
            }
        }

        if ($isCallback && $function instanceof ArrowFunction) {
            $kind = $this->getBorrowedKind($function->expr, $environment, $owners, $resource, $scope);

            if ($kind !== null) {
                $this->addError($errors, $function->expr, $borrow, $kind, 'is returned');
            }
        }

        foreach ($created as $id => [$node, $capturedEnvironment, $index]) {
            if ($spawned[$id] ?? false) {
                continue;
            }

            $nestedOwners = $this->getCaptured($node, $owners);

            // A spawned closure runs in a child coroutine, which resolves its own default connection because copied
            // contexts leave connections out, or in a deferred callback after the pin ends. A connection object it
            // captures is still the pinned one.
            if (isset($spawned[$id])) {
                unset($nestedOwners[DB::class]);
            }

            // A variable captured by reference holds what it was last given when the nested function runs, so
            // a write in the statement that created the function, or after it, leaves its value unknown.
            if ($node instanceof Closure) {
                $rewritten = array_filter(
                    $this->getReferenceNames($node),
                    static fn (string $name): bool => isset($lastWritten[$name]) && $lastWritten[$name] >= $index,
                    ARRAY_FILTER_USE_KEY,
                );

                $capturedEnvironment['tracked'] = array_diff_key($capturedEnvironment['tracked'], $rewritten);
                $capturedEnvironment['appended'] = array_diff_key($capturedEnvironment['appended'], $rewritten);
                $capturedEnvironment['callables'] = array_diff_key($capturedEnvironment['callables'], $rewritten);
            }

            // A nested function takes from outside the callback only what this function itself took from there,
            // and shares a by-reference variable with that code only through its own by-reference `use`.
            $captured = $this->getCapturedNames($node);

            $this->analyzeFunction(
                $node,
                $borrow,
                $capturedEnvironment,
                $nestedOwners,
                $external === null ? array_diff_key($captured, $locals) : array_intersect_key($captured, $external),
                $node instanceof Closure ? array_intersect_key($this->getReferenceNames($node), $byReference) : [],
                false,
                $scope,
                $errors,
            );
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
     * `$this` properties written.
     *
     * @param list<Node> $nodes
     *
     * @return array{array<string, non-empty-list<?Expr>>, array<string, true>}
     */
    private function collectWrites(array $nodes): array
    {
        $writes = [];
        $propertyWrites = [];

        foreach ($nodes as $node) {
            foreach ($this->getUnknownTargets($node) as $leaf) {
                if ($leaf instanceof Variable && is_string($leaf->name)) {
                    $writes[$leaf->name][] = null;
                } elseif (($property = $this->getPropertyWriteKey($leaf)) !== null) {
                    $propertyWrites[$property] = true;
                }
            }

            if (! $node instanceof Assign && ! $node instanceof AssignRef) {
                continue;
            }

            if (($property = $this->getPropertyWriteKey($node->var)) !== null) {
                $propertyWrites[$property] = true;
            }

            if ($node->var instanceof Variable && is_string($node->var->name)) {
                $writes[$node->var->name][] = $node->expr;
            }

            // A later write through either side of a reference binding changes the other, so both are written.
            if ($node instanceof AssignRef) {
                foreach ($this->getReferenceRoots($node) as $name) {
                    $writes[$name][] = null;
                }

                if (($property = $this->getPropertyWriteKey($node->expr)) !== null) {
                    $propertyWrites[$property] = true;
                }
            }
        }

        return [$writes, $propertyWrites];
    }

    /**
     * Get the variables a reference binding ties together: the root variable of each side, such as `$items`
     * for `$items[0]` or `$holder` for `$holder->value`.
     *
     * @return list<string>
     */
    private function getReferenceRoots(AssignRef $node): array
    {
        $names = [];

        foreach ([$node->var, $node->expr] as $side) {
            while ($side instanceof ArrayDimFetch || $side instanceof PropertyFetch) {
                $side = $side->var;
            }

            if ($side instanceof Variable && is_string($side->name) && $side->name !== 'this') {
                $names[] = $side->name;
            }
        }

        return $names;
    }

    /**
     * Get the targets a node writes without a value the rule follows: destructuring, compound assignments,
     * foreach, catch, static and global variables.
     *
     * @return list<Expr>
     */
    private function getUnknownTargets(Node $node): array
    {
        $targets = match (true) {
            $node instanceof Assign && ($node->var instanceof List_ || $node->var instanceof Array_) => [$node->var],
            $node instanceof AssignOp => [$node->var],
            $node instanceof Foreach_ => [$node->keyVar, $node->valueVar],
            $node instanceof Catch_ => [$node->var],
            $node instanceof Static_ => array_map(static fn (StaticVar $variable): Variable => $variable->var, $node->vars),
            $node instanceof Global_ => $node->vars,
            default => [],
        };

        $leaves = [];

        foreach ($targets as $target) {
            $leaves = [...$leaves, ...CoroutineRuleHelper::getTargetLeaves($target)];
        }

        return $leaves;
    }

    /**
     * Apply a statement's writes, other than its reset, to the environment.
     *
     * They may run in any order, any number of times or not at all. A defined variable stays borrowed only
     * when its old and new values both are, an array keeps what was appended to it until a reset, and a
     * variable may hold any closure assigned to it. Repeated passes follow alias chains, including those
     * a loop carries into its next iteration.
     *
     * @param list<Node> $nodes
     * @param Environment $environment
     * @param array<string, true> $owners
     */
    private function applyWrites(array $nodes, ?Assign $reset, array &$environment, array $owners, string $resource, Scope $scope): void
    {
        $writes = [];

        foreach ($nodes as $node) {
            foreach ($this->getUnknownTargets($node) as $leaf) {
                if ($leaf instanceof Variable && is_string($leaf->name)) {
                    $writes[] = [$leaf->name, null, false];
                }
            }

            if ((! $node instanceof Assign && ! $node instanceof AssignRef) || $node === $reset) {
                continue;
            }

            $root = $node->var;

            while ($root instanceof ArrayDimFetch) {
                $root = $root->var;
            }

            if ($root instanceof Variable && is_string($root->name) && $root->name !== 'this') {
                $writes[] = [$root->name, $node->expr, $root !== $node->var];
            }
        }

        if ($writes === []) {
            return;
        }

        do {
            $previous = $environment;

            foreach ($writes as [$name, $value, $appends]) {
                $fact = $value === null
                    ? ['tracked' => null, 'appended' => null, 'callables' => []]
                    : $this->getFact($value, $environment, $owners, $resource, $scope);

                if (! isset($environment['defined'][$name])) {
                    $this->setFact($environment, $name, $appends ? ['tracked' => null, 'appended' => $fact['tracked'] ?? $fact['appended'], 'callables' => $fact['callables']] : $fact);

                    continue;
                }

                if ($appends) {
                    $fact = ['tracked' => $environment['tracked'][$name] ?? null, 'appended' => $fact['tracked'] ?? $fact['appended'], 'callables' => $fact['callables']];
                } elseif ($fact['tracked'] === null || ! isset($environment['tracked'][$name])) {
                    $fact['tracked'] = null;
                } else {
                    $fact['tracked'] = $environment['tracked'][$name];
                }

                $fact['appended'] = $environment['appended'][$name] ?? $fact['appended'];
                $fact['callables'] = ($environment['callables'][$name] ?? []) + $fact['callables'];

                $this->setFact($environment, $name, $fact);
            }
        } while ($environment !== $previous);
    }

    /**
     * Get what assigning the expression gives a variable: the borrowed value it is, what it holds as an array
     * built by appending, and the closures it is or holds.
     *
     * @param Environment $environment
     * @param array<string, true> $owners
     *
     * @return Fact
     */
    private function getFact(Expr $expr, array $environment, array $owners, string $resource, Scope $scope): array
    {
        if ($expr instanceof Assign || $expr instanceof AssignRef) {
            return $this->getFact($expr->expr, $environment, $owners, $resource, $scope);
        }

        if ($expr instanceof Variable) {
            return [
                'tracked' => is_string($expr->name) ? $environment['tracked'][$expr->name] ?? null : null,
                'appended' => is_string($expr->name) ? $environment['appended'][$expr->name] ?? null : null,
                'callables' => is_string($expr->name) ? $environment['callables'][$expr->name] ?? [] : [],
            ];
        }

        if ($expr instanceof Closure || $expr instanceof ArrowFunction) {
            return ['tracked' => null, 'appended' => null, 'callables' => [spl_object_id($expr) => $this->getHeldClosure($expr, $environment)]];
        }

        if ($expr instanceof Array_) {
            $fact = ['tracked' => null, 'appended' => null, 'callables' => []];

            foreach ($expr->items as $item) {
                $itemFact = $this->getFact($item->value, $environment, $owners, $resource, $scope);
                $fact['tracked'] ??= $itemFact['tracked'] ?? $itemFact['appended'];
                $fact['callables'] += $itemFact['callables'];
            }

            return $fact;
        }

        return ['tracked' => $this->getBorrowedKind($expr, $environment, $owners, $resource, $scope), 'appended' => null, 'callables' => []];
    }

    /**
     * Replace what the variable holds with the fact. A variable bound by reference stays unknown.
     *
     * @param Environment $environment
     * @param Fact $fact
     */
    private function setFact(array &$environment, string $name, array $fact): void
    {
        $environment['defined'][$name] = true;

        if (isset($environment['referenced'][$name])) {
            return;
        }

        if ($fact['tracked'] === null) {
            unset($environment['tracked'][$name]);
        } else {
            $environment['tracked'][$name] = $fact['tracked'];
        }

        if ($fact['appended'] === null) {
            unset($environment['appended'][$name]);
        } else {
            $environment['appended'][$name] = $fact['appended'];
        }

        if ($fact['callables'] === []) {
            unset($environment['callables'][$name]);
        } else {
            $environment['callables'][$name] = $fact['callables'];
        }
    }

    /**
     * Get what the expression's value is or holds now: "lent" for the lent resource, the name of a raw
     * handle, or null. An array holds what its items hold, and a closure what it captures.
     *
     * @param Environment $environment
     * @param array<string, true> $owners
     */
    private function getBorrowedKind(Expr $expr, array $environment, array $owners, string $resource, Scope $scope): ?string
    {
        if ($expr instanceof Assign || $expr instanceof AssignRef) {
            return $this->getBorrowedKind($expr->expr, $environment, $owners, $resource, $scope);
        }

        if ($expr instanceof Variable) {
            return is_string($expr->name) ? $this->getFirstKind([$expr->name => true], $environment) : null;
        }

        if ($expr instanceof Closure || $expr instanceof ArrowFunction) {
            $held = $this->getHeldClosure($expr, $environment);

            return $held[1] ?? $this->getLiveKind($held, $environment);
        }

        if ($expr instanceof Array_) {
            foreach ($expr->items as $item) {
                $kind = $this->getBorrowedKind($item->value, $environment, $owners, $resource, $scope);

                if ($kind !== null) {
                    return $kind;
                }
            }

            return null;
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
            return ! $expr instanceof StaticCall && $this->getBorrowedKind($expr->var, $environment, $owners, $resource, $scope) === 'lent' ? $handle : null;
        }

        // A pinned session's PDOs come from the pinned connection itself, through the framework's own getters.
        $ownerType = match (true) {
            $expr instanceof StaticCall => isset($owners[DB::class]) && $expr->class instanceof Name && ReflectionHelper::isFacade($expr->class, DB::class, 'DB')
                ? new ObjectType($scope->resolveName($expr->class))
                : null,
            $expr->var instanceof Variable && is_string($expr->var->name) && isset($owners[$expr->var->name]) => $scope->getType($expr->var),
            ($property = $this->getPropertyWriteKey($expr->var)) !== null && isset($owners[$property]) => $scope->getType($expr->var),
            default => null,
        };

        return $ownerType !== null && $this->runsConnectionMethod($ownerType, $expr->name->toString(), $scope) ? $handle : null;
    }

    /**
     * Get how an assignment to the target keeps its value beyond the callback, or null when it stays local.
     *
     * A property of $this, or of an object a variable holds from outside the callback, outlives it.
     *
     * @param array<string, true> $byReference the callback's by-reference variables
     * @param null|array<string, true> $external the variables holding what they received from outside the callback, or null for every one the function doesn't own
     * @param array<string, mixed> $locals the function's parameters and assigned variables
     */
    private function getStore(Expr $target, array $byReference, ?array $external, array $locals): ?string
    {
        while ($target instanceof ArrayDimFetch || $target instanceof PropertyFetch) {
            if ($target instanceof PropertyFetch
                && $target->var instanceof Variable
                && is_string($name = $target->var->name)
                && ($name === 'this' || ($external === null ? ! isset($locals[$name]) : isset($external[$name])))) {
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
     * Get the closures a spawning call is passed: directly, in an array, or through a variable that may hold them.
     *
     * @param Environment $environment
     *
     * @return list<HeldClosure>
     */
    private function getPassedClosures(CallLike $call, array $environment): array
    {
        $passed = [];

        foreach ($call->getArgs() as $argument) {
            $values = [$argument->value];

            if ($argument->value instanceof Array_) {
                $values = array_map(static fn (ArrayItem $item): Expr => $item->value, $argument->value->items);
            }

            foreach ($values as $value) {
                if ($value instanceof Closure || $value instanceof ArrowFunction) {
                    $passed[] = $this->getHeldClosure($value, $environment);
                } elseif ($value instanceof Variable && is_string($value->name)) {
                    $passed = [...$passed, ...array_values($environment['callables'][$value->name] ?? [])];
                }
            }
        }

        return $passed;
    }

    /**
     * Get the entry for a closure created now: the closure, what the first borrowed variable it captures by value
     * holds, which it keeps for as long as it lives, and the by-reference variables it reads when it runs, keyed
     * by the closure capturing them: its own and those of the closures it captures by value, even once their
     * variables are replaced.
     *
     * @param Environment $environment
     *
     * @return HeldClosure
     */
    private function getHeldClosure(Closure|ArrowFunction $closure, array $environment): array
    {
        $names = $this->getCapturedNames($closure);
        $live = [];

        if ($closure instanceof Closure) {
            $references = $this->getReferenceNames($closure);
            $names = array_diff_key($names, $references);
            $live[spl_object_id($closure)] = $references;
        }

        foreach (array_intersect_key($environment['callables'], $names) as $closures) {
            foreach ($closures as [, , $captured]) {
                foreach ($captured as $id => $references) {
                    $live[$id] = ($live[$id] ?? []) + $references;
                }
            }
        }

        return [$closure, $this->getFirstKind($names, $environment, live: false), $live];
    }

    /**
     * Get the closures a nested function created now captures.
     *
     * The nested function runs later without this function's variables, so what a captured closure reads by
     * reference from them is read now, leaving out the variables this function writes in the creating statement
     * or after it, which may hold anything by then. A variable the nested function shares by reference is the
     * same binding, which it can change itself, so only those variables are read from its own facts when it runs.
     *
     * @param Environment $environment
     * @param array<string, int> $lastWritten the last statement writing each variable
     *
     * @return array<string, array<int, HeldClosure>>
     */
    private function getCarriedCallables(Closure|ArrowFunction $function, array $environment, array $lastWritten, int $index): array
    {
        $shared = $function instanceof Closure ? $this->getReferenceNames($function) : [];
        $unread = $shared + array_filter($lastWritten, static fn (int $last): bool => $last >= $index);

        $now = [
            'tracked' => array_diff_key($environment['tracked'], $unread),
            'appended' => array_diff_key($environment['appended'], $unread),
            'callables' => array_diff_key($environment['callables'], $unread),
        ] + $environment;

        return array_map(
            fn (array $closures): array => array_map(
                fn (array $held): array => [
                    $held[0],
                    $held[1] ?? $this->getLiveKind($held, $now),
                    array_filter(array_map(static fn (array $references): array => array_intersect_key($references, $shared), $held[2])),
                ],
                $closures,
            ),
            $this->getCaptured($function, $environment['callables']),
        );
    }

    /**
     * Get what the first borrowed variable a held closure reads by reference holds now, through itself or a
     * closure it captured, or null when there is none.
     *
     * @param HeldClosure $held
     * @param Environment $environment
     * @param array<int, array<string, true>> $visited the variables already being read for each closure, which a closure capturing itself reaches again
     */
    private function getLiveKind(array $held, array $environment, array $visited = []): ?string
    {
        foreach ($held[2] as $id => $references) {
            $references = array_diff_key($references, $visited[$id] ?? []);

            if ($references === []) {
                continue;
            }

            $visited[$id] = ($visited[$id] ?? []) + $references;
            $kind = $this->getFirstKind($references, $environment, $visited);

            if ($kind !== null) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Get what the first of the variables that is borrowed holds now: its value, what was appended to it, or what
     * a closure it may hold captured by value. A live read also includes what such a closure reads by reference now.
     *
     * @param array<string, true> $names
     * @param Environment $environment
     * @param array<int, array<string, true>> $visited
     */
    private function getFirstKind(array $names, array $environment, array $visited = [], bool $live = true): ?string
    {
        foreach ([$environment['tracked'], $environment['appended']] as $kinds) {
            foreach (array_intersect_key($kinds, $names) as $kind) {
                return $kind;
            }
        }

        foreach (array_intersect_key($environment['callables'], $names) as $closures) {
            foreach ($closures as $held) {
                $kind = $held[1] ?? ($live ? $this->getLiveKind($held, $environment, $visited) : null);

                if ($kind !== null) {
                    return $kind;
                }
            }
        }

        return null;
    }

    /**
     * Get the names of the variables a nested function takes from its enclosing one: a closure's `use`
     * variables, or the variables an arrow function's body reads other than its parameters, including
     * those its own nested functions take. A nested class's body takes nothing.
     *
     * @return array<string, true>
     */
    private function getCapturedNames(Closure|ArrowFunction $function): array
    {
        if ($function instanceof Closure) {
            return $this->getUseNames($function);
        }

        $visitor = new class($this->getCapturedNames(...)) extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $names = [];

            /**
             * @param \Closure(ArrowFunction|Closure): array<string, true> $capturedNames
             */
            public function __construct(
                private \Closure $capturedNames,
            ) {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
                }

                if ($node instanceof Closure || $node instanceof ArrowFunction) {
                    $this->names += ($this->capturedNames)($node);

                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Variable && is_string($node->name)) {
                    $this->names[$node->name] = true;
                }

                return null;
            }
        };

        (new NodeTraverser($visitor))->traverse([$function->expr]);

        return array_diff_key($visitor->names, $this->getParameterNames($function));
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
                $name === 'this', str_starts_with($name, 'this->') => ! $function->static,
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
     * Get a closure's by-reference `use` variable names.
     *
     * @return array<string, true>
     */
    private function getReferenceNames(Closure $closure): array
    {
        $names = [];

        foreach ($closure->uses as $use) {
            if ($use->byRef && is_string($use->var->name)) {
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
