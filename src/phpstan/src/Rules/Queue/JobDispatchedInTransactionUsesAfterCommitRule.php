<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Queue;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Contracts\Queue\ShouldQueueViaOutbox;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\DatabaseManager;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Concerns\InspectsQueuedJobs;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\PHPStan\Support\CallStack;
use Hypervel\PHPStan\Support\ConfigParser;
use Hypervel\PHPStan\Support\ConstructorParser;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Queue\Attributes\Connection;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\InitializerExprContext;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function spl_object_id;
use function sprintf;
use function strtolower;

/**
 * A queued job dispatched inside a `DB::transaction(...)` closure must defer its
 * dispatch until the transaction commits, by chaining `->afterCommit()` on the
 * dispatch, declaring `$afterCommit = true`, implementing ShouldQueueAfterCommit,
 * or using a queue connection that does either.
 *
 * A queued job pushed during an open transaction can be picked up by a worker
 * before the transaction commits (a fast worker racing the still open connection):
 * the job then loads rows that are not visible yet and fails, or silently operates
 * on half written state. Worse, if the transaction rolls back the job still runs
 * against data that never existed. `afterCommit` holds the dispatch until the
 * outermost transaction commits, and drops it entirely on rollback.
 *
 * Unlike upstream, which assumes Laravel's queue config, the rule reports only a
 * dispatch whose queue connection is known to push outside the transaction: Hypervel
 * defaults `after_commit` to true for every driver except `sync` and `database`, and a
 * database queue on the transaction's own connection inserts inside it. Anything the
 * analysis cannot establish (an environment-dependent connection, a custom driver,
 * failover, an enclosing transaction or a job constructor that changes its settings)
 * stays silent, as do a deliberate `beforeCommit()`, the outbox and `afterResponse()`.
 *
 * Scope and limits:
 *
 *   - Only the transaction callback's closure or arrow function form is inspected.
 *     The manual `DB::beginTransaction()` ... `DB::commit()` form has no closure
 *     to bound the analysis and is not covered.
 *   - `Job::dispatch(...)`, `Job::dispatchIf(...)`, `Job::dispatchUnless(...)`, the
 *     `dispatch()` helper and `Bus::dispatch()` are checked, for a job named in the
 *     call. A literal condition that fails dispatches nothing. Synchronous dispatch
 *     (`dispatchSync`, `dispatch_sync`) runs inline within the transaction by design.
 *   - A dispatch's own settings only count when they are syntactically chained on it.
 *   - Unlike upstream, the walk does not enter nested closures, functions or
 *     classes: they run later, never or at an unknown time (a `DB::afterCommit()`
 *     callback, for example). A nested transaction is checked from its own call.
 *
 * @implements Rule<Expr\CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkDispatchInTransactionAfterCommit%')]
class JobDispatchedInTransactionUsesAfterCommitRule implements Rule
{
    use HasContainer;
    use InspectsQueuedJobs;

    /**
     * The setting each dispatch chain method changes, by lowercase method name.
     *
     * @var array<string, string>
     */
    private const array CHAIN_SETTINGS = [
        'aftercommit' => 'afterCommit',
        'beforecommit' => 'afterCommit',
        'onconnection' => 'connection',
        'allonconnection' => 'connection',
        'viaoutbox' => 'viaOutbox',
        'withoutoutbox' => 'viaOutbox',
        'onoutboxdatabase' => 'viaOutbox',
        'afterresponse' => 'afterResponse',
    ];

    /**
     * The job properties holding those settings.
     *
     * @var list<string>
     */
    private const array SETTING_PROPERTIES = ['connection', 'afterCommit', 'viaOutbox', 'outboxDatabase'];

    /**
     * The Queueable methods a job constructor may call without changing those settings.
     *
     * @var list<string>
     */
    private const array SETTING_FREE_METHODS = ['onqueue', 'allonqueue', 'ongroup', 'withdeduplicator', 'delay', 'withoutdelay', 'through'];

    /**
     * The after_commit default each built-in connector applies, for the drivers that can enqueue outside the transaction.
     *
     * @var array<string, bool>
     */
    private const array ASYNC_DRIVERS = [
        'background' => true,
        'beanstalkd' => true,
        'database' => false,
        'deferred' => true,
        'redis' => true,
        'sqs' => true,
    ];

    /**
     * The dispatch settings of each job class and the files they come from, by class name.
     *
     * @var array<string, array{null|array{afterCommit: ?bool, afterCommitContract: bool, viaOutbox: ?bool, outboxContract: bool, connection: ?string, connectionAttribute: ?string, connectionOverridesAttribute: bool}, list<string>}>
     */
    private array $jobSettings = [];

    /**
     * The booted queue routes, or false when the registry does not resolve.
     *
     * @var null|array<string, array<int, null|string>|string>|false
     */
    private array|false|null $queueRoutes = null;

    /**
     * Whether booted queue forwards exist.
     */
    private bool $hasQueueForwards = false;

    /**
     * Create a new rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private ConfigParser $configParser,
        private InitializerExprTypeResolver $initializerExprTypeResolver,
        private ConstructorParser $constructorParser,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return Expr\CallLike::class;
    }

    /**
     * Report queued jobs dispatched inside a transaction that do not wait for it to commit.
     *
     * @param Expr\CallLike $node
     * @param DependencyTracker&Scope $scope
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ($node instanceof StaticCall || $node instanceof MethodCall) || ! $this->isTransactionCall($node, $scope)) {
            return [];
        }

        $callback = null;

        foreach ($node->getArgs() as $index => $arg) {
            if ($arg->name === null ? $index === 0 : $arg->name->name === 'callback') {
                $callback = $arg;
            }
        }

        $bodyNodes = $callback === null ? [] : $this->closureBody($callback->value);

        if ($bodyNodes === []) {
            return [];
        }

        $dispatches = [];
        $chainCalls = [];

        foreach ($bodyNodes as $bodyNode) {
            $this->visit($bodyNode, $scope, $dispatches, $chainCalls);
        }

        // An enclosing transaction's connection is unknown, and its open transaction may be the queue's.
        $owner = $this->isInDatabaseCall($scope, 'transaction') ? null : $this->getTransactionConnection($node, $scope);
        $errors = [];

        foreach ($dispatches as $dispatch) {
            $job = $this->getDispatchedJob($dispatch, $scope);

            if ($job === null) {
                continue;
            }

            $method = $dispatch instanceof StaticCall && $dispatch->name instanceof Identifier ? $dispatch->name->toLowerString() : 'dispatch';
            $condition = $method === 'dispatch' ? null : $dispatch->getArg('boolean', 0);

            if ($condition !== null && $this->getLiteralBoolean($condition, $scope) === [$method === 'dispatchunless']) {
                continue;
            }

            [$settings, $files] = $this->getJobSettings($job);

            foreach ($files as $file) {
                if ($file !== $scope->getFile()) {
                    $scope->trackFileDependency($file);
                }
            }

            if ($settings === null || ! $this->dispatchesBeforeCommit($job, $settings, $chainCalls[spl_object_id($dispatch)] ?? [], $owner, $scope)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Job %s is dispatched inside a transaction without deferring until commit.',
                $job->getDisplayName(),
            ))
                ->tip('Call afterCommit() on the dispatch, implement ShouldQueueAfterCommit, or enable after_commit on the queue connection.')
                ->identifier('hypervel.dispatchInTransactionAfterCommit')
                ->line($dispatch->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * Determine whether the call runs a transaction: `DB::transaction()` or a connection's `transaction()`.
     */
    private function isTransactionCall(StaticCall|MethodCall $node, Scope $scope): bool
    {
        if (! $node->name instanceof Identifier || $node->name->toLowerString() !== 'transaction') {
            return false;
        }

        return $node instanceof StaticCall
            ? $node->class instanceof Name && $this->isFacade($node->class, DB::class, 'DB')
            : (new ObjectType(ConnectionInterface::class))->isSuperTypeOf($scope->getType($node->var))->yes();
    }

    /**
     * Get the database connection the transaction runs on, or null when it is unknown.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function getTransactionConnection(StaticCall|MethodCall $node, Scope $scope): ?string
    {
        if ($node instanceof StaticCall) {
            return $this->getDatabaseConnection(null, $scope);
        }

        $receiver = $node->var;

        if (
            ! $receiver instanceof StaticCall
            || ! $receiver->class instanceof Name
            || ! $receiver->name instanceof Identifier
            || $receiver->name->toLowerString() !== 'connection'
            || ! $this->isFacade($receiver->class, DB::class, 'DB')
        ) {
            return null;
        }

        $nameArgument = $receiver->getArgs()[0] ?? null;
        $name = $nameArgument === null ? [null] : $this->getLiteralIdentifier($nameArgument, $scope);

        return $name === null ? null : $this->getDatabaseConnection($name[0], $scope);
    }

    /**
     * Get the database connection a name requests, resolving the default, or null when it is unknown.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function getDatabaseConnection(?string $name, Scope $scope): ?string
    {
        if ($name !== null && $name !== '') {
            return $name;
        }

        // DB::usingConnection() replaces the default while its callback runs.
        if ($this->isInDatabaseCall($scope, 'usingConnection')) {
            return null;
        }

        $default = $this->getConfigValue('database.default', $scope);

        return is_string($default[0] ?? null) && $default[0] !== '' ? $default[0] : null;
    }

    /**
     * Determine whether the code runs inside a callback of the given database method.
     */
    private function isInDatabaseCall(Scope $scope, string $method): bool
    {
        foreach (CallStack::enclosing($scope) as $reflection) {
            if (! $reflection instanceof MethodReflection || strtolower($reflection->getName()) !== strtolower($method)) {
                continue;
            }

            $class = $reflection->getDeclaringClass();

            if ($class->is(ConnectionInterface::class) || $class->is(DatabaseManager::class) || $class->is(DB::class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The statements or expression that make up the transaction callback's body,
     * or an empty list when the callback is not an inspectable closure literal.
     *
     * @return list<Node>
     */
    private function closureBody(Expr $callback): array
    {
        if ($callback instanceof Closure) {
            return array_values($callback->stmts);
        }

        if ($callback instanceof ArrowFunction) {
            return [$callback->expr];
        }

        return [];
    }

    /**
     * Walk the callback body, recording every dispatch call and the outermost call of
     * each setting in its method chain.
     *
     * @param list<FuncCall|StaticCall> $dispatches
     * @param array<int, array<string, MethodCall|NullsafeMethodCall>> $chainCalls
     */
    private function visit(Node $node, Scope $scope, array &$dispatches, array &$chainCalls): void
    {
        if ($node instanceof FunctionLike || $node instanceof ClassLike) {
            return;
        }

        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->name instanceof Identifier) {
            $setting = self::CHAIN_SETTINGS[$node->name->toLowerString()] ?? null;
            $dispatch = $setting === null ? null : $this->dispatchInReceiverChain($node, $scope);

            if ($setting !== null && $dispatch !== null) {
                // The outermost call executes last and is visited first.
                $chainCalls[spl_object_id($dispatch)][$setting] ??= $node;
            }
        }

        if (($node instanceof FuncCall || $node instanceof StaticCall) && $this->isDispatchCall($node, $scope)) {
            $dispatches[] = $node;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $sub = $node->{$subNodeName};
            $children = is_array($sub) ? $sub : [$sub];

            foreach ($children as $child) {
                if (! $child instanceof Node) {
                    continue;
                }

                $this->visit($child, $scope, $dispatches, $chainCalls);
            }
        }
    }

    /**
     * Determine whether the node dispatches a job through Dispatchable's dispatch(), dispatchIf()
     * or dispatchUnless(), the dispatch() helper or the Bus facade.
     */
    private function isDispatchCall(Node $node, Scope $scope): bool
    {
        if (! ($node instanceof FuncCall || $node instanceof StaticCall) || $node->isFirstClassCallable()) {
            return false;
        }

        if ($node instanceof FuncCall) {
            return $node->name instanceof Name
                && strtolower((string) $this->reflectionProvider->resolveFunctionName($node->name, $scope)) === 'dispatch';
        }

        if (
            ! $node->class instanceof Name
            || $node->class->isSpecialClassName()
            || ! $node->name instanceof Identifier
            || ! in_array($node->name->toLowerString(), ['dispatch', 'dispatchif', 'dispatchunless'], true)
        ) {
            return false;
        }

        // Unlike upstream, Bus::dispatch() is checked too: it queues the job the same way.
        if ($this->isFacade($node->class, Bus::class, 'Bus')) {
            return $node->name->toLowerString() === 'dispatch';
        }

        $className = $scope->resolveName($node->class);

        if (! $this->reflectionProvider->hasClass($className)) {
            return false;
        }

        $class = $this->reflectionProvider->getClass($className);
        $method = $node->name->toString();

        // A class's own dispatch method decides how it dispatches.
        return $class->hasNativeMethod($method)
            && $class->getNativeReflection()->getMethod($method)->getFileName() === $this->reflectionProvider->getClass(Dispatchable::class)->getFileName();
    }

    /**
     * Descend a method chain and return the pending dispatch it is applied to, for example
     * the `Job::dispatch()` in `Job::dispatch()->onQueue('x')->afterCommit()`.
     */
    private function dispatchInReceiverChain(MethodCall|NullsafeMethodCall $chainCall, Scope $scope): FuncCall|StaticCall|null
    {
        $current = $chainCall;

        while ($current instanceof MethodCall || $current instanceof NullsafeMethodCall) {
            $receiver = $current->var;

            if (($receiver instanceof FuncCall || $receiver instanceof StaticCall) && $this->isDispatchCall($receiver, $scope)) {
                // Bus::dispatch() returns the handler's result, not a pending dispatch.
                return $receiver instanceof StaticCall && $receiver->class instanceof Name && $this->isFacade($receiver->class, Bus::class, 'Bus')
                    ? null
                    : $receiver;
            }

            $current = $receiver;
        }

        return null;
    }

    /**
     * Get the class of the job a dispatch names, or null when the call does not name one.
     *
     * Unlike upstream, a job in a variable is not typed: the scope is the transaction call's,
     * which does not know the callback's own variables.
     */
    private function getDispatchedJob(FuncCall|StaticCall $dispatch, Scope $scope): ?ClassReflection
    {
        if ($dispatch instanceof StaticCall && $dispatch->class instanceof Name && ! $this->isFacade($dispatch->class, Bus::class, 'Bus')) {
            return $this->reflectionProvider->getClass($scope->resolveName($dispatch->class));
        }

        $argument = $dispatch->getArgs()[0] ?? null;
        $job = $argument === null || $argument->unpack ? null : $argument->value;

        // The helper queues a closure as a CallQueuedClosure.
        if ($job instanceof Closure || $job instanceof ArrowFunction) {
            return $dispatch instanceof FuncCall ? $this->reflectionProvider->getClass(CallQueuedClosure::class) : null;
        }

        if (! $job instanceof New_ || ! $job->class instanceof Name || $job->class->isSpecialClassName()) {
            return null;
        }

        $className = $scope->resolveName($job->class);

        return $this->reflectionProvider->hasClass($className) ? $this->reflectionProvider->getClass($className) : null;
    }

    /**
     * Get the job's dispatch settings and the files they come from. The settings are null
     * when the job is not queued through the connection, or its constructor may change them.
     *
     * @return array{null|array{afterCommit: ?bool, afterCommitContract: bool, viaOutbox: ?bool, outboxContract: bool, connection: ?string, connectionAttribute: ?string, connectionOverridesAttribute: bool}, list<string>}
     */
    private function getJobSettings(ClassReflection $job): array
    {
        if (array_key_exists($job->getName(), $this->jobSettings)) {
            return $this->jobSettings[$job->getName()];
        }

        // Property defaults, constructor bodies and the constants attribute arguments read are not
        // declarations, whose changes alone reanalyze the dispatching file.
        $files = SourceFiles::forInstance($this->reflectionProvider, $job, null);
        $attribute = ReflectionHelper::findClassAttribute($job, Connection::class);

        if ($attribute !== null) {
            $files = array_values(array_unique(array_merge($files, SourceFiles::forAttributeArguments($this->reflectionProvider, $attribute[2], Connection::class))));
        }

        $afterCommit = $this->getPropertyDefault($job, 'afterCommit');
        $viaOutbox = $this->getPropertyDefault($job, 'viaOutbox');
        $connection = $this->getPropertyDefault($job, 'connection');
        $connectionAttribute = $attribute === null ? [null] : $this->getIdentifier($attribute[0]->getArgumentTypes()['connection'] ?? null);
        $connectionProperty = $job->hasNativeProperty('connection') ? $job->getNativeProperty('connection') : null;

        // A job's own queue() method pushes it itself.
        if (
            ! $job->is(ShouldQueue::class)
            || $job->hasNativeMethod('queue')
            || $afterCommit === null || ! ($afterCommit[0] === null || is_bool($afterCommit[0]))
            || $viaOutbox === null || ! ($viaOutbox[0] === null || is_bool($viaOutbox[0]))
            || $connection === null || ! ($connection[0] === null || is_string($connection[0]))
            || $connectionAttribute === null
            || $this->constructorChangesSettings($job, $job)
        ) {
            return $this->jobSettings[$job->getName()] = [null, $files];
        }

        return $this->jobSettings[$job->getName()] = [
            [
                'afterCommit' => $afterCommit[0],
                'afterCommitContract' => $job->is(ShouldQueueAfterCommit::class),
                'viaOutbox' => $viaOutbox[0],
                'outboxContract' => $job->is(ShouldQueueViaOutbox::class),
                'connection' => $connection[0],
                'connectionAttribute' => $connectionAttribute[0],
                'connectionOverridesAttribute' => $attribute !== null
                    && $connectionProperty !== null
                    && $connectionProperty->isPublic()
                    && $connectionProperty->getDeclaringClass()->isSubclassOfClass($attribute[1]),
            ],
            $files,
        ];
    }

    /**
     * Get a property's default value, or null when it is not a constant. A missing or
     * uninitialized property reads as null, as isset() does.
     *
     * @return null|array{null|bool|float|int|string}
     */
    private function getPropertyDefault(ClassReflection $class, string $name): ?array
    {
        if (! $class->hasNativeProperty($name)) {
            return [null];
        }

        $property = $class->getNativeProperty($name);
        $expression = $property->getNativeReflection()->getBetterReflection()->getDefaultValueExpression();

        if ($expression === null) {
            return [null];
        }

        return $this->getConstantValue($this->initializerExprTypeResolver->getType(
            $expression,
            InitializerExprContext::fromClassReflection($property->getDeclaringClass()),
        ));
    }

    /**
     * Determine whether the class's constructor, run for the job, may change the job's dispatch settings.
     *
     * Calls on the job are not interpreted, except the parent constructor and Queueable's
     * own setters that change none of them. A parent constructor's calls reach the job's
     * own methods, so methods are looked up on the job.
     */
    private function constructorChangesSettings(ClassReflection $job, ClassReflection $class): bool
    {
        $constructor = $this->constructorParser->findConstructor($class);

        if ($constructor === null) {
            return false;
        }

        [$constructorNode, $declaringClass] = $constructor;

        foreach ($constructorNode->params as $param) {
            if ($param->isPromoted() && $param->var instanceof Variable && in_array($param->var->name, self::SETTING_PROPERTIES, true)) {
                return true;
            }
        }

        /** @var list<Assign|AssignOp|AssignRef|MethodCall|NullsafeMethodCall|StaticCall> $nodes */
        $nodes = (new NodeFinder)->find($constructorNode->stmts ?? [], static fn (Node $node): bool => $node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef || $node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall);

        foreach ($nodes as $node) {
            if ($node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef) {
                if (
                    ($node->var instanceof PropertyFetch || $node->var instanceof NullsafePropertyFetch)
                    && $this->isJob($node->var->var)
                    && (! $node->var->name instanceof Identifier || in_array($node->var->name->toString(), self::SETTING_PROPERTIES, true))
                ) {
                    return true;
                }

                continue;
            }

            if ($node instanceof StaticCall) {
                if (! $node->class instanceof Name || ! in_array($node->class->toLowerString(), ['self', 'static', 'parent'], true)) {
                    continue;
                }

                if ($node->class->toLowerString() === 'parent' && $node->name instanceof Identifier && $node->name->toLowerString() === '__construct') {
                    $parent = $declaringClass->getParentClass();

                    if ($parent !== null && $this->constructorChangesSettings($job, $parent)) {
                        return true;
                    }

                    continue;
                }
            } elseif (! $this->isJob($node->var)) {
                continue;
            }

            if (
                ! $node->name instanceof Identifier
                || ! in_array($node->name->toLowerString(), self::SETTING_FREE_METHODS, true)
                || ! $job->hasNativeMethod($node->name->toString())
                || $job->getNativeReflection()->getMethod($node->name->toString())->getFileName() !== $this->reflectionProvider->getClass(Queueable::class)->getFileName()
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a constructor expression is the job: `$this`, or a call chain on it.
     *
     * Each call in the chain is checked on its own, so the chain only continues on the job
     * through Queueable's setters, which return it.
     */
    private function isJob(Expr $expression): bool
    {
        while ($expression instanceof MethodCall || $expression instanceof NullsafeMethodCall) {
            $expression = $expression->var;
        }

        return $expression instanceof Variable && $expression->name === 'this';
    }

    /**
     * Determine whether the dispatch pushes the job before the transaction commits, to a queue outside it.
     *
     * @param array{afterCommit: ?bool, afterCommitContract: bool, viaOutbox: ?bool, outboxContract: bool, connection: ?string, connectionAttribute: ?string, connectionOverridesAttribute: bool} $settings
     * @param array<string, MethodCall|NullsafeMethodCall> $chainCalls
     * @param ?string $transactionConnection the transaction's database connection, or null when unknown
     * @param DependencyTracker&Scope $scope
     */
    private function dispatchesBeforeCommit(ClassReflection $job, array $settings, array $chainCalls, ?string $transactionConnection, Scope $scope): bool
    {
        $chain = [];

        foreach ($chainCalls as $setting => $call) {
            $value = $this->getChainSetting($call, $scope);

            if ($value === null) {
                return false;
            }

            $chain[$setting] = $value[0];
        }

        // afterResponse() runs the job synchronously once the response is sent.
        if (($chain['afterResponse'] ?? false) === true) {
            return false;
        }

        // Unlike upstream, a deliberate beforeCommit() or $afterCommit = false is respected: it
        // is a supported way to dispatch inside the transaction.
        if (array_key_exists('afterCommit', $chain) || $settings['afterCommit'] !== null || $settings['afterCommitContract']) {
            return false;
        }

        $viaOutbox = array_key_exists('viaOutbox', $chain) ? $chain['viaOutbox'] : $settings['viaOutbox'];

        if ($viaOutbox === true || ($viaOutbox === null && $settings['outboxContract'])) {
            return false;
        }

        $connection = $this->getQueueConnection($job, $settings, $chain, $scope);

        if ($connection === null) {
            return false;
        }

        // Failover, sync, null and custom drivers are not known to push outside the transaction.
        $driver = $this->getConfigValue("queue.connections.{$connection}.driver", $scope)[0] ?? null;

        if (! is_string($driver) || ! isset(self::ASYNC_DRIVERS[$driver])) {
            return false;
        }

        $afterCommit = $this->getConfigValue("queue.connections.{$connection}.after_commit", $scope, self::ASYNC_DRIVERS[$driver]);
        $outbox = $viaOutbox === null ? $this->getConfigValue("queue.connections.{$connection}.outbox", $scope, false) : [false];

        if (($afterCommit[0] ?? true) !== false || ($outbox[0] ?? true) !== false) {
            return false;
        }

        if ($driver !== 'database') {
            return true;
        }

        // A database queue on the transaction's own connection inserts the job inside the transaction.
        $queueDatabase = $this->getConfigValue("queue.connections.{$connection}.connection", $scope);
        $queueConnection = $queueDatabase !== null && ($queueDatabase[0] === null || is_string($queueDatabase[0]))
            ? $this->getDatabaseConnection($queueDatabase[0], $scope)
            : null;

        return $transactionConnection !== null
            && $queueConnection !== null
            && $this->usesSeparateDatabaseConnections($transactionConnection, $queueConnection, $scope);
    }

    /**
     * Get the value a dispatch chain call sets, or null when it is unknown.
     *
     * @return null|array{null|bool|string}
     */
    private function getChainSetting(MethodCall|NullsafeMethodCall $call, Scope $scope): ?array
    {
        if ($call->isFirstClassCallable() || ! $call->name instanceof Identifier) {
            return null;
        }

        $argument = $call->getArgs()[0] ?? null;

        return match ($call->name->toLowerString()) {
            'aftercommit', 'viaoutbox', 'onoutboxdatabase' => [true],
            'beforecommit', 'withoutoutbox' => [false],
            'onconnection', 'allonconnection' => $argument === null ? null : $this->getLiteralIdentifier($argument, $scope),
            'afterresponse' => $argument === null ? [true] : $this->getLiteralBoolean($argument, $scope),
            default => null,
        };
    }

    /**
     * Get the queue connection the job is pushed to, as the dispatcher selects it, or null when it is unknown.
     *
     * @param array{afterCommit: ?bool, afterCommitContract: bool, viaOutbox: ?bool, outboxContract: bool, connection: ?string, connectionAttribute: ?string, connectionOverridesAttribute: bool} $settings
     * @param array<string, null|bool|string> $chain
     * @param DependencyTracker&Scope $scope
     */
    private function getQueueConnection(ClassReflection $job, array $settings, array $chain, Scope $scope): ?string
    {
        // ReadsClassAttributes::getAttributeValue(): a property changed from its default wins, then the
        // attribute unless a public property declared below it overrides it, then the property.
        $default = $settings['connection'];
        /** @var ?string $property onConnection() and allOnConnection() set a connection name */
        $property = array_key_exists('connection', $chain) ? $chain['connection'] : $default;
        $connection = match (true) {
            $property !== null && $property !== $default => $property,
            $settings['connectionAttribute'] !== null => $settings['connectionOverridesAttribute'] && $property !== null
                ? $property
                : $settings['connectionAttribute'],
            default => $property,
        };

        // Booted routes and forwards may come from the environment, so they never establish a connection.
        if ($connection === null) {
            $this->runtimeInputs->track($scope);

            if ($this->routesMayChooseConnection($job)) {
                return null;
            }
        }

        if ($connection !== null && $connection !== '') {
            return $connection;
        }

        $default = $this->getConfigValue('queue.default', $scope);

        return is_string($default[0] ?? null) && $default[0] !== '' ? $default[0] : null;
    }

    /**
     * Determine whether booted queue routes or forwards may choose the connection of a job without one.
     */
    private function routesMayChooseConnection(ClassReflection $job): bool
    {
        if ($this->queueRoutes === null) {
            $routes = $this->resolve('queue.routes');
            $this->queueRoutes = $routes instanceof QueueRoutes ? $routes->all() : false;
            $this->hasQueueForwards = $routes instanceof QueueRoutes && (fn (): bool => $this->forwards !== [])->call($routes);
        }

        if ($this->queueRoutes === false || $this->hasQueueForwards) {
            return true;
        }

        // QueueRoutes::getRoute() checks the class, its parents, interfaces and traits in that order.
        foreach ([$job->getName(), ...$job->getParentClassesNames(), ...array_keys($job->getInterfaces()), ...array_keys($job->getTraits(true))] as $class) {
            if (isset($this->queueRoutes[$class])) {
                return is_array($this->queueRoutes[$class]) && ($this->queueRoutes[$class][0] ?? null) !== null;
            }
        }

        return false;
    }

    /**
     * Determine whether two requested database connections are held separately.
     *
     * Each requested name, role included, has its own connection, except role aliases of a
     * shared in-memory SQLite database.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function usesSeparateDatabaseConnections(string $first, string $second, Scope $scope): bool
    {
        if ($first === $second) {
            return false;
        }

        $base = ConnectionName::parse($first)->base;

        if ($base !== ConnectionName::parse($second)->base) {
            return true;
        }

        $driver = $this->getConfigValue("database.connections.{$base}.driver", $scope)[0] ?? null;

        return is_string($driver) && $driver !== 'sqlite';
    }

    /**
     * Get a config value when it is a known constant, or null when it is unknown. An absent
     * key gives the default.
     *
     * @param DependencyTracker&Scope $scope
     * @return null|array{null|bool|float|int|string}
     */
    private function getConfigValue(string $key, Scope $scope, bool|string|null $default = null): ?array
    {
        $value = $this->configParser->getValue($key, $scope);

        if ($value === null) {
            return null;
        }

        [$presence, $type] = $value;

        if ($presence->no()) {
            return [$default];
        }

        return $presence->yes() ? $this->getConstantValue($type) : null;
    }

    /**
     * Get the identifier a literal argument gives, or null when it is not a literal: the scope is the
     * transaction call's, which does not know the callback's own variables.
     *
     * @return null|array{?string}
     */
    private function getLiteralIdentifier(Arg $argument, Scope $scope): ?array
    {
        return $this->isLiteral($argument) ? $this->getIdentifier($scope->getType($argument->value)) : null;
    }

    /**
     * Get the boolean a literal argument gives, or null when it is not a literal boolean.
     *
     * @return null|array{bool}
     */
    private function getLiteralBoolean(Arg $argument, Scope $scope): ?array
    {
        $value = $this->isLiteral($argument) ? $this->getConstantValue($scope->getType($argument->value)) : null;

        return $value !== null && is_bool($value[0]) ? [$value[0]] : null;
    }

    /**
     * Determine whether the argument is a literal whose type does not depend on the scope.
     */
    private function isLiteral(Arg $argument): bool
    {
        return ! $argument->unpack
            && ($argument->value instanceof String_ || $argument->value instanceof ConstFetch || $argument->value instanceof ClassConstFetch);
    }

    /**
     * Get the connection name a type gives, normalized as enum_value() does, or null when it is unknown.
     *
     * @return null|array{?string}
     */
    private function getIdentifier(?Type $type): ?array
    {
        if ($type === null) {
            return null;
        }

        $enumCases = $type->getEnumCases();

        if (count($enumCases) === 1 && $enumCases[0]->equals($type)) {
            $backingValues = $enumCases[0]->getBackingValueType()?->getConstantScalarValues() ?? [];

            return [count($backingValues) === 1 ? (string) $backingValues[0] : $enumCases[0]->getEnumCaseName()];
        }

        $value = $this->getConstantValue($type);

        return $value !== null && ($value[0] === null || is_string($value[0])) ? [$value[0]] : null;
    }

    /**
     * Get the single constant value of a type, or null when it has none.
     *
     * @return null|array{null|bool|float|int|string}
     */
    private function getConstantValue(Type $type): ?array
    {
        if ($type->isNull()->yes()) {
            return [null];
        }

        $values = $type->getConstantScalarValues();

        return count($values) === 1 ? [$values[0]] : null;
    }
}
