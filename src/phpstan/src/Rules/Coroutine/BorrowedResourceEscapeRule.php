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
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignOp\Coalesce as CoalesceAssign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\StaticVar;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;
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

use function array_all;
use function array_any;
use function array_diff_key;
use function array_fill_keys;
use function array_filter;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_values;
use function class_basename;
use function count;
use function in_array;
use function is_array;
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
 * act on another coroutine's work. Each callback is read as written, in statement order: the lent
 * parameter, the raw handles taken from it, and the local variables, arrays and closures holding
 * them are followed, but not values passed through calls.
 *
 * @phpstan-type HeldClosure array{ArrowFunction|Closure, ?string, array<string, true>}
 * @phpstan-type Environment array{tracked: array<string, string>, appended: array<string, string>, callables: array<string, array<int, HeldClosure>>, referenced: array<string, true>, globals: array<string, true>, byReference: array<string, true>, nonNull: array<string, true>, nonEmpty: array<string, true>}
 * @phpstan-type Fact array{tracked: ?string, appended: ?string, callables: array<int, HeldClosure>}
 * @phpstan-type Flow array{normal: ?Environment, breaks: array<int, Environment>, continues: array<int, Environment>, exit: ?Environment}
 * @phpstan-type Walk array{borrow: array{method: string, resource: string}, owners: array<string, true>, locals: array<string, mixed>, external: ?array<string, true>, outer: ?array<string, true>, writes: array<string, non-empty-list<?Expr>>, positions: array<string, list<int>>, loops: list<array{int, int}>, isCallback: bool, generator: bool, used: ?string, raised: ?Environment, catching: bool, thrown: ?Environment, created: array<int, array{ArrowFunction|Closure, Environment, int}>, spawned: array<int, bool>, errors: array<string, IdentifierRuleError>}
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
     * What a value that holds nothing borrowed is.
     */
    private const array NO_FACT = ['tracked' => null, 'appended' => null, 'callables' => []];

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
        $borrow = ['method' => $method, 'resource' => $resource];
        $environment = [
            'tracked' => $tracked,
            'appended' => [],
            'callables' => [],
            'referenced' => [],
            'globals' => [],
            'byReference' => $callback instanceof Closure ? $this->getReferenceNames($callback) : [],
            'nonNull' => [],
            'nonEmpty' => [],
        ];

        // A closure callback receives its `use` variables from outside; an arrow function every variable it reads.
        $this->analyzeFunction(
            $callback,
            $borrow,
            $environment,
            $owners,
            $callback instanceof Closure ? $this->getUseNames($callback) : null,
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
     * to its caller inside the callback. The body is walked in statement order: each branch from what
     * reaches it, joined where the branches meet, and each loop's first iteration from what reaches it
     * and its later ones from what earlier ones pass back. A join keeps whatever any path gives a variable.
     *
     * @param array{method: string, resource: string} $borrow
     * @param Environment $environment what the function receives: each borrowed variable's value ("lent", or the name of the raw handle), what its arrays hold, the closures each variable may hold, and the variables bound to global or the caller's variables
     * @param array<string, true> $owners the variables or `$this` properties holding the pinned connection the function receives, or the DB facade
     * @param null|array<string, true> $received the variables the function receives from outside the callback, or null for every variable it doesn't own
     * @param array<string, IdentifierRuleError> $errors
     */
    private function analyzeFunction(
        Closure|ArrowFunction $function,
        array $borrow,
        array $environment,
        array $owners,
        ?array $received,
        bool $isCallback,
        Scope $scope,
        array &$errors,
    ): void {
        $nodes = $this->getOwnNodes($function instanceof Closure ? $function->stmts : [$function->expr]);
        [$writes, $propertyWrites, $positions] = $this->collectWrites($nodes);

        // A reassigned variable or property no longer holds the pinned connection.
        $owners = array_diff_key($owners, $writes, $propertyWrites);
        $locals = $writes + $this->getParameterNames($function);

        // The variables still holding what they received from outside the callback. An object they hold
        // outlives the callback, so a property written through one keeps its value.
        $external = $received === null ? null : array_diff_key($received, $locals);

        $loops = [];

        foreach ($nodes as $node) {
            if ($node instanceof Foreach_ || $node instanceof For_ || $node instanceof While_ || $node instanceof Do_) {
                $loops[] = [$node->getStartFilePos(), $node->getEndFilePos()];
            }
        }

        $walk = [
            'borrow' => $borrow,
            'owners' => $owners,
            'locals' => $locals,
            'external' => $external,
            // The callback's external variables are unchanged from the lending call's scope, which knows their
            // types. A nested function's are unknown.
            'outer' => $isCallback ? $external : [],
            'writes' => $writes,
            'positions' => $positions,
            'loops' => $loops,
            'isCallback' => $isCallback,
            // Calling a generator returns it before its body runs, so the lending method ends the borrow before the
            // body uses anything borrowed.
            'generator' => $isCallback && array_any($nodes, static fn (Node $node): bool => $node instanceof Yield_ || $node instanceof YieldFrom),
            'used' => null,
            'raised' => null,
            'catching' => false,
            'thrown' => null,
            'created' => [],
            'spawned' => [],
            'errors' => [],
        ];

        if ($function instanceof Closure) {
            $this->walkStatements($function->stmts, $environment, $walk, $scope);
        } else {
            [$after, $fact] = $this->evaluate($function->expr, $environment, $walk, $scope);
            $kind = $isCallback && $after !== null ? $this->getKind($fact, $after) : null;

            if ($kind !== null) {
                $this->addError($walk['errors'], $function->expr, $borrow, $kind, 'is returned');
            }
        }

        if ($walk['used'] !== null) {
            $this->addError($walk['errors'], $function, $borrow, $walk['used'], 'is used in a generator callback');
        }

        $errors += $walk['errors'];

        foreach ($walk['created'] as $id => [$node, $created, $position]) {
            if ($walk['spawned'][$id] ?? false) {
                continue;
            }

            $nestedOwners = $this->getCaptured($node, $owners);

            // A spawned closure runs in a child coroutine, which resolves its own default connection because copied
            // contexts leave connections out, or in a deferred callback after the pin ends. A connection object it
            // captures is still the pinned one.
            if (isset($walk['spawned'][$id])) {
                unset($nestedOwners[DB::class]);
            }

            $references = $node instanceof Closure ? $this->getReferenceNames($node) : [];

            // A variable captured by reference holds what it was last given when the nested function runs, so
            // a write after the function is created, or in a loop around its creation, leaves its value unknown.
            $rewritten = array_filter(
                $references,
                fn (string $name): bool => $this->isRewritten($name, $position, $walk),
                ARRAY_FILTER_USE_KEY,
            );

            // A nested function takes from outside the callback what this function itself took from there, and what
            // a global or by-reference name held when it was created, which the callback doesn't own even when
            // captured by value. Only a by-reference `use` shares the binding itself, so assigning the name writes there.
            $captured = $this->getCapturedNames($node);

            $this->analyzeFunction(
                $node,
                $borrow,
                [
                    'tracked' => array_diff_key($this->getCaptured($node, $created['tracked']), $rewritten),
                    'appended' => array_diff_key($this->getCaptured($node, $created['appended']), $rewritten),
                    'callables' => array_diff_key($this->getCarriedCallables($node, $created, $position, $walk), $rewritten),
                    'referenced' => [],
                    'globals' => array_intersect_key($references, $created['globals']),
                    'byReference' => array_intersect_key($references, $created['byReference']),
                    'nonNull' => [],
                    'nonEmpty' => [],
                ],
                $nestedOwners,
                ($external === null ? array_diff_key($captured, $locals) : array_intersect_key($captured, $external))
                    + array_intersect_key($captured, $created['byReference'] + $created['globals']),
                false,
                $scope,
                $errors,
            );
        }
    }

    /**
     * Walk statements in order from what reaches them, stopping where no path goes on.
     *
     * @param array<Stmt> $statements
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return Flow
     */
    private function walkStatements(array $statements, array $environment, array &$walk, Scope $scope): array
    {
        $flow = $this->flow($environment);

        foreach ($statements as $statement) {
            if ($flow['normal'] === null) {
                break;
            }

            $flow = $this->joinFlows(['normal' => null] + $flow, $this->walkStatement($statement, $flow['normal'], $walk, $scope));
        }

        return $flow;
    }

    /**
     * Walk a statement from what reaches it.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return Flow
     */
    private function walkStatement(Stmt $statement, array $environment, array &$walk, Scope $scope): array
    {
        // A throw in the statement's own expressions leaves through its exit.
        $raised = $walk['raised'];
        $walk['raised'] = null;

        $flow = match (true) {
            $statement instanceof Expression => $this->flow($this->evaluate($statement->expr, $environment, $walk, $scope)[0]),
            $statement instanceof Echo_ => $this->flow($this->evaluateAll($statement->exprs, $environment, $walk, $scope)),
            $statement instanceof Return_ => $this->walkReturn($statement, $environment, $walk, $scope),
            $statement instanceof If_ => $this->walkIf($statement, $environment, $walk, $scope),
            $statement instanceof Switch_ => $this->walkSwitch($statement, $environment, $walk, $scope),
            $statement instanceof While_, $statement instanceof Do_, $statement instanceof For_, $statement instanceof Foreach_ => $this->walkLoop($statement, $environment, $walk, $scope),
            $statement instanceof TryCatch => $this->walkTry($statement, $environment, $walk, $scope),
            $statement instanceof Break_ => ['normal' => null, 'breaks' => [$this->getLevel($statement) => $environment], 'continues' => [], 'exit' => null],
            $statement instanceof Continue_ => ['normal' => null, 'breaks' => [], 'continues' => [$this->getLevel($statement) => $environment], 'exit' => null],
            $statement instanceof Unset_ => $this->flow($this->walkUnset($statement, $environment, $walk, $scope)),
            $statement instanceof Static_ => $this->flow($this->detach($environment, CoroutineRuleHelper::getDetachedNames($statement), 'static')),
            $statement instanceof Global_ => $this->flow($this->detach($environment, $this->getGlobalNames($statement), 'global')),
            $statement instanceof Block, $statement instanceof Declare_ => $this->walkStatements($statement->stmts ?? [], $environment, $walk, $scope),
            // A goto isn't followed, so nothing after it is known to be reached.
            $statement instanceof Goto_ => $this->flow(null),
            default => $this->flow($environment),
        };

        $flow['exit'] = $this->join($flow['exit'], $walk['raised']);
        $walk['raised'] = $raised;

        return $flow;
    }

    /**
     * Walk a return, which leaves the callback with its value.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return Flow
     */
    private function walkReturn(Return_ $return, array $environment, array &$walk, Scope $scope): array
    {
        if ($return->expr !== null) {
            [$environment, $fact] = $this->evaluate($return->expr, $environment, $walk, $scope);
            $kind = $walk['isCallback'] && $environment !== null ? $this->getKind($fact, $environment) : null;

            if ($kind !== null) {
                $this->addError($walk['errors'], $return, $walk['borrow'], $kind, 'is returned');
            }
        }

        return ['normal' => null, 'breaks' => [], 'continues' => [], 'exit' => $environment];
    }

    /**
     * Walk each branch of an if from what its condition leaves, and join them.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return Flow
     */
    private function walkIf(If_ $if, array $environment, array &$walk, Scope $scope): array
    {
        [$condition] = $this->evaluate($if->cond, $environment, $walk, $scope);

        if ($condition === null) {
            return $this->flow(null);
        }

        $flow = $this->walkStatements($if->stmts, $condition, $walk, $scope);

        foreach ($if->elseifs as $elseif) {
            [$condition] = $this->evaluate($elseif->cond, $condition, $walk, $scope);

            if ($condition === null) {
                return $flow;
            }

            $flow = $this->joinFlows($flow, $this->walkStatements($elseif->stmts, $condition, $walk, $scope));
        }

        return $this->joinFlows($flow, $if->else === null ? $this->flow($condition) : $this->walkStatements($if->else->stmts, $condition, $walk, $scope));
    }

    /**
     * Walk each case of a switch from what its subject leaves, falling through into the next case.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return Flow
     */
    private function walkSwitch(Switch_ $switch, array $environment, array &$walk, Scope $scope): array
    {
        [$tested] = $this->evaluate($switch->cond, $environment, $walk, $scope);

        // Each case is entered with what the comparisons up to its own leave, and the default, wherever it is, with
        // what every comparison leaves.
        $entries = [];
        $default = null;

        foreach ($switch->cases as $index => $case) {
            if ($case->cond === null) {
                $default = $index;
            } elseif ($tested !== null) {
                [$tested] = $this->evaluate($case->cond, $tested, $walk, $scope);
                $entries[$index] = $tested;
            }
        }

        if ($default !== null) {
            $entries[$default] = $tested;
        }

        $flow = $this->flow(null);
        $fallthrough = null;

        foreach ($switch->cases as $index => $case) {
            $entry = $this->join($entries[$index] ?? null, $fallthrough);
            $caseFlow = $entry === null ? $this->flow(null) : $this->walkStatements($case->stmts, $entry, $walk, $scope);
            $fallthrough = $caseFlow['normal'];
            $flow = $this->joinFlows($flow, ['normal' => null] + $caseFlow);
        }

        // Without a default no case may match, and the last case falls out of the switch. A switch counts as a loop for
        // break and continue, and a continue that targets it acts like break.
        return $this->leaveLevel($flow, $this->join(
            $this->join($default === null ? $tested : null, $fallthrough),
            $this->join($flow['breaks'][1] ?? null, $flow['continues'][1] ?? null),
        ));
    }

    /**
     * Walk a loop: its first iteration from what reaches it, then its later iterations until what they pass back
     * stops changing.
     *
     * A later iteration starts only from what an earlier one passes back, so it doesn't meet what the variables held
     * before the loop. Each later pass only adds to what reaches the next, where a variable only becomes bound by
     * reference once, every other fact can only be added once and what's known for certain can only be lost once,
     * so the passes end.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return Flow
     */
    private function walkLoop(While_|Do_|For_|Foreach_ $loop, array $environment, array &$walk, Scope $scope): array
    {
        $head = match (true) {
            $loop instanceof For_ => $this->evaluateAll($loop->init, $environment, $walk, $scope),
            $loop instanceof Foreach_ => $this->evaluate($loop->expr, $environment, $walk, $scope)[0],
            default => $environment,
        };

        if ($head === null) {
            return $this->flow(null);
        }

        [$firstLeaves, $first, $later] = $this->walkIteration($loop, $head, $walk, $scope);

        // A foreach over an array literal holding an item that isn't unpacked, or over a variable last given one, runs
        // at least once, so it never leaves before its first iteration.
        $leaves = $loop instanceof Foreach_ && ($this->isNonEmptyArray($loop->expr)
            || ($loop->expr instanceof Variable && is_string($loop->expr->name) && isset($head['nonEmpty'][$loop->expr->name])))
            ? null
            : $firstLeaves;
        $last = $this->flow(null);

        // An iteration that passes back what reached it makes every later one repeat the first.
        if ($later === $head) {
            $later = null;
            $leaves = $firstLeaves;
        }

        while ($later !== null) {
            [$laterLeaves, $last, $back] = $this->walkIteration($loop, $later, $walk, $scope);
            $next = $this->join($later, $back) ?? $later;

            if ($next === $later) {
                $leaves = $this->join($leaves, $laterLeaves);

                break;
            }

            $later = $next;
        }

        $flow = $this->joinFlows($first, $last);

        return $this->leaveLevel($flow, $this->join($leaves, $flow['breaks'][1] ?? null));
    }

    /**
     * Determine whether the expression is an array literal holding an item that isn't unpacked, which is never empty.
     */
    private function isNonEmptyArray(Expr $expr): bool
    {
        return $expr instanceof Array_ && array_any($expr->items, static fn (ArrayItem $item): bool => ! $item->unpack);
    }

    /**
     * Walk one iteration of a loop from what reaches its head: where the loop may leave there, what its body does,
     * and what the iteration passes back to the head.
     *
     * @param Environment $head
     * @param Walk $walk
     *
     * @return array{?Environment, Flow, ?Environment}
     */
    private function walkIteration(While_|Do_|For_|Foreach_ $loop, array $head, array &$walk, Scope $scope): array
    {
        if ($loop instanceof Do_) {
            $body = $this->walkStatements($loop->stmts, $head, $walk, $scope);
            $end = $this->join($body['normal'], $body['continues'][1] ?? null);
            $back = $end === null ? null : $this->evaluate($loop->cond, $end, $walk, $scope)[0];

            return [$this->isTrue($loop->cond) ? null : $back, $body, $back];
        }

        $entry = match (true) {
            $loop instanceof While_ => $this->evaluate($loop->cond, $head, $walk, $scope)[0],
            $loop instanceof For_ => $this->evaluateAll($loop->cond, $head, $walk, $scope),
            default => $this->assignForeach($loop, $head),
        };

        // A foreach leaves at its head once it runs out of values; the others when their condition fails.
        $leaves = match (true) {
            $loop instanceof While_ => $this->isTrue($loop->cond) ? null : $entry,
            $loop instanceof For_ => $loop->cond === [] || $this->isTrue($loop->cond[count($loop->cond) - 1]) ? null : $entry,
            default => $head,
        };

        $body = $entry === null ? $this->flow(null) : $this->walkStatements($loop->stmts, $entry, $walk, $scope);
        $back = $this->join($body['normal'], $body['continues'][1] ?? null);

        if ($loop instanceof For_ && $back !== null) {
            $back = $this->evaluateAll($loop->loop, $back, $walk, $scope);
        }

        return [$leaves, $body, $back];
    }

    /**
     * Apply a foreach's writes at the head of each iteration: its key and value targets get values the rule doesn't
     * follow, and a by-reference value binds the target to an element of the iterated array.
     *
     * @param Environment $environment
     *
     * @return Environment
     */
    private function assignForeach(Foreach_ $foreach, array $environment): array
    {
        $environment = $this->detach($environment, CoroutineRuleHelper::getDetachedNames($foreach), 'reference');

        foreach ([...CoroutineRuleHelper::getTargetLeaves($foreach->keyVar), ...CoroutineRuleHelper::getTargetLeaves($foreach->valueVar)] as $leaf) {
            if ($leaf instanceof Variable && is_string($leaf->name)) {
                $this->setFact($environment, $leaf->name, self::NO_FACT);
            }
        }

        return $this->bindReferences($foreach, $environment);
    }

    /**
     * Walk a try, its catches and its finally.
     *
     * The catches receive what the try block has at each call and throw in it, the operations followed as throwing, of
     * any exception type; other operations that can throw, such as division, aren't. The finally runs on each way out of
     * the try and its catches, including an exception either lets out, then continues that way unless it leaves itself,
     * so an exception reaches the enclosing try's catches with what the finally leaves.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return Flow
     */
    private function walkTry(TryCatch $try, array $environment, array &$walk, Scope $scope): array
    {
        [$outerCatching, $outerThrown] = [$walk['catching'], $walk['thrown']];
        $walk['catching'] = true;
        $walk['thrown'] = null;
        $flow = $this->walkStatements($try->stmts, $environment, $walk, $scope);
        $thrown = $walk['thrown'];

        // An exception may match no catch, and a catch may throw, so both leave with what they had at the throw. A try
        // block that can't throw runs no catch.
        $escaping = $thrown;

        foreach ($thrown === null ? [] : $try->catches as $catch) {
            $entry = $thrown;

            if ($catch->var instanceof Variable && is_string($catch->var->name)) {
                $this->setFact($entry, $catch->var->name, self::NO_FACT);
            }

            $walk['thrown'] = null;
            $flow = $this->joinFlows($flow, $this->walkStatements($catch->stmts, $entry, $walk, $scope));
            $escaping = $this->join($escaping, $walk['thrown']);
        }

        $walk['catching'] = $outerCatching;
        $walk['thrown'] = $outerThrown;

        // An exception that leaves reaches the enclosing try's catches after the finally runs.
        if ($try->finally === null) {
            if ($escaping !== null) {
                $this->recordThrow($walk, $escaping);
            }

            return $flow;
        }

        $result = $this->flow(null);
        $ways = [['normal', 0, $flow['normal']], ['exit', 0, $flow['exit']], ['exception', 0, $escaping]];

        foreach ($flow['breaks'] as $level => $break) {
            $ways[] = ['breaks', $level, $break];
        }

        foreach ($flow['continues'] as $level => $continue) {
            $ways[] = ['continues', $level, $continue];
        }

        foreach ($ways as [$way, $level, $reaching]) {
            if ($reaching === null) {
                continue;
            }

            $finally = $this->walkStatements($try->finally->stmts, $reaching, $walk, $scope);
            $pending = $this->flow(null);

            if ($finally['normal'] !== null && $way === 'exception') {
                $this->recordThrow($walk, $finally['normal']);
            } elseif ($finally['normal'] !== null && ($way === 'normal' || $way === 'exit')) {
                $pending[$way] = $finally['normal'];
            } elseif ($finally['normal'] !== null) {
                $pending[$way][$level] = $finally['normal'];
            }

            $result = $this->joinFlows($this->joinFlows($result, ['normal' => null] + $finally), $pending);
        }

        return $result;
    }

    /**
     * Get the flow of a path that goes on normally, or of none.
     *
     * @param ?Environment $environment
     *
     * @return Flow
     */
    private function flow(?array $environment): array
    {
        return ['normal' => $environment, 'breaks' => [], 'continues' => [], 'exit' => null];
    }

    /**
     * Join two flows, each way out with the same way out.
     *
     * @param Flow $flow
     * @param Flow $other
     *
     * @return Flow
     */
    private function joinFlows(array $flow, array $other): array
    {
        foreach (['breaks', 'continues'] as $way) {
            foreach ($other[$way] as $level => $environment) {
                $flow[$way][$level] = $this->join($flow[$way][$level] ?? null, $environment) ?? $environment;
            }
        }

        $flow['normal'] = $this->join($flow['normal'], $other['normal']);
        $flow['exit'] = $this->join($flow['exit'], $other['exit']);

        return $flow;
    }

    /**
     * Record what a call or throw inside a try leaves for its catches and finally.
     *
     * @param Walk $walk
     * @param Environment $environment
     */
    private function recordThrow(array &$walk, array $environment): void
    {
        if ($walk['catching']) {
            $walk['thrown'] = $this->join($walk['thrown'], $environment);
        }
    }

    /**
     * Leave a loop or switch: what goes on after it, and the breaks and continues that target an enclosing one.
     *
     * @param Flow $flow
     * @param ?Environment $after
     *
     * @return Flow
     */
    private function leaveLevel(array $flow, ?array $after): array
    {
        $outer = ['normal' => $after, 'breaks' => [], 'continues' => [], 'exit' => $flow['exit']];

        foreach (['breaks', 'continues'] as $way) {
            foreach ($flow[$way] as $level => $environment) {
                if ($level > 1) {
                    $outer[$way][$level - 1] = $environment;
                }
            }
        }

        return $outer;
    }

    /**
     * Get how many enclosing loops and switches a break or continue leaves.
     */
    private function getLevel(Break_|Continue_ $statement): int
    {
        return $statement->num instanceof Int_ ? $statement->num->value : 1;
    }

    /**
     * Determine whether a loop condition is the literal true, so the loop only leaves through a break.
     */
    private function isTrue(Expr $condition): bool
    {
        return $condition instanceof ConstFetch && $condition->name->toLowerString() === 'true';
    }

    /**
     * Get the nodes of a function's own body: closures and arrow functions are included but not entered, and named
     * functions and classes are skipped, since they take nothing from the function they're declared in.
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
                if ($node instanceof ClassLike || $node instanceof Function_) {
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
     * Collect the values each variable is assigned, null for a write whose value is not followed, the
     * `$this` properties written, and where each variable's writes end.
     *
     * @param list<Node> $nodes
     *
     * @return array{array<string, non-empty-list<?Expr>>, array<string, true>, array<string, list<int>>}
     */
    private function collectWrites(array $nodes): array
    {
        $writes = [];
        $propertyWrites = [];
        $positions = [];

        foreach ($nodes as $node) {
            foreach ($this->getUnknownTargets($node) as $leaf) {
                if ($leaf instanceof Variable && is_string($leaf->name)) {
                    $writes[$leaf->name][] = null;
                    $positions[$leaf->name][] = $node->getEndFilePos();
                } elseif (($property = $this->getPropertyWriteKey($leaf)) !== null) {
                    $propertyWrites[$property] = true;
                }
            }

            // A later write through either side of a reference binding changes the other, so both are written.
            $sides = $this->getReferenceSides($node);

            foreach ($this->getReferenceRoots($node) as $name) {
                $writes[$name][] = null;
                $positions[$name][] = $node->getEndFilePos();
            }

            if ($sides !== [] && ($property = $this->getPropertyWriteKey($sides[count($sides) - 1])) !== null) {
                $propertyWrites[$property] = true;
            }

            if (! $node instanceof Assign && ! $node instanceof AssignRef && ! $node instanceof CoalesceAssign) {
                continue;
            }

            if (($property = $this->getPropertyWriteKey($node->var)) !== null) {
                $propertyWrites[$property] = true;
            }

            if ($node->var instanceof Variable && is_string($node->var->name)) {
                $writes[$node->var->name][] = $node->expr;
                $positions[$node->var->name][] = $node->getEndFilePos();
            }
        }

        return [$writes, $propertyWrites, $positions];
    }

    /**
     * Get both sides of the reference bindings the node makes, the source last: `=&`, by-reference destructuring
     * items and the destructured value, or a by-reference foreach value and the iterated expression.
     *
     * @return list<Expr>
     */
    private function getReferenceSides(Node $node): array
    {
        $targets = match (true) {
            $node instanceof AssignRef => [$node->var],
            $node instanceof Assign => CoroutineRuleHelper::getReferenceLeaves($node->var),
            $node instanceof Foreach_ => $node->byRef ? [$node->valueVar] : CoroutineRuleHelper::getReferenceLeaves($node->valueVar),
            default => [],
        };

        return $targets === [] ? [] : [...$targets, $node->expr];
    }

    /**
     * Get the variables the node's reference bindings tie together: the root variable of each side, such as
     * `$items` for `$items[0]` or `$holder` for `$holder->value`.
     *
     * @return list<string>
     */
    private function getReferenceRoots(Node $node): array
    {
        $names = [];

        foreach ($this->getReferenceSides($node) as $side) {
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
     * Get the targets a node writes without a value the rule follows: destructuring, compound assignments
     * other than `??=`, foreach, catch, static and global variables.
     *
     * @return list<Expr>
     */
    private function getUnknownTargets(Node $node): array
    {
        $targets = match (true) {
            $node instanceof Assign && ($node->var instanceof List_ || $node->var instanceof Array_) => [$node->var],
            $node instanceof AssignOp && ! $node instanceof CoalesceAssign => [$node->var],
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
     * Point the names at new bindings on this path: through unset(), static, global or a by-reference binding.
     *
     * A closure sharing an old binding by reference keeps it, and the names can no longer reach it, so the
     * closure keeps what the binding holds now: its value joins the closure's fixed kind, and the closures it
     * holds keep reading their own by-reference variables. A detached name read through those closures keeps
     * its binding the same way. Earlier copies keep their own values.
     *
     * @param Environment $environment
     * @param list<string> $names
     * @param 'global'|'reference'|'static'|'unset' $how
     *
     * @return Environment
     */
    private function detach(array $environment, array $names, string $how): array
    {
        if ($names === []) {
            return $environment;
        }

        $detached = array_fill_keys($names, true);
        $before = $environment;

        foreach ($before['callables'] as $variable => $closures) {
            foreach ($closures as $id => [$closure, $kind, $live]) {
                $pending = array_intersect_key($live, $detached);

                if ($pending === []) {
                    continue;
                }

                $live = array_diff_key($live, $detached);

                // Each detached name is followed once, so closures sharing each other's bindings end.
                $reached = [];

                while ($pending !== []) {
                    $reached += $pending;
                    $kind ??= $this->getFirstKind($pending, $before, live: false);
                    $next = [];

                    foreach (array_intersect_key($before['callables'], $pending) as $held) {
                        foreach ($held as [, , $references]) {
                            $live += array_diff_key($references, $detached);
                            $next += array_intersect_key($references, $detached);
                        }
                    }

                    $pending = array_diff_key($next, $reached);
                }

                $environment['callables'][$variable][$id] = [$closure, $kind, $live];
            }
        }

        // A global name is bound to a global variable from here on; the others to nothing outside the function.
        return [
            'tracked' => array_diff_key($environment['tracked'], $detached),
            'appended' => array_diff_key($environment['appended'], $detached),
            'callables' => array_diff_key($environment['callables'], $detached),
            'referenced' => array_diff_key($environment['referenced'], $detached),
            'globals' => $how === 'global' ? $environment['globals'] + $detached : array_diff_key($environment['globals'], $detached),
            'byReference' => array_diff_key($environment['byReference'], $detached),
            'nonNull' => array_diff_key($environment['nonNull'], $detached),
            'nonEmpty' => array_diff_key($environment['nonEmpty'], $detached),
        ];
    }

    /**
     * Make the variables a reference binding ties together unknown: a later write through either side changes both,
     * so neither value is followed from here on, including on paths that join this one.
     *
     * @param Environment $environment
     *
     * @return Environment
     */
    private function bindReferences(Node $node, array $environment): array
    {
        foreach ($this->getReferenceRoots($node) as $name) {
            unset($environment['tracked'][$name], $environment['appended'][$name], $environment['callables'][$name], $environment['nonNull'][$name], $environment['nonEmpty'][$name]);
            $environment['referenced'][$name] = true;
        }

        return $environment;
    }

    /**
     * Apply an unset(): a plain variable gets a new, undefined binding, while unsetting an offset or property leaves
     * what the rest of its array holds, though the array may now be empty.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return ?Environment
     */
    private function walkUnset(Unset_ $unset, array $environment, array &$walk, Scope $scope): ?array
    {
        foreach ($unset->vars as $variable) {
            if (! $variable instanceof Variable) {
                $environment = $this->evaluateTarget($variable, $environment, $walk, $scope);

                if ($environment === null) {
                    return null;
                }

                // Removing an offset changes the array without adding to it.
                $environment = $this->write($environment, $variable, self::NO_FACT);
            }
        }

        return $this->detach($environment, CoroutineRuleHelper::getDetachedNames($unset), 'unset');
    }

    /**
     * Get the names a `global` statement binds.
     *
     * @return list<string>
     */
    private function getGlobalNames(Global_ $global): array
    {
        $names = [];

        foreach ($global->vars as $variable) {
            if ($variable instanceof Variable && is_string($variable->name)) {
                $names[] = $variable->name;
            }
        }

        return $names;
    }

    /**
     * Join what two paths give each variable: whatever either gives it, except that a variable bound by
     * reference on either path is unknown. What's known for certain holds only when both paths know it.
     *
     * @param ?Environment $environment
     * @param ?Environment $other
     *
     * @return ?Environment
     */
    private function join(?array $environment, ?array $other): ?array
    {
        if ($environment === null || $other === null || $environment === $other) {
            return $environment ?? $other;
        }

        $callables = $environment['callables'];

        foreach ($other['callables'] as $name => $closures) {
            $callables[$name] = $this->mergeCallables($callables[$name] ?? [], $closures);
        }

        $referenced = $environment['referenced'] + $other['referenced'];

        return [
            'tracked' => array_diff_key($environment['tracked'] + $other['tracked'], $referenced),
            'appended' => array_diff_key($environment['appended'] + $other['appended'], $referenced),
            'callables' => array_diff_key($callables, $referenced),
            'referenced' => $referenced,
            'globals' => $environment['globals'] + $other['globals'],
            'byReference' => $environment['byReference'] + $other['byReference'],
            'nonNull' => array_intersect_key($environment['nonNull'], $other['nonNull']),
            'nonEmpty' => array_intersect_key($environment['nonEmpty'], $other['nonEmpty']),
        ];
    }

    /**
     * Merge the closures two values may hold. A closure both hold, from the same node, keeps the first borrowed
     * kind either captured by value and every name either reads by reference.
     *
     * @param array<int, HeldClosure> $closures
     * @param array<int, HeldClosure> $others
     *
     * @return array<int, HeldClosure>
     */
    private function mergeCallables(array $closures, array $others): array
    {
        foreach ($others as $id => $held) {
            if (! isset($closures[$id])) {
                $closures[$id] = $held;

                continue;
            }

            $closures[$id][1] ??= $held[1];
            $closures[$id][2] += $held[2];
        }

        return $closures;
    }

    /**
     * Join what two values may be.
     *
     * @param Fact $fact
     * @param Fact $other
     *
     * @return Fact
     */
    private function joinFacts(array $fact, array $other): array
    {
        return [
            'tracked' => $fact['tracked'] ?? $other['tracked'],
            'appended' => $fact['appended'] ?? $other['appended'],
            'callables' => $this->mergeCallables($fact['callables'], $other['callables']),
        ];
    }

    /**
     * Replace what the variable holds with the fact. A variable bound by reference stays unknown.
     *
     * @param Environment $environment
     * @param Fact $fact
     */
    private function setFact(array &$environment, string $name, array $fact): void
    {
        unset($environment['nonNull'][$name], $environment['nonEmpty'][$name]);

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
     * Get what a variable holds.
     *
     * @param Environment $environment
     *
     * @return Fact
     */
    private function getVariableFact(array $environment, string $name): array
    {
        return [
            'tracked' => $environment['tracked'][$name] ?? null,
            'appended' => $environment['appended'][$name] ?? null,
            'callables' => $environment['callables'][$name] ?? [],
        ];
    }

    /**
     * Write a value to an assignment's target: a variable takes it, and an array written through an offset keeps
     * what it held as well. Properties aren't followed.
     *
     * @param Environment $environment
     * @param Fact $fact
     *
     * @return Environment
     */
    private function write(array $environment, Expr $target, array $fact): array
    {
        $root = $target;

        while ($root instanceof ArrayDimFetch) {
            $root = $root->var;
        }

        if (! $root instanceof Variable || ! is_string($root->name) || $root->name === 'this') {
            return $environment;
        }

        $this->setFact($environment, $root->name, $root === $target ? $fact : [
            'tracked' => $environment['tracked'][$root->name] ?? null,
            'appended' => $environment['appended'][$root->name] ?? $fact['tracked'] ?? $fact['appended'],
            'callables' => $this->mergeCallables($environment['callables'][$root->name] ?? [], $fact['callables']),
        ]);

        return $environment;
    }

    /**
     * Evaluate an expression from what reaches it, checking its escapes and applying its writes.
     *
     * Only PHP's guaranteed sequencing is followed: an assignment's value before its write, and the left side or
     * condition of `&&`, `||`, `??`, a ternary and a match before the operands that depend on it, each of which starts
     * from what reaches it. Other operands are taken in source order, which approximates expressions whose result
     * depends on the order of side effects in different operands.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return array{?Environment, Fact} what follows the expression, or null when it always throws or exits, and what its value is or holds
     */
    private function evaluate(Expr $expr, array $environment, array &$walk, Scope $scope): array
    {
        if ($expr instanceof Variable) {
            if (! is_string($expr->name)) {
                return [$this->evaluate($expr->name, $environment, $walk, $scope)[0], self::NO_FACT];
            }

            $fact = $this->getVariableFact($environment, $expr->name);

            if ($walk['generator']) {
                $walk['used'] ??= $this->getKind($fact, $environment);
            }

            return [$environment, $fact];
        }

        if ($expr instanceof Assign && ($expr->var instanceof List_ || $expr->var instanceof Array_)) {
            return $this->evaluateDestructuring($expr, $expr->var, $environment, $walk, $scope);
        }

        if ($expr instanceof CoalesceAssign) {
            $before = $this->evaluateTarget($expr->var, $environment, $walk, $scope);

            if ($before === null) {
                return [null, self::NO_FACT];
            }

            // `??=` keeps a target that is already set without running its value, so the target holds either value
            // afterwards.
            $old = $expr->var instanceof Variable && is_string($expr->var->name) ? $this->getVariableFact($before, $expr->var->name) : self::NO_FACT;

            // A variable known to hold a value other than null never runs it.
            if ($expr->var instanceof Variable && is_string($expr->var->name) && isset($before['nonNull'][$expr->var->name])) {
                return [$before, $old];
            }

            [$after, $fact] = $this->evaluate($expr->expr, $before, $walk, $scope);

            if ($after === null) {
                return [$before, $old];
            }

            $fact = $this->joinFacts($old, $fact);
            $this->checkStore($expr->var, $fact, $after, $walk, $expr);

            return [$this->join($before, $this->write($after, $expr->var, $fact)), $fact];
        }

        if ($expr instanceof Assign || $expr instanceof AssignRef || $expr instanceof AssignOp) {
            $before = $this->evaluateTarget($expr->var, $environment, $walk, $scope);
            [$after, $fact] = $before === null ? [null, self::NO_FACT] : $this->evaluate($expr->expr, $before, $walk, $scope);

            if ($after === null) {
                return [null, self::NO_FACT];
            }

            if ($expr instanceof Assign) {
                $this->checkStore($expr->var, $fact, $after, $walk, $expr);
                $after = $this->write($after, $expr->var, $fact);

                // A literal other than null gives the variable a value `??=` keeps, and a nonempty array literal gives it
                // one a foreach runs over at least once. A call or a write through a reference forgets both.
                if ($expr->var instanceof Variable && is_string($expr->var->name) && ! isset($after['referenced'][$expr->var->name])
                    && ($expr->expr instanceof Scalar
                        || $expr->expr instanceof Array_
                        || $expr->expr instanceof New_
                        || $expr->expr instanceof Closure
                        || $expr->expr instanceof ArrowFunction
                        || ($expr->expr instanceof ConstFetch && in_array($expr->expr->name->toLowerString(), ['true', 'false'], true)))) {
                    $after['nonNull'][$expr->var->name] = true;

                    if ($this->isNonEmptyArray($expr->expr)) {
                        $after['nonEmpty'][$expr->var->name] = true;
                    }
                }

                return [$after, $fact];
            }

            if ($expr instanceof AssignRef) {
                // A reference assignment between a plain variable and global or by-reference storage binds the variable
                // to that storage, read before the variable is detached from what it was bound to. Binding a plain
                // variable by reference points it at another value instead of storing through it.
                $alias = CoroutineRuleHelper::getStorageAlias($expr, $after['globals'] + $after['byReference'] + ['GLOBALS' => true]);
                $global = $alias !== null && ($alias[1] === 'GLOBALS' || isset($after['globals'][$alias[1]]));
                $after = $this->detach($after, CoroutineRuleHelper::getDetachedNames($expr), 'reference');
                $this->checkStore($expr->var, $fact, $after, $walk, $expr);

                if ($alias !== null) {
                    $after[$global ? 'globals' : 'byReference'][$alias[0]] = true;
                }

                return [$this->bindReferences($expr, $after), $fact];
            }

            if ($expr->var instanceof Variable && is_string($expr->var->name)) {
                $this->setFact($after, $expr->var->name, self::NO_FACT);
            }

            return [$after, self::NO_FACT];
        }

        if ($expr instanceof PreInc || $expr instanceof PreDec || $expr instanceof PostInc || $expr instanceof PostDec) {
            $after = $this->evaluateTarget($expr->var, $environment, $walk, $scope);

            if ($after !== null && $expr->var instanceof Variable && is_string($expr->var->name)) {
                $this->setFact($after, $expr->var->name, self::NO_FACT);
            }

            return [$after, self::NO_FACT];
        }

        if ($expr instanceof Ternary || $expr instanceof Coalesce || $expr instanceof BooleanAnd || $expr instanceof BooleanOr || $expr instanceof LogicalAnd || $expr instanceof LogicalOr) {
            [$first, $firstFact] = $this->evaluate($expr instanceof Ternary ? $expr->cond : $expr->left, $environment, $walk, $scope);

            if ($first === null) {
                return [null, self::NO_FACT];
            }

            $branches = match (true) {
                $expr instanceof Ternary => [$expr->if === null ? [$first, $firstFact] : $this->evaluate($expr->if, $first, $walk, $scope), $this->evaluate($expr->else, $first, $walk, $scope)],
                $expr instanceof Coalesce => [[$first, $firstFact], $this->evaluate($expr->right, $first, $walk, $scope)],
                default => [[$first, self::NO_FACT], [$this->evaluate($expr->right, $first, $walk, $scope)[0], self::NO_FACT]],
            };

            return $this->joinBranches($branches);
        }

        if ($expr instanceof Match_) {
            [$tested] = $this->evaluate($expr->cond, $environment, $walk, $scope);
            $branches = [];
            $default = null;

            // Each arm runs with what any of its own conditions leaves, and the default arm, wherever it is, with what
            // every condition leaves.
            foreach ($expr->arms as $arm) {
                if ($arm->conds === null) {
                    $default = $arm;

                    continue;
                }

                $matched = null;

                foreach ($arm->conds as $condition) {
                    if ($tested !== null) {
                        [$tested] = $this->evaluate($condition, $tested, $walk, $scope);
                        $matched = $this->join($matched, $tested);
                    }
                }

                if ($matched !== null) {
                    $branches[] = $this->evaluate($arm->body, $matched, $walk, $scope);
                }
            }

            if ($default !== null && $tested !== null) {
                $branches[] = $this->evaluate($default->body, $tested, $walk, $scope);
            }

            return $this->joinBranches($branches);
        }

        if ($expr instanceof Closure || $expr instanceof ArrowFunction) {
            // A closure reached more than once, such as in a loop or a finally, captures what any of those visits gives it.
            $id = spl_object_id($expr);
            $walk['created'][$id] = [$expr, $this->join($walk['created'][$id][1] ?? null, $environment) ?? $environment, $expr->getStartFilePos()];
            $held = $this->getHeldClosure($expr, $environment);

            if ($walk['generator']) {
                $walk['used'] ??= $held[1] ?? $this->getLiveKind($held, $environment);
            }

            return [$environment, ['tracked' => null, 'appended' => null, 'callables' => [$id => $held]]];
        }

        if ($expr instanceof Array_) {
            $fact = self::NO_FACT;

            foreach ($expr->items as $item) {
                [$after, $itemFact] = $this->evaluateItem($item, $environment, $walk, $scope);

                if ($after === null) {
                    return [null, self::NO_FACT];
                }

                $environment = $after;
                $fact['tracked'] ??= $itemFact['tracked'] ?? $itemFact['appended'];
                $fact['callables'] = $this->mergeCallables($fact['callables'], $itemFact['callables']);
            }

            return [$environment, $fact];
        }

        if ($expr instanceof CallLike) {
            return $this->evaluateCall($expr, $environment, $walk, $scope);
        }

        if ($expr instanceof Throw_) {
            [$after] = $this->evaluate($expr->expr, $environment, $walk, $scope);

            if ($after !== null) {
                $walk['raised'] = $this->join($walk['raised'], $after);
                $this->recordThrow($walk, $after);
            }

            return [null, self::NO_FACT];
        }

        if ($expr instanceof Exit_) {
            if ($expr->expr !== null) {
                $this->evaluate($expr->expr, $environment, $walk, $scope);
            }

            return [null, self::NO_FACT];
        }

        if ($expr instanceof ErrorSuppress) {
            return $this->evaluate($expr->expr, $environment, $walk, $scope);
        }

        // Any other expression reads its operands in source order, and its value isn't followed.
        foreach ($expr->getSubNodeNames() as $name) {
            foreach (is_array($expr->{$name}) ? $expr->{$name} : [$expr->{$name}] as $child) {
                $child = $child instanceof Arg ? $child->value : $child;

                if ($child instanceof ArrayItem) {
                    [$environment] = $this->evaluateItem($child, $environment, $walk, $scope);
                } elseif ($child instanceof Expr) {
                    [$environment] = $this->evaluate($child, $environment, $walk, $scope);
                }

                if ($environment === null) {
                    return [null, self::NO_FACT];
                }
            }
        }

        return [$environment, self::NO_FACT];
    }

    /**
     * Join the branches of a conditional expression that complete.
     *
     * @param list<array{?Environment, Fact}> $branches
     *
     * @return array{?Environment, Fact}
     */
    private function joinBranches(array $branches): array
    {
        $environment = null;
        $fact = self::NO_FACT;

        foreach ($branches as [$after, $branchFact]) {
            if ($after !== null) {
                $environment = $this->join($environment, $after);
                $fact = $this->joinFacts($fact, $branchFact);
            }
        }

        return [$environment, $fact];
    }

    /**
     * Evaluate an array item: its key, then its value.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return array{?Environment, Fact}
     */
    private function evaluateItem(ArrayItem $item, array $environment, array &$walk, Scope $scope): array
    {
        if ($item->key !== null) {
            [$after] = $this->evaluate($item->key, $environment, $walk, $scope);

            if ($after === null) {
                return [null, self::NO_FACT];
            }

            $environment = $after;
        }

        return $this->evaluate($item->value, $environment, $walk, $scope);
    }

    /**
     * Evaluate a call: its receiver, then its arguments, then whether it spawns closures that can outlive the
     * callback. Its result is a raw handle when it takes one from the lent resource.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return array{?Environment, Fact}
     */
    private function evaluateCall(CallLike $call, array $environment, array &$walk, Scope $scope): array
    {
        $receiver = self::NO_FACT;

        if ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) {
            [$after, $receiver] = $this->evaluate($call->var, $environment, $walk, $scope);
        } else {
            $callee = match (true) {
                $call instanceof FuncCall => $call->name,
                $call instanceof StaticCall, $call instanceof New_ => $call->class,
                default => null,
            };

            [$after] = $callee instanceof Expr ? $this->evaluate($callee, $environment, $walk, $scope) : [$environment];
        }

        // A nullsafe call's arguments and invocation don't run when its receiver is null.
        $skipped = $call instanceof NullsafeMethodCall ? $after : null;
        $passed = [];

        foreach ($call->isFirstClassCallable() ? [] : $call->getArgs() as $argument) {
            if ($after === null) {
                break;
            }

            [$after, $argumentFact] = $this->evaluate($argument->value, $after, $walk, $scope);
            $passed = $this->mergeCallables($passed, $argumentFact['callables']);
        }

        if ($after === null) {
            return [$skipped, self::NO_FACT];
        }

        $spawn = $this->getSpawn($call, $walk['locals'], $walk['writes'], $walk['outer'], $scope);

        if ($spawn !== null) {
            $kind = null;

            foreach ($passed as $id => $held) {
                $heldKind = $held[1] ?? $this->getLiveKind($held, $after);
                $walk['spawned'][$id] = ($walk['spawned'][$id] ?? false) || $heldKind !== null;
                $kind ??= $heldKind;
            }

            if ($kind !== null) {
                $this->addError($walk['errors'], $call, $walk['borrow'], $kind, sprintf('is captured by a closure passed to %s()', $spawn));
            }
        }

        // Creating a first-class callable runs nothing. A call can change locals through references it's given or a
        // closure it runs holds, and other coroutines can change by-reference and global variables while it waits, so
        // what's known for certain about variables no longer holds.
        if (! $call->isFirstClassCallable()) {
            $after['nonNull'] = [];
            $after['nonEmpty'] = [];
            $this->recordThrow($walk, $after);
        }

        $handle = $this->getHandleKind($call, $receiver, $walk['owners'], $walk['borrow']['resource'], $scope);

        if ($walk['generator']) {
            $walk['used'] ??= $handle;
        }

        return [$this->join($skipped, $after) ?? $after, ['tracked' => $handle, 'appended' => null, 'callables' => []]];
    }

    /**
     * Evaluate a destructuring assignment. A keyless array literal gives each keyless leaf the item at its position,
     * whose store is checked; the leaves themselves get values the rule doesn't follow.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return array{?Environment, Fact}
     */
    private function evaluateDestructuring(Assign $assign, List_|Array_ $target, array $environment, array &$walk, Scope $scope): array
    {
        $leaves = [];
        [$after, $fact] = $this->evaluateDestructured($target, $assign->expr, $environment, $walk, $scope, $leaves);

        if ($after === null) {
            return [null, self::NO_FACT];
        }

        $after = $this->detach($after, CoroutineRuleHelper::getDetachedNames($assign), 'reference');

        foreach ($leaves as [$leaf, $leafFact]) {
            $this->checkStore($leaf, $leafFact, $after, $walk, $assign);
        }

        foreach (CoroutineRuleHelper::getTargetLeaves($target) as $leaf) {
            if ($leaf instanceof Variable && is_string($leaf->name)) {
                $this->setFact($after, $leaf->name, self::NO_FACT);
            }
        }

        return [$this->bindReferences($assign, $after), $fact];
    }

    /**
     * Evaluate a destructured value, collecting each keyless leaf that a keyless array literal gives the item at its
     * position, through nested destructuring.
     *
     * @param Environment $environment
     * @param Walk $walk
     * @param list<array{Expr, Fact}> $leaves
     *
     * @return array{?Environment, Fact}
     */
    private function evaluateDestructured(List_|Array_ $target, Expr $value, array $environment, array &$walk, Scope $scope, array &$leaves): array
    {
        if (! $value instanceof Array_ || ! array_all($value->items, static fn (ArrayItem $item): bool => $item->key === null && ! $item->unpack)) {
            return $this->evaluate($value, $environment, $walk, $scope);
        }

        $fact = self::NO_FACT;

        foreach ($value->items as $position => $item) {
            $leaf = $target->items[$position] ?? null;

            if ($leaf !== null && $leaf->key === null && ($leaf->value instanceof List_ || $leaf->value instanceof Array_)) {
                [$after, $itemFact] = $this->evaluateDestructured($leaf->value, $item->value, $environment, $walk, $scope, $leaves);
            } else {
                [$after, $itemFact] = $this->evaluate($item->value, $environment, $walk, $scope);

                if ($leaf !== null && $leaf->key === null) {
                    $leaves[] = [$leaf->value, $itemFact];
                }
            }

            if ($after === null) {
                return [null, self::NO_FACT];
            }

            $environment = $after;
            $fact['tracked'] ??= $itemFact['tracked'] ?? $itemFact['appended'];
            $fact['callables'] = $this->mergeCallables($fact['callables'], $itemFact['callables']);
        }

        return [$environment, $fact];
    }

    /**
     * Evaluate what a write target reads before it's written: its offsets and the objects whose properties it writes.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return ?Environment
     */
    private function evaluateTarget(Expr $target, array $environment, array &$walk, Scope $scope): ?array
    {
        if ($target instanceof Variable) {
            return is_string($target->name) ? $environment : $this->evaluate($target->name, $environment, $walk, $scope)[0];
        }

        if (! $target instanceof ArrayDimFetch) {
            return $this->evaluate($target, $environment, $walk, $scope)[0];
        }

        $after = $this->evaluateTarget($target->var, $environment, $walk, $scope);

        return $after === null || $target->dim === null ? $after : $this->evaluate($target->dim, $after, $walk, $scope)[0];
    }

    /**
     * Evaluate expressions in order.
     *
     * @param array<Expr> $exprs
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return ?Environment
     */
    private function evaluateAll(array $exprs, array $environment, array &$walk, Scope $scope): ?array
    {
        foreach ($exprs as $expr) {
            [$after] = $this->evaluate($expr, $environment, $walk, $scope);

            if ($after === null) {
                return null;
            }

            $environment = $after;
        }

        return $environment;
    }

    /**
     * Get the raw handle a call takes from the lent resource, or from the pinned connection, or null.
     *
     * @param Fact $receiver what the call's receiver is or holds
     * @param array<string, true> $owners
     */
    private function getHandleKind(CallLike $call, array $receiver, array $owners, string $resource, Scope $scope): ?string
    {
        if (! ($call instanceof MethodCall || $call instanceof NullsafeMethodCall || $call instanceof StaticCall)
            || ! $call->name instanceof Identifier
            || $call->isFirstClassCallable()) {
            return null;
        }

        $handle = self::RESOURCES[$resource][1][$call->name->toLowerString()] ?? null;

        if ($handle === null) {
            return null;
        }

        if ($resource !== 'session') {
            return ! $call instanceof StaticCall && $receiver['tracked'] === 'lent' ? $handle : null;
        }

        // A pinned session's PDOs come from the pinned connection itself, through the framework's own getters.
        $ownerType = match (true) {
            $call instanceof StaticCall => isset($owners[DB::class]) && $call->class instanceof Name && ReflectionHelper::isFacade($call->class, DB::class, 'DB')
                ? new ObjectType($scope->resolveName($call->class))
                : null,
            $call->var instanceof Variable && is_string($call->var->name) && isset($owners[$call->var->name]) => $scope->getType($call->var),
            ($property = $this->getPropertyWriteKey($call->var)) !== null && isset($owners[$property]) => $scope->getType($call->var),
            default => null,
        };

        return $ownerType !== null && $this->runsConnectionMethod($ownerType, $call->name->toString(), $scope) ? $handle : null;
    }

    /**
     * Report a borrowed value an assignment stores where it outlives the callback.
     *
     * @param Fact $fact
     * @param Environment $environment
     * @param Walk $walk
     */
    private function checkStore(Expr $target, array $fact, array $environment, array &$walk, Node $node): void
    {
        $how = $this->getStore($target, $environment['byReference'], $environment['globals'], $walk['external'], $walk['locals']);
        $kind = $how === null ? null : $this->getKind($fact, $environment);

        if ($how !== null && $kind !== null) {
            $this->addError($walk['errors'], $node, $walk['borrow'], $kind, $how);
        }
    }

    /**
     * Get what a value is or holds now: "lent" for the lent resource, the name of a raw handle, or null. A closure
     * holds what it captured by value and what it reads by reference now.
     *
     * @param Fact $fact
     * @param Environment $environment
     */
    private function getKind(array $fact, array $environment): ?string
    {
        if (($fact['tracked'] ?? $fact['appended']) !== null) {
            return $fact['tracked'] ?? $fact['appended'];
        }

        foreach ($fact['callables'] as $held) {
            $kind = $held[1] ?? $this->getLiveKind($held, $environment);

            if ($kind !== null) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Get how an assignment to the target keeps its value beyond the callback, or null when it stays local.
     *
     * A property of $this, or of an object a variable holds from outside the callback or from a global variable,
     * outlives it, as do global variables.
     *
     * @param array<string, true> $byReference the callback's by-reference variables
     * @param array<string, true> $globals the variables bound to global variables
     * @param null|array<string, true> $external the variables holding what they received from outside the callback, or null for every one the function doesn't own
     * @param array<string, mixed> $locals the function's parameters and assigned variables
     */
    private function getStore(Expr $target, array $byReference, array $globals, ?array $external, array $locals): ?string
    {
        while ($target instanceof ArrayDimFetch || $target instanceof PropertyFetch) {
            if ($target instanceof PropertyFetch
                && $target->var instanceof Variable
                && is_string($name = $target->var->name)
                && ($name === 'this' || isset($globals[$name]) || ($external === null ? ! isset($locals[$name]) : isset($external[$name])))) {
                return 'is stored on a property';
            }

            $target = $target->var;
        }

        if (! $target instanceof Variable || ! is_string($target->name)) {
            return $target instanceof StaticPropertyFetch ? 'is stored on a property' : null;
        }

        return match (true) {
            $target->name === 'GLOBALS', isset($globals[$target->name]) => 'is stored in a global variable',
            isset($byReference[$target->name]) => 'is stored in a by-reference variable',
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
     * Get the entry for a closure created now: the closure, what the first borrowed variable it captures by value
     * holds, which it keeps for as long as it lives, and the by-reference variables it reads when it runs: its own
     * and those of the closures it captures by value, even once their variables are replaced.
     *
     * @param Environment $environment
     *
     * @return HeldClosure
     */
    private function getHeldClosure(Closure|ArrowFunction $closure, array $environment): array
    {
        $names = $this->getCapturedNames($closure);
        $live = $closure instanceof Closure ? $this->getReferenceNames($closure) : [];
        $names = array_diff_key($names, $live);

        foreach (array_intersect_key($environment['callables'], $names) as $closures) {
            foreach ($closures as [, , $references]) {
                $live += $references;
            }
        }

        return [$closure, $this->getFirstKind($names, $environment, live: false), $live];
    }

    /**
     * Get the closures a nested function created now captures.
     *
     * The nested function runs later without this function's variables, so what a captured closure reads by
     * reference from them is read now, leaving out the variables this function writes after the creation or in
     * a loop around it, which may hold anything by then. A variable the nested function shares by reference is the
     * same binding, which it can change itself, so only those variables are read from its own facts when it runs.
     *
     * @param Environment $environment
     * @param Walk $walk
     *
     * @return array<string, array<int, HeldClosure>>
     */
    private function getCarriedCallables(Closure|ArrowFunction $function, array $environment, int $position, array $walk): array
    {
        $shared = $function instanceof Closure ? $this->getReferenceNames($function) : [];
        $unread = $shared;

        foreach (array_keys($environment['tracked'] + $environment['appended'] + $environment['callables']) as $name) {
            if ($this->isRewritten($name, $position, $walk)) {
                $unread[$name] = true;
            }
        }

        $now = [
            'tracked' => array_diff_key($environment['tracked'], $unread),
            'appended' => array_diff_key($environment['appended'], $unread),
            'callables' => array_diff_key($environment['callables'], $unread),
        ] + $environment;

        return array_map(
            fn (array $closures): array => array_map(
                fn (array $held): array => [$held[0], $held[1] ?? $this->getLiveKind($held, $now), array_intersect_key($held[2], $shared)],
                $closures,
            ),
            $this->getCaptured($function, $environment['callables']),
        );
    }

    /**
     * Determine whether the function writes the variable after the position, or in a loop around the position,
     * which runs that write again before it.
     *
     * @param Walk $walk
     */
    private function isRewritten(string $name, int $position, array $walk): bool
    {
        foreach ($walk['positions'][$name] ?? [] as $written) {
            if ($written >= $position) {
                return true;
            }

            foreach ($walk['loops'] as [$start, $end]) {
                if ($start < $position && $position < $end && $start < $written) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get what the first borrowed variable a held closure reads by reference holds now, through itself or a
     * closure it captured, or null when there is none.
     *
     * @param HeldClosure $held
     * @param Environment $environment
     * @param array<string, true> $visited the variables already being read, which a closure capturing itself reaches again
     */
    private function getLiveKind(array $held, array $environment, array $visited = []): ?string
    {
        $references = array_diff_key($held[2], $visited);

        return $references === [] ? null : $this->getFirstKind($references, $environment, $visited + $references);
    }

    /**
     * Get what the first of the variables that is borrowed holds now: its value, what was appended to it, or what
     * a closure it may hold captured by value. A live read also includes what such a closure reads by reference now.
     *
     * @param array<string, true> $names
     * @param Environment $environment
     * @param array<string, true> $visited
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

        return array_diff_key($this->getReadNames($function->expr), $this->getParameterNames($function));
    }

    /**
     * Get the names of the variables an expression reads, including those its nested functions take. A nested
     * class's body reads nothing.
     *
     * @return array<string, true>
     */
    private function getReadNames(Expr $expr): array
    {
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

        (new NodeTraverser($visitor))->traverse([$expr]);

        return $visitor->names;
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
        } elseif ($how === 'is used in a generator callback') {
            $tip = sprintf('A generator callback only runs once it is iterated, after the %s ends, so return the results instead of yielding them.', $end);
        }

        $errors[$node->getStartLine() . $message] ??= RuleErrorBuilder::message($message)
            ->line($node->getStartLine())
            ->identifier('hypervel.borrowedResourceEscape')
            ->addTip($tip)
            ->build();
    }
}
