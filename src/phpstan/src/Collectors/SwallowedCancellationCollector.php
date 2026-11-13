<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ForeignCancellationException;
use Hypervel\Coroutine\Parallel;
use Hypervel\Coroutine\Waiter;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\MassPrunable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Prunable;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Relations\HasOneOrMany;
use Hypervel\Database\Eloquent\Relations\HasOneOrManyThrough;
use Hypervel\Database\Eloquent\Relations\HasOneThrough;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\MorphOne;
use Hypervel\Database\Eloquent\Relations\MorphOneOrMany;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use Hypervel\Database\Schema\MySqlBuilder;
use Hypervel\Database\Schema\PostgresBuilder;
use Hypervel\Database\Schema\SQLiteBuilder;
use Hypervel\Engine\Channel as EngineChannel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\PHPStan\Methods\Macros\SoftDeletingMacros;
use Hypervel\PHPStan\Reflection\ForwardedMethodReflection;
use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\PHPStan\Rules\Coroutine\CoroutineRuleHelper;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\PHPStan\Support\ConstructorParser;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Concurrency;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Redis;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Sleep;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\Php\PhpMethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel as SwooleChannel;
use Swoole\Coroutine\WaitGroup as SwooleWaitGroup;

use function array_all;
use function array_any;
use function array_filter;
use function array_slice;
use function count;
use function end;
use function in_array;
use function is_array;
use function is_string;
use function strtolower;

/**
 * Collects a try whose block runs an operation that can deliver cancellation, where the catch receiving
 * the cancellation doesn't propagate it. The rule reports those that run in a request, job or operation.
 *
 * @phpstan-import-type FunctionFacts from WorkerStateHelper
 *
 * @phpstan-type SwallowedCancellation array{line: int, operation: string, function: FunctionFacts}
 *
 * @implements Collector<TryCatch, SwallowedCancellation>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkCancellationHandling%')]
class SwallowedCancellationCollector implements Collector
{
    private const string DATABASE = 'a database query';

    private const string REDIS = 'a Redis command';

    private const string HTTP = 'an HTTP request';

    private const string WAIT = 'a wait';

    /**
     * The functions that wait, so a cancellation can reach them while they run.
     */
    private const array FUNCTIONS = [
        'sleep' => true,
        'usleep' => true,
        'hypervel\coroutine\parallel' => true,
        'hypervel\coroutine\wait' => true,
    ];

    /**
     * The lowercase connection methods that run a query.
     */
    private const array CONNECTION_METHODS = [
        'select' => true, 'selectone' => true, 'selectfromwriteconnection' => true, 'selectresultsets' => true,
        'scalar' => true, 'insert' => true, 'update' => true, 'delete' => true, 'statement' => true,
        'affectingstatement' => true, 'unprepared' => true, 'threadcount' => true, 'transaction' => true,
        'begintransaction' => true, 'commit' => true, 'rollback' => true,
    ];

    /**
     * The lowercase query builder methods that run a query.
     */
    private const array QUERY_METHODS = [
        'get' => true, 'first' => true, 'firstorfail' => true, 'sole' => true, 'find' => true, 'findor' => true,
        'value' => true, 'rawvalue' => true, 'solevalue' => true, 'pluck' => true, 'pluckwithcolumn' => true,
        'implode' => true, 'count' => true, 'min' => true, 'max' => true, 'sum' => true, 'avg' => true, 'average' => true,
        'aggregate' => true, 'numericaggregate' => true, 'exists' => true, 'existsor' => true, 'doesntexist' => true,
        'doesntexistor' => true, 'getcountforpagination' => true, 'explain' => true, 'insert' => true,
        'insertgetid' => true, 'insertorignore' => true, 'insertorignorereturning' => true, 'insertusing' => true,
        'insertorignoreusing' => true, 'update' => true, 'updatefrom' => true, 'updateorinsert' => true,
        'upsert' => true, 'delete' => true, 'truncate' => true, 'increment' => true, 'incrementeach' => true,
        'decrement' => true, 'decrementeach' => true, 'paginate' => true, 'simplepaginate' => true,
        'cursorpaginate' => true, 'chunk' => true, 'chunkmap' => true, 'chunkbyid' => true, 'chunkbyiddesc' => true,
        'orderedchunkbyid' => true, 'each' => true, 'eachbyid' => true,
    ];

    /**
     * The lowercase Eloquent builder methods beyond the query builder's that run a query.
     */
    private const array ELOQUENT_METHODS = [
        'firstwhere' => true, 'firstor' => true, 'findsole' => true, 'findorfail' => true, 'findornew' => true,
        'findmany' => true, 'valueorfail' => true, 'getmodels' => true, 'fromquery' => true, 'fillandinsert' => true,
        'fillandinsertorignore' => true, 'fillandinsertgetid' => true, 'create' => true, 'createquietly' => true,
        'forcecreate' => true, 'forcecreatequietly' => true, 'firstorcreate' => true, 'firstornew' => true,
        'updateorcreate' => true, 'createorfirst' => true, 'incrementorcreate' => true, 'touch' => true,
        'forcedelete' => true,
    ];

    /**
     * The lowercase model methods that run a query, including the protected increments its __call() runs.
     */
    private const array MODEL_METHODS = [
        'all' => true, 'destroy' => true, 'forcedestroy' => true, 'save' => true, 'savequietly' => true,
        'saveorfail' => true, 'saveorignore' => true, 'update' => true, 'updatequietly' => true, 'updateorfail' => true,
        'push' => true, 'pushquietly' => true, 'delete' => true, 'deletequietly' => true, 'deleteorfail' => true,
        'forcedelete' => true, 'refresh' => true, 'refreshforupdate' => true, 'fresh' => true, 'touch' => true,
        'touchquietly' => true, 'increment' => true, 'incrementquietly' => true, 'incrementeach' => true,
        'incrementeachquietly' => true, 'decrement' => true, 'decrementquietly' => true, 'decrementeach' => true,
        'decrementeachquietly' => true, 'load' => true, 'loadmissing' => true, 'loadaggregate' => true,
        'loadcount' => true, 'loadmax' => true, 'loadmin' => true, 'loadsum' => true, 'loadavg' => true,
        'loadexists' => true, 'loadmorph' => true, 'loadmorphaggregate' => true, 'loadmorphcount' => true,
        'loadmorphmax' => true, 'loadmorphmin' => true, 'loadmorphsum' => true, 'loadmorphavg' => true,
    ];

    /**
     * The lowercase Eloquent collection methods that load from the database.
     */
    private const array COLLECTION_METHODS = [
        'load' => true, 'loadmissing' => true, 'loadmissingrelationshipchain' => true, 'loadaggregate' => true,
        'loadcount' => true, 'loadmax' => true, 'loadmin' => true, 'loadsum' => true, 'loadavg' => true,
        'loadexists' => true, 'loadmorph' => true, 'loadmorphcount' => true, 'fresh' => true,
    ];

    /**
     * The lowercase relation methods that find or create related models, on the relations that declare them.
     */
    private const array RELATION_CREATE_METHODS = [
        'findornew' => true, 'firstornew' => true, 'firstorcreate' => true, 'createorfirst' => true,
        'restoreorcreate' => true, 'createorrestore' => true, 'updateorcreate' => true, 'incrementorcreate' => true,
    ];

    /**
     * The lowercase relation methods that save related models, on the relations that declare them.
     */
    private const array RELATION_SAVE_METHODS = [
        'save' => true, 'savequietly' => true, 'savemany' => true, 'savemanyquietly' => true, 'create' => true,
        'createquietly' => true, 'createmany' => true, 'createmanyquietly' => true, 'forcecreate' => true,
        'forcecreatequietly' => true,
    ];

    /**
     * The lowercase relation methods that query related models, on the relations that declare them.
     */
    private const array RELATION_QUERY_METHODS = [
        'find' => true, 'findsole' => true, 'findmany' => true, 'findorfail' => true, 'findor' => true,
        'firstwhere' => true, 'first' => true, 'firstorfail' => true, 'firstor' => true, 'get' => true,
        'paginate' => true, 'simplepaginate' => true, 'cursorpaginate' => true, 'chunk' => true, 'chunkmap' => true,
        'chunkbyid' => true, 'chunkbyiddesc' => true, 'each' => true, 'eachbyid' => true, 'touch' => true,
        'allrelatedids' => true,
    ];

    /**
     * The lowercase schema builder methods that run a statement or read the schema. The base builder's
     * dropAllTables(), dropAllViews() and dropAllTypes() only throw, so only driver overrides count.
     */
    private const array SCHEMA_METHODS = [
        'createdatabase' => true, 'dropdatabaseifexists' => true, 'getschemas' => true, 'hastable' => true,
        'hasview' => true, 'gettables' => true, 'gettablelisting' => true, 'getviews' => true, 'gettypes' => true,
        'hascolumn' => true, 'hascolumns' => true, 'whentablehascolumn' => true, 'whentabledoesnthavecolumn' => true,
        'whentablehasindex' => true, 'whentabledoesnthaveindex' => true, 'getcolumntype' => true,
        'getcolumnlisting' => true, 'getcolumn' => true, 'getcolumns' => true, 'getindexes' => true,
        'getindexlisting' => true, 'hasindex' => true, 'hasforeignkey' => true, 'getforeignkeys' => true,
        'getpartitions' => true, 'table' => true, 'create' => true, 'createrangepartition' => true,
        'createmigrationrepositorytable' => true, 'drop' => true, 'dropifexists' => true, 'dropcolumns' => true,
        'truncatetables' => true, 'rename' => true, 'enableforeignkeyconstraints' => true, 'disableforeignkeyconstraints' => true,
        'withoutforeignkeyconstraints' => true, 'ensureextensionexists' => true, 'ensurevectorextensionexists' => true,
        'executeblueprint' => true,
    ];

    /**
     * The lowercase pivot table methods of a many-to-many relation that write the pivot table.
     */
    private const array PIVOT_METHODS = [
        'attach' => true, 'attachorfail' => true, 'detach' => true, 'detachorfail' => true, 'sync' => true,
        'syncorfail' => true, 'syncwithoutdetaching' => true, 'syncwithoutdetachingorfail' => true,
        'syncwithpivotvalues' => true, 'syncwithpivotvaluesorfail' => true, 'toggle' => true, 'toggleorfail' => true,
        'updateexistingpivot' => true, 'updateexistingpivotorfail' => true,
    ];

    /**
     * The lowercase HTTP client methods that send a request.
     */
    private const array HTTP_METHODS = [
        'send' => true, 'get' => true, 'post' => true, 'put' => true, 'patch' => true, 'delete' => true, 'head' => true,
    ];

    /**
     * The operation each class's or trait's own declarations run, with the lowercase methods that run it.
     * An override is judged by its own declaration, so its class has no entry.
     */
    private const array OPERATIONS = [
        ConnectionInterface::class => [self::DATABASE, self::CONNECTION_METHODS],
        Connection::class => [self::DATABASE, self::CONNECTION_METHODS],
        PdoConnection::class => [self::DATABASE, self::CONNECTION_METHODS],
        DatabaseManager::class => [self::DATABASE, self::CONNECTION_METHODS],
        DB::class => [self::DATABASE, self::CONNECTION_METHODS],
        QueryBuilder::class => [self::DATABASE, self::QUERY_METHODS],
        EloquentBuilder::class => [self::DATABASE, self::QUERY_METHODS + self::ELOQUENT_METHODS],
        Model::class => [self::DATABASE, self::MODEL_METHODS],
        SoftDeletes::class => [self::DATABASE, [
            'restore' => true, 'restorequietly' => true, 'forcedelete' => true, 'forcedeletequietly' => true,
            'forcedestroy' => true,
        ]],
        // The builder macros SoftDeletingScope adds, which BuilderMacroResolver resolves to this interface.
        SoftDeletingMacros::class => [self::DATABASE, ['restore' => true, 'restoreorcreate' => true, 'createorrestore' => true]],
        Prunable::class => [self::DATABASE, ['prune' => true, 'pruneall' => true]],
        MassPrunable::class => [self::DATABASE, ['pruneall' => true]],
        EloquentCollection::class => [self::DATABASE, self::COLLECTION_METHODS],
        Relation::class => [self::DATABASE, [
            'get' => true, 'geteager' => true, 'sole' => true, 'touch' => true, 'rawupdate' => true,
        ]],
        HasOneOrMany::class => [self::DATABASE, self::RELATION_CREATE_METHODS + self::RELATION_SAVE_METHODS + [
            'forcecreatemany' => true, 'forcecreatemanyquietly' => true, 'upsert' => true,
        ]],
        HasOne::class => [self::DATABASE, ['getresults' => true]],
        HasMany::class => [self::DATABASE, ['getresults' => true]],
        MorphOneOrMany::class => [self::DATABASE, ['forcecreate' => true, 'upsert' => true]],
        MorphOne::class => [self::DATABASE, ['getresults' => true]],
        MorphMany::class => [self::DATABASE, ['getresults' => true, 'forcecreate' => true]],
        BelongsTo::class => [self::DATABASE, ['getresults' => true, 'touch' => true]],
        MorphTo::class => [self::DATABASE, ['geteager' => true, 'touch' => true]],
        BelongsToMany::class => [self::DATABASE, self::RELATION_CREATE_METHODS + self::RELATION_SAVE_METHODS
            + self::RELATION_QUERY_METHODS + self::PIVOT_METHODS + ['getresults' => true, 'orderedchunkbyid' => true]],
        HasOneOrManyThrough::class => [self::DATABASE, self::RELATION_CREATE_METHODS + self::RELATION_QUERY_METHODS],
        HasOneThrough::class => [self::DATABASE, ['getresults' => true]],
        HasManyThrough::class => [self::DATABASE, ['getresults' => true]],
        Pivot::class => [self::DATABASE, ['delete' => true]],
        MorphPivot::class => [self::DATABASE, ['delete' => true]],
        SchemaBuilder::class => [self::DATABASE, self::SCHEMA_METHODS],
        // The facade runs the configured driver's builder, whose drop-all methods run statements.
        Schema::class => [self::DATABASE, self::SCHEMA_METHODS + [
            'dropalltables' => true, 'dropallviews' => true, 'dropalltypes' => true,
        ]],
        MySqlBuilder::class => [self::DATABASE, ['dropalltables' => true, 'dropallviews' => true]],
        PostgresBuilder::class => [self::DATABASE, [
            'executeblueprint' => true, 'hasview' => true, 'dropalltables' => true, 'dropallviews' => true,
            'dropalltypes' => true, 'getcurrentschemaname' => true,
        ]],
        // Its database creation and removal work on the database file, not through the connection.
        SQLiteBuilder::class => [self::DATABASE, [
            'executeblueprint' => true, 'gettables' => true, 'getviews' => true, 'getcolumns' => true,
            'getcolumnsforschemastate' => true, 'getindexesforschemastate' => true,
            'getforeignkeysforschemastate' => true, 'dropalltables' => true, 'dropallviews' => true, 'pragma' => true,
            'enableforeignkeyconstraints' => true, 'disableforeignkeyconstraints' => true,
        ]],
        PendingRequest::class => [self::HTTP, self::HTTP_METHODS],
        Http::class => [self::HTTP, self::HTTP_METHODS],
        SwooleChannel::class => [self::WAIT, ['push' => true, 'pop' => true]],
        EngineChannel::class => [self::WAIT, ['push' => true, 'pop' => true]],
        SwooleWaitGroup::class => [self::WAIT, ['wait' => true]],
        WaitGroup::class => [self::WAIT, ['wait' => true]],
        Waiter::class => [self::WAIT, ['wait' => true]],
        Parallel::class => [self::WAIT, ['wait' => true]],
        Concurrency::class => [self::WAIT, ['run' => true]],
        SwooleCoroutine::class => [self::WAIT, ['join' => true, 'sleep' => true]],
        Coroutine::class => [self::WAIT, ['join' => true, 'sleep' => true]],
        EngineCoroutine::class => [self::WAIT, ['join' => true]],
        Sleep::class => [self::WAIT, ['then' => true]],
    ];

    /**
     * The lowercase static methods that create a sleep.
     */
    private const array SLEEP_FACTORIES = ['for' => true, 'until' => true, 'usleep' => true, 'sleep' => true];

    /**
     * The classes whose declarations run Redis commands.
     */
    private const array REDIS_CLASSES = [RedisProxy::class, RedisConnection::class, Redis::class];

    /**
     * The lowercase Redis methods, beyond the commands, that run a command while they're called.
     */
    private const array REDIS_METHODS = [
        'command' => true, 'withconnection' => true, 'withpinnedconnection' => true,
        'scan' => true, 'hscan' => true, 'sscan' => true, 'zscan' => true,
    ];

    /**
     * Create a new collector instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private WorkerStateHelper $helper,
        private CoroutineRuleHelper $coroutineRuleHelper,
        private ConstructorParser $constructorParser,
    ) {
    }

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return TryCatch::class;
    }

    /**
     * Collect the try when the catch receiving cancellation swallows it and the block can deliver one.
     *
     * @param TryCatch $node
     * @param DependencyTracker&Scope $scope
     *
     * @return null|SwallowedCancellation
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $catch = $this->findCancellationCatch($node, $scope);

        if ($catch === null || $this->propagates($catch, $scope)) {
            return null;
        }

        // ExactCancellationCatchRule reports an exact catch that keeps the cancellation apart from a later broad catch.
        foreach ($this->coroutineRuleHelper->findExactCancellationMisses($node, $scope) as [$exactCatch]) {
            if ($exactCatch === $catch) {
                return null;
            }
        }

        $operation = $this->findOperation($node->stmts, $scope);
        $function = $operation === null ? null : $this->helper->getFunctionFacts($scope, $node);

        return $operation === null || $function === null
            ? null
            : ['line' => $catch->getStartLine(), 'operation' => $operation, 'function' => $function];
    }

    /**
     * Find the first catch that receives CanceledException. Later catches never receive it.
     */
    private function findCancellationCatch(TryCatch $node, Scope $scope): ?Catch_
    {
        $cancellation = $this->reflectionProvider->getClass(CanceledException::class);

        foreach ($node->catches as $catch) {
            foreach ($catch->types as $type) {
                if ($cancellation->is($scope->resolveName($type))) {
                    return $catch;
                }
            }
        }

        return null;
    }

    /**
     * Determine whether the catch propagates the cancellation: the statements a cancellation runs end by
     * rethrowing the exception or wrapping it as the previous exception. A Coroutine::causedByCancellation()
     * guard starting the body narrows them to its branch, or to the statements after a negated guard.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function propagates(Catch_ $catch, Scope $scope): bool
    {
        if ($catch->var === null || ! is_string($catch->var->name)) {
            return false;
        }

        $variable = $catch->var->name;
        $first = $catch->stmts[0] ?? null;

        if ($this->rethrows($catch->stmts, $variable, $scope)) {
            return true;
        }

        if (! $first instanceof If_) {
            return false;
        }

        return match ($this->coroutineRuleHelper->guardPolarity($first->cond, $variable)) {
            true => $this->rethrows($first->stmts, $variable, $scope),
            false => $first->elseifs === [] && $first->else === null && $this->rethrows(array_slice($catch->stmts, 1), $variable, $scope),
            null => false,
        };
    }

    /**
     * Determine whether the statements end by throwing the exception or wrapping it, with nothing leaving them first.
     *
     * @param array<Stmt> $statements
     * @param DependencyTracker&Scope $scope
     */
    private function rethrows(array $statements, string $variable, Scope $scope): bool
    {
        $last = end($statements);

        if (! $last instanceof Expression || ! $last->expr instanceof Throw_ || $this->leaves($statements, 0)) {
            return false;
        }

        // The caught exception must reach the throw unchanged, or replaced only by top-level wrappers that keep
        // it as their previous exception. A nested catch binding the same name replaces it too.
        $writes = $this->coroutineRuleHelper->find(
            $statements,
            fn (Node $node): bool => $this->coroutineRuleHelper->assigns($node, $variable) || ($node instanceof Catch_ && $node->var?->name === $variable),
        );

        foreach ($writes as $write) {
            if (! $write instanceof Assign
                || ! array_any($statements, static fn (Stmt $statement): bool => $statement instanceof Expression && $statement->expr === $write)
                || ! $this->wraps($write->expr, $variable, $scope)
            ) {
                return false;
            }
        }

        $thrown = $last->expr->expr;

        return ($thrown instanceof Variable && $thrown->name === $variable) || $this->wraps($thrown, $variable, $scope);
    }

    /**
     * Determine whether the expression creates an exception that keeps the variable as its previous exception.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function wraps(Expr $expr, string $variable, Scope $scope): bool
    {
        if (! $expr instanceof New_ || ! $expr->class instanceof Name || ! $this->reflectionProvider->hasClass($scope->resolveName($expr->class))) {
            return false;
        }

        $exception = $this->reflectionProvider->getClass($scope->resolveName($expr->class));

        // Exception constructor bodies decide which argument becomes the previous exception.
        foreach (SourceFiles::forClass($exception, null) as $file) {
            if ($file !== $scope->getFile()) {
                $scope->trackFileDependency($file);
            }
        }

        // Coroutine::causedByCancellation() stops at a foreign cancellation, so wrapping in one hides it.
        return ! $exception->is(ForeignCancellationException::class)
            && $this->forwardsAsPrevious($exception, $expr->getArgs(), $variable);
    }

    /**
     * Determine whether the nodes can leave the catch without throwing: a return, or a break or continue
     * leaving more loops and switches than the given depth plus those the nodes open. Nested functions
     * and classes don't run here.
     *
     * @param array<Node> $nodes
     */
    private function leaves(array $nodes, int $depth): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof Return_) {
                return true;
            }

            if ($node instanceof Break_ || $node instanceof Continue_) {
                if (($node->num instanceof Int_ ? $node->num->value : 1) > $depth) {
                    return true;
                }

                continue;
            }

            if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                continue;
            }

            $innerDepth = $node instanceof For_ || $node instanceof Foreach_ || $node instanceof While_ || $node instanceof Do_ || $node instanceof Switch_
                ? $depth + 1
                : $depth;

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $children = $node->{$subNodeName};

                if ($this->leaves(array_filter(is_array($children) ? $children : [$children], static fn (mixed $child): bool => $child instanceof Node), $innerDepth)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine whether the constructor the arguments are passed to makes the variable the previous
     * exception: it binds to a native constructor's $previous, or an overriding constructor passes the
     * parameter it binds to on to parent::__construct() as the previous exception.
     *
     * @param array<Arg> $args
     */
    private function forwardsAsPrevious(ClassReflection $exception, array $args, string $variable): bool
    {
        if (! $exception->hasConstructor()) {
            return false;
        }

        $constructor = $exception->getConstructor();
        $parameters = $constructor->getOnlyVariant()->getParameters();
        $parameter = null;

        foreach ($args as $position => $argument) {
            if ($argument->unpack) {
                return false;
            }

            if ($argument->value instanceof Variable && $argument->value->name === $variable) {
                $parameter = $argument->name?->toString() ?? ($parameters[$position] ?? null)?->getName();

                break;
            }
        }

        if ($parameter === null) {
            return false;
        }

        if ($constructor->getDeclaringClass()->isBuiltin()) {
            return $parameter === 'previous';
        }

        [$constructorNode, $declaringClass] = $this->constructorParser->findConstructor($exception) ?? [null, null];
        $parent = $declaringClass?->getParentClass();

        if ($constructorNode === null || $parent === null) {
            return false;
        }

        $statements = $constructorNode->stmts ?? [];

        // Only a top-level parent::__construct() runs on every construction, and the parameter must reach it
        // unchanged, with no return before it.
        foreach ($statements as $statement) {
            $call = $statement instanceof Expression ? $statement->expr : null;

            if (! $call instanceof StaticCall
                || ! $call->class instanceof Name
                || $call->class->toLowerString() !== 'parent'
                || ! $call->name instanceof Identifier
                || $call->name->toLowerString() !== '__construct'
            ) {
                continue;
            }

            $earlier = $this->coroutineRuleHelper->find(
                $statements,
                fn (Node $node): bool => $node->getStartFilePos() < $call->getStartFilePos()
                    && ($node instanceof Return_ || $this->coroutineRuleHelper->assigns($node, $parameter)),
            );

            return $earlier === [] && $this->forwardsAsPrevious($parent, $call->getArgs(), $parameter);
        }

        return false;
    }

    /**
     * Find the first operation in the block that can deliver cancellation, a call or a discarded sleep, outside nested functions.
     *
     * @param array<Stmt> $statements
     * @param DependencyTracker&Scope $scope
     */
    private function findOperation(array $statements, Scope $scope): ?string
    {
        // A nested try whose receiving catch swallows the cancellation keeps its block's operations from this
        // catch, and is reported itself. Its catch and finally bodies still run in this block.
        $swallowed = [];

        foreach ($this->coroutineRuleHelper->find($statements, static fn (Node $node): bool => $node instanceof TryCatch) as $try) {
            $catch = $try instanceof TryCatch && $try->stmts !== [] ? $this->findCancellationCatch($try, $scope) : null;

            if ($catch !== null && ! $this->propagates($catch, $scope)) {
                $swallowed[] = [$try->stmts[0]->getStartFilePos(), $try->stmts[count($try->stmts) - 1]->getEndFilePos()];
            }
        }

        $nodes = $this->coroutineRuleHelper->find(
            $statements,
            static fn (Node $node): bool => ($node instanceof Expression || ($node instanceof CallLike && ! $node->isFirstClassCallable()))
                && ! array_any($swallowed, static fn (array $range): bool => $range[0] <= $node->getStartFilePos() && $node->getStartFilePos() <= $range[1]),
        );

        foreach ($nodes as $node) {
            $operation = match (true) {
                $node instanceof Expression => $this->sleepsWhenDiscarded($node->expr, $scope) ? self::WAIT : null,
                $node instanceof CallLike => $this->getOperation($node, $statements, $scope),
                default => null,
            };

            if ($operation !== null) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * Get the operation the call runs on every class it may be made on, matched by the framework's own declarations.
     *
     * @param array<Stmt> $statements
     */
    private function getOperation(CallLike $call, array $statements, Scope $scope): ?string
    {
        if ($call instanceof FuncCall) {
            return $call->name instanceof Name
                && isset(self::FUNCTIONS[strtolower($this->reflectionProvider->resolveFunctionName($call->name, $scope) ?? '')])
                    ? self::WAIT
                    : null;
        }

        if (! ($call instanceof MethodCall || $call instanceof NullsafeMethodCall || $call instanceof StaticCall) || ! $call->name instanceof Identifier) {
            return null;
        }

        $name = $call->name->toString();

        // The scope is the try's, so a receiver built in the block is typed from what the block assigns. The classes
        // keep their type arguments, since a builder's macros depend on its model.
        $classes = match (true) {
            ! $call instanceof StaticCall => $this->coroutineRuleHelper->getReceiverType($call->var, $statements, $scope)?->getObjectClassReflections() ?? [],
            $call->class instanceof Name => $scope->resolveTypeByName($call->class)->getObjectClassReflections(),
            default => $this->coroutineRuleHelper->getReceiverType($call->class, $statements, $scope)?->getObjectTypeOrClassStringObjectType()->getObjectClassReflections() ?? [],
        };

        $operation = null;

        foreach ($classes as $class) {
            if (! $class->hasMethod($name)) {
                return null;
            }

            $method = $class->getMethod($name, $scope);

            // A model or Eloquent builder forwards some methods to the builder or query builder that runs them.
            // PHPStan wraps the reflection an extension gives, which keeps the forwarded one as its prototype.
            while (($prototype = $method->getPrototype()) instanceof ForwardedMethodReflection) {
                $method = $prototype->getForwardedMethod();
            }

            $declaringClass = $method->getDeclaringClass()->getName();

            // The class using a trait declares its methods, so an application model's SoftDeletes methods are matched by their trait.
            // @phpstan-ignore phpstanApi.instanceofAssumption (Only PhpMethodReflection exposes a native method's declaring trait. The SoftDeletes test fails if that changes.)
            if (! isset(self::OPERATIONS[$declaringClass]) && $method instanceof PhpMethodReflection && $method->getDeclaringTrait() !== null) {
                $declaringClass = $method->getDeclaringTrait()->getName();
            }

            [$classOperation, $methods] = self::OPERATIONS[$declaringClass] ?? [null, []];

            if (in_array($declaringClass, self::REDIS_CLASSES, true)) {
                $classOperation = $this->runsRedisCommand($method->getName(), $call) ? self::REDIS : null;
            } elseif (! isset($methods[strtolower($method->getName())])
                // Sleep::then() sleeps through goodnight(), which a subclass may override.
                || ($declaringClass === Sleep::class && ! $this->runsFrameworkSleep($class, ['goodnight']))
            ) {
                $classOperation = null;
            }

            if ($classOperation === null) {
                return null;
            }

            $operation = $classOperation;
        }

        return $operation;
    }

    /**
     * Determine whether the expression creates a framework sleep and discards it, so it sleeps as it's
     * destroyed at the end of the statement: new or a factory method, followed by fluent methods. A sleep
     * held in a variable sleeps when that variable releases it.
     */
    private function sleepsWhenDiscarded(Expr $expr, Scope $scope): bool
    {
        // The creating method, the fluent methods, the destructor and the sleep it runs must all be the framework's.
        $methods = ['__destruct', 'goodnight'];
        $root = $expr;

        while ($root instanceof MethodCall && $root->name instanceof Identifier) {
            $methods[] = $root->name->toString();
            $root = $root->var;
        }

        if ($root instanceof New_) {
            $methods[] = '__construct';
        } elseif ($root instanceof StaticCall && $root->name instanceof Identifier && isset(self::SLEEP_FACTORIES[$root->name->toLowerString()])) {
            $methods[] = $root->name->toString();
        } else {
            return false;
        }

        if (! $root->class instanceof Name || ! $this->reflectionProvider->hasClass($scope->resolveName($root->class))) {
            return false;
        }

        $sleep = $this->reflectionProvider->getClass($scope->resolveName($root->class));

        // The statement's value must still be the sleep, not a result such as then()'s.
        return $sleep->is(Sleep::class)
            && $this->runsFrameworkSleep($sleep, $methods)
            && (new ObjectType(Sleep::class))->isSuperTypeOf($scope->getType($expr))->yes();
    }

    /**
     * Determine whether the sleep class runs the framework's own declarations of the methods.
     *
     * @param list<string> $methods
     */
    private function runsFrameworkSleep(ClassReflection $sleep, array $methods): bool
    {
        return array_all(
            $methods,
            static fn (string $method): bool => $sleep->hasNativeMethod($method) && $sleep->getNativeMethod($method)->getDeclaringClass()->getName() === Sleep::class,
        );
    }

    /**
     * Determine whether the Redis method runs a command while it's called. A pipeline or transaction
     * without a callback returns a client that queues commands for later.
     */
    private function runsRedisCommand(string $method, MethodCall|NullsafeMethodCall|StaticCall $call): bool
    {
        $lowercase = strtolower($method);

        if ($lowercase === 'pipeline' || $lowercase === 'transaction') {
            $callback = $call->getArg('callback', 0)?->value;

            return $callback !== null && ! ($callback instanceof ConstFetch && $callback->name->toLowerString() === 'null');
        }

        $proxy = $this->reflectionProvider->getClass(RedisProxy::class);

        // Commands reach the connection through the proxy's __call(), so the proxy doesn't declare them natively.
        return isset(self::REDIS_METHODS[$lowercase]) || (! $proxy->hasNativeMethod($method) && $proxy->hasMethod($method));
    }
}
