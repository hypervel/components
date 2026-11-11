<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\PHPStan\Methods\ModelScopeMethodResolver;
use Hypervel\PHPStan\Properties\ModelHelper;
use Hypervel\PHPStan\Properties\ModelPropertyHelper;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\PHPStan\Support\CollectionHelper;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ReflectionException;

use function array_all;
use function array_any;
use function array_diff;
use function array_keys;
use function array_map;
use function array_reverse;
use function count;
use function in_array;
use function is_int;
use function is_string;
use function sprintf;
use function str_starts_with;
use function strtolower;

/**
 * This rule checks for unnecessary heavy operations on the Collection class
 * that could have instead been performed on the Builder class.
 *
 * For example:
 * User::all()->count()
 * could be simplified to:
 * User::count()
 *
 * In addition, this code:
 * User::whereStatus('active')->get()->pluck('id')
 * could be simplified to:
 * User::whereStatus('active')->pluck('id')
 *
 * Unlike upstream, a call is reported only when the query method returns the same result, which needs the whole
 * query in view: a DB::table() query, or a model query whose global scopes, collection and attribute reads are
 * the framework's, built from filters, ordering, eager loads, joins and limits and fetched with get() or all().
 * Relations, variables, selects, macros and named scopes stay silent.
 *
 * @implements Rule<MethodCall>
 */
#[Conditional(attribute: new AutowiredService, if: '%noUnnecessaryCollectionCall%')]
class NoUnnecessaryCollectionCallRule implements Rule
{
    /**
     * The method names that can be applied on a Collection, but should be applied on a Builder, with the
     * query method that returns the same result.
     *
     * Hypervel: upstream also reports last(), pop(), skip(), slice(), diff(), except(), find(), only(), contains(),
     * containsStrict(), firstWhere(), where(), whereStrict() and the aggregates, whose query forms differ: last()
     * and pop() have none, skip() and slice() keep keys, diff(), except(), find() and only() compare keys loosely,
     * and SQL compares, casts and aggregates values differently from PHP.
     */
    protected const array REPLACEMENTS = [
        'count' => 'count()',
        'first' => 'first()',
        'isempty' => 'doesntExist()',
        'isnotempty' => 'exists()',
        'modelkeys' => 'modelKeys()',
        'pluck' => 'pluck()',
        'take' => 'take()->get()',
    ];

    /**
     * The builder methods that only filter the rows a query returns.
     */
    private const array FILTERS = [
        'doesnthave', 'doesnthavemorph', 'has', 'hasmorph', 'ordoesnthave', 'ordoesnthavemorph', 'orhas', 'orhasmorph',
        'orwhere', 'orwhereaftertoday', 'orwhereall', 'orwhereany', 'orwhereattachedto', 'orwherebeforetoday',
        'orwherebelongsto', 'orwherebetween', 'orwherebetweencolumns', 'orwherebinary', 'orwherecolumn', 'orwheredate',
        'orwhereday', 'orwheredoesnthave', 'orwheredoesnthavemorph', 'orwheredoesnthaverelation', 'orwhereexists',
        'orwherefulltext', 'orwherefuture', 'orwherehas', 'orwherehasmorph', 'orwherein', 'orwhereintegerinraw',
        'orwhereintegernotinraw', 'orwherejsoncontains', 'orwherejsoncontainskey', 'orwherejsondoesntcontain',
        'orwherejsondoesntcontainkey', 'orwherejsondoesntoverlap', 'orwherejsonlength', 'orwherejsonoverlaps',
        'orwherekey', 'orwherekeynot', 'orwherelike', 'orwheremonth', 'orwheremorphdoesnthaverelation',
        'orwheremorphedto', 'orwheremorphrelation', 'orwherenone', 'orwherenot', 'orwherenotbetween',
        'orwherenotbetweencolumns', 'orwherenotbinary', 'orwherenotexists', 'orwherenotin', 'orwherenotlike',
        'orwherenotmorphedto', 'orwherenotnull', 'orwherenoworfuture', 'orwherenoworpast', 'orwherenull',
        'orwherenullsafeequals', 'orwherepast', 'orwhereraw', 'orwhererelation', 'orwhererowvalues', 'orwheretime',
        'orwheretoday', 'orwheretodayorafter', 'orwheretodayorbefore', 'orwherevaluebetween',
        'orwherevaluenotbetween', 'orwherevectordistancelessthan', 'orwhereyear', 'where', 'whereaftertoday',
        'whereall', 'whereany', 'whereattachedto', 'wherebeforetoday', 'wherebelongsto', 'wherebetween',
        'wherebetweencolumns', 'wherebinary', 'wherecolumn', 'wheredate', 'whereday', 'wheredoesnthave',
        'wheredoesnthavemorph', 'wheredoesnthaverelation', 'whereexists', 'wherefulltext', 'wherefuture', 'wherehas',
        'wherehasmorph', 'wherein', 'whereintegerinraw', 'whereintegernotinraw', 'wherejsoncontains',
        'wherejsoncontainskey', 'wherejsondoesntcontain', 'wherejsondoesntcontainkey', 'wherejsondoesntoverlap',
        'wherejsonlength', 'wherejsonoverlaps', 'wherekey', 'wherekeynot', 'wherelike', 'wheremonth',
        'wheremorphdoesnthaverelation', 'wheremorphedto', 'wheremorphrelation', 'wherenested', 'wherenone',
        'wherenot', 'wherenotbetween', 'wherenotbetweencolumns', 'wherenotbinary', 'wherenotexists', 'wherenotin',
        'wherenotlike', 'wherenotmorphedto', 'wherenotnull', 'wherenoworfuture', 'wherenoworpast', 'wherenull',
        'wherenullsafeequals', 'wherepast', 'whereraw', 'whererelation', 'whererowvalues', 'wheretime', 'wheretoday',
        'wheretodayorafter', 'wheretodayorbefore', 'wherevaluebetween', 'wherevaluenotbetween',
        'wherevectordistancelessthan', 'wherevectorsimilarto', 'whereyear', 'withwherehas', 'withwhererelation',
    ];

    /**
     * The builder methods that order the rows a query returns.
     */
    private const array ORDERS = [
        'inorderof', 'inrandomorder', 'latest', 'oldest', 'orderby', 'orderbydesc', 'orderbyraw', 'orderbyvectordistance',
        'reorder', 'reorderdesc',
    ];

    /**
     * The builder methods that choose the relations a model query eager loads.
     */
    private const array EAGER_LOADS = ['with', 'without', 'withonly'];

    /**
     * The builder methods that join tables, whose columns a model query's default select also fetches.
     */
    private const array JOINS = [
        'crossjoin', 'crossjoinsub', 'join', 'joinlateral', 'joinsub', 'joinwhere', 'leftjoin', 'leftjoinlateral',
        'leftjoinsub', 'leftjoinwhere', 'rightjoin', 'rightjoinsub', 'rightjoinwhere', 'straightjoin',
        'straightjoinsub', 'straightjoinwhere',
    ];

    /**
     * The builder methods that limit or offset the rows a query returns.
     */
    private const array LIMITS = ['forpage', 'limit', 'offset', 'skip', 'take'];

    /**
     * The collection methods a recommended method calls, which a custom collection may override.
     */
    private const array DELEGATED_METHODS = [
        'isnotempty' => ['isEmpty'],
        'pluck' => ['toBase', 'all'],
        'take' => ['slice', 'newInstance'],
    ];

    /**
     * The model methods that hydrate a model and read its attributes.
     *
     * A collection reads each fetched model, while Builder::pluck() returns the fetched value of an attribute
     * without a get accessor, cast or date, and otherwise reads it from a model holding that attribute alone.
     * So an override, such as a translated attribute value, can change only the collection's result.
     */
    private const array ATTRIBUTE_METHODS = [
        'newFromBuilder',
        'newInstance',
        'setRawAttributes',
        'getAttribute',
        'getAttributeValue',
        'getAttributeFromArray',
        'transformModelValue',
        '__get',
        'hasAnyGetMutator',
        'hasCast',
        'getCasts',
        'getDates',
        'castAttribute',
    ];

    /**
     * The model methods a collection's pluck() reads attributes through, with data_get().
     */
    private const array PLUCK_METHODS = ['offsetExists', 'offsetGet', '__isset'];

    /**
     * The model methods that name and read a model's key, which a collection's modelKeys() reads from each fetched
     * model and Builder::modelKeys() names from the query's model.
     */
    private const array KEY_METHODS = ['getKey', 'getKeyName', 'getQualifiedKeyName'];

    /** @var string[] The method names that should be checked by this rule - can be configured by the user. */
    protected array $shouldHandle;

    /**
     * Whether each model hydrates and reads attributes as Model does, by class name.
     *
     * @var array<string, bool>
     */
    private array $plainAttributeReads = [];

    /**
     * Create a new no unnecessary collection call rule instance.
     *
     * @param string[] $onlyMethods
     * @param string[] $excludeMethods
     */
    public function __construct(
        protected ReflectionProvider $reflectionProvider,
        private ModelRuleHelper $modelRuleHelper,
        private ModelHelper $modelHelper,
        private ModelPropertyHelper $modelPropertyHelper,
        private ModelScopeMethodResolver $modelScopeMethodResolver,
        private CollectionHelper $collectionHelper,
        private RuntimeInputs $runtimeInputs,
        #[AutowiredParameter(ref: '%noUnnecessaryCollectionCallOnly%')]
        array $onlyMethods,
        #[AutowiredParameter(ref: '%noUnnecessaryCollectionCallExcept%')]
        array $excludeMethods,
    ) {
        $allMethods = array_keys(self::REPLACEMENTS);

        if (! empty($onlyMethods)) {
            $this->shouldHandle = array_map(static function (string $methodName): string {
                return Str::lower($methodName);
            }, $onlyMethods);
        } else {
            $this->shouldHandle = $allMethods;
        }

        if (empty($excludeMethods)) {
            return;
        }

        $this->shouldHandle = array_diff($this->shouldHandle, array_map(static function (string $methodName): string {
            return Str::lower($methodName);
        }, $excludeMethods));
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * Report collection methods called on a query's results that the query itself could have returned.
     *
     * @param DependencyTracker&Scope $scope
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }

        $name = $node->name;

        if (! in_array($name->toLowerString(), $this->shouldHandle, true)
            || ! isset(self::REPLACEMENTS[$name->toLowerString()])
            || ! $this->isReplaceableByQuery($node, $name->toLowerString(), $scope)) {
            return [];
        }

        return [$this->formatError($name->toString(), self::REPLACEMENTS[$name->toLowerString()])];
    }

    /**
     * Determine whether the query the collection was fetched with returns the method's result itself.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function isReplaceableByQuery(MethodCall $call, string $method, Scope $scope): bool
    {
        $fetch = $call->var;

        if (! ($fetch instanceof MethodCall || $fetch instanceof StaticCall)
            || ! $fetch->name instanceof Identifier
            || $fetch->isFirstClassCallable()) {
            return false;
        }

        $fetchName = $fetch->name->toLowerString();

        // A pluck() fetch keeps one value per row, so it counts and tests rows as the query does.
        $fetched = match ($fetchName) {
            'get' => $fetch->getArgs() === [],
            'all' => $fetch instanceof StaticCall && $fetch->getArgs() === [],
            'pluck' => in_array($method, ['count', 'isempty', 'isnotempty'], true) && $this->constantStringArgument($fetch, $scope) !== null,
            default => false,
        };

        if (! $fetched) {
            return false;
        }

        $arguments = $call->getArgs();
        $column = $method === 'pluck' ? $this->constantStringArgument($call, $scope) : null;
        $takes = $method === 'take' && count($arguments) === 1 ? $this->constantValues($arguments[0], $scope) : null;

        $accepted = match ($method) {
            // A negative take() keeps the last rows, while the query ignores a negative limit.
            'take' => $takes !== null && array_all($takes, static fn (mixed $value): bool => is_int($value) && $value >= 0),
            'pluck' => $column !== null,
            default => $arguments === [],
        };

        $query = $accepted ? $this->queryChain($fetch, $scope) : null;

        if ($query === null) {
            return false;
        }

        // The booted global and query macros decide what a link name calls.
        $this->runtimeInputs->track($scope);

        $models = $query['models'];
        $joins = false;
        $limits = [];

        foreach ($query['links'] as [$linkName, $link]) {
            if ($models === null
                ? ! $this->isQueryLink($linkName)
                : ! array_all($models, fn (ClassReflection $model): bool => $this->isEloquentLink($model, $linkName))) {
                return false;
            }

            $joins = $joins || in_array(strtolower($linkName), self::JOINS, true);

            if (in_array(strtolower($linkName), self::LIMITS, true)) {
                $limits[] = $link;
            }
        }

        if (! $this->returnsFrameworkCollection($models, $fetchName, $method)) {
            return false;
        }

        // count() counts the whole query, and take() would replace the query's limit. Builder::pluck() and
        // modelKeys() select one unqualified column, while a model query's default select takes joined columns too.
        return match ($method) {
            'first' => $this->limitsToAtLeastOneRow(array_reverse($limits), $scope),
            'isempty', 'isnotempty' => true,
            'count', 'take' => $limits === [],
            'pluck' => $models !== null && ! $joins && $column !== null
                && array_all($models, fn (ClassReflection $model): bool => $this->plucksLikeCollection($model, $column)),
            'modelkeys' => $models !== null && ! $joins
                && array_all($models, fn (ClassReflection $model): bool => $this->plucksKeysLikeCollection($model)),
            default => false,
        };
    }

    /**
     * Get the models a fetch's query is built on (null for a DB::table() query) and the methods building it,
     * from the fetch back to the root, or null when the whole query is not in view.
     *
     * @return null|array{models: null|list<ClassReflection>, links: list<array{string, MethodCall|StaticCall}>}
     */
    private function queryChain(MethodCall|StaticCall $fetch, Scope $scope): ?array
    {
        $links = [];
        $root = $fetch;

        while ($root instanceof MethodCall) {
            $root = $root->var;

            if (! ($root instanceof MethodCall || $root instanceof StaticCall)
                || ! $root->name instanceof Identifier
                || $root->isFirstClassCallable()) {
                return null;
            }

            if ($this->isTableQuery($root, $scope)) {
                return ['models' => null, 'links' => $links];
            }

            if ($root instanceof MethodCall) {
                $links[] = [$root->name->toString(), $root];
            }
        }

        $models = $this->modelRuleHelper->exactCalledClasses($root, $scope);

        if ($models === null || ! $root->name instanceof Identifier) {
            return null;
        }

        $name = $root->name->toString();
        $entryPoints = $root === $fetch ? ['all'] : ['query', 'with'];

        foreach ($models as $model) {
            if (! $model->is(Model::class) || ! $this->modelRuleHelper->buildsPlainQueries($model)) {
                return null;
            }

            // Model's own static methods start the query, and any other name reaches it through __callStatic().
            if ($model->hasNativeMethod($name)
                && (! in_array(strtolower($name), $entryPoints, true)
                    || $model->getNativeMethod($name)->getDeclaringClass()->getName() !== Model::class)) {
                return null;
            }
        }

        if ($root !== $fetch && ! in_array(strtolower($name), $entryPoints, true)) {
            $links[] = [$name, $root];
        }

        return ['models' => $models, 'links' => $links];
    }

    /**
     * Determine whether the call starts a query with DB::table(), or the table() of a DB::connection().
     */
    private function isTableQuery(MethodCall|StaticCall $call, Scope $scope): bool
    {
        if (! $call->name instanceof Identifier || $call->name->toLowerString() !== 'table' || ! $this->hasConstantArguments($call, $scope)) {
            return false;
        }

        if ($call instanceof StaticCall) {
            return $this->isDatabaseFacade($call);
        }

        $connection = $call->var;

        return $connection instanceof StaticCall
            && $connection->name instanceof Identifier
            && $connection->name->toLowerString() === 'connection'
            && $this->isDatabaseFacade($connection)
            && $this->hasConstantArguments($connection, $scope);
    }

    /**
     * Determine whether the static call is on the DB facade.
     */
    private function isDatabaseFacade(StaticCall $call): bool
    {
        return $call->class instanceof Name
            && $this->reflectionProvider->hasClass($call->class->toString())
            && $this->reflectionProvider->getClass($call->class->toString())->getName() === DB::class;
    }

    /**
     * Determine whether each of the call's arguments is a constant value.
     */
    private function hasConstantArguments(MethodCall|StaticCall $call, Scope $scope): bool
    {
        return ! $call->isFirstClassCallable()
            && array_all($call->getArgs(), fn (Arg $argument): bool => $this->constantValues($argument, $scope) !== null);
    }

    /**
     * Determine whether a model query method only filters, orders, eager loads, joins or limits the query, as
     * Builder::__call() resolves it.
     */
    private function isEloquentLink(ClassReflection $model, string $name): bool
    {
        $builder = $this->reflectionProvider->getClass(EloquentBuilder::class);

        if ($builder->hasNativeMethod($name)) {
            return $builder->getNativeMethod($name)->isPublic() && $this->isListedLink($name);
        }

        // A plain model's query has no local macros, and a global macro or named scope runs before the query's method.
        return ! EloquentBuilder::hasGlobalMacro($name)
            && $this->modelScopeMethodResolver->resolve($model, $name) === null
            && $this->isQueryLink($name);
    }

    /**
     * Determine whether a base query method only filters, orders, joins or limits the query, as Builder::__call()
     * resolves it.
     */
    private function isQueryLink(string $name): bool
    {
        $query = $this->reflectionProvider->getClass(QueryBuilder::class);

        if ($query->hasNativeMethod($name)) {
            return $query->getNativeMethod($name)->isPublic() && $this->isListedLink($name);
        }

        // Any other where... name that no macro takes is a dynamic where.
        return ! QueryBuilder::hasMacro($name) && str_starts_with($name, 'where');
    }

    /**
     * Determine whether a native builder method is one the rule follows.
     */
    private function isListedLink(string $name): bool
    {
        $name = strtolower($name);

        return in_array($name, self::FILTERS, true)
            || in_array($name, self::ORDERS, true)
            || in_array($name, self::EAGER_LOADS, true)
            || in_array($name, self::JOINS, true)
            || in_array($name, self::LIMITS, true);
    }

    /**
     * Determine whether the query's limit is absent or at least one row, which first() replaces with one row.
     *
     * @param list<MethodCall|StaticCall> $limits the limit and offset calls, in the order they apply
     */
    private function limitsToAtLeastOneRow(array $limits, Scope $scope): bool
    {
        $values = [null];

        foreach ($limits as $limit) {
            $name = $limit->name instanceof Identifier ? $limit->name->toLowerString() : '';

            if ($name === 'offset' || $name === 'skip') {
                continue;
            }

            $arguments = $limit->getArgs();

            // forPage() limits the query to its second argument, 15 rows by default.
            $values = $name === 'forpage' && count($arguments) === 1
                ? [15]
                : $this->constantValues($arguments[$name === 'forpage' ? 1 : 0] ?? null, $scope);

            if ($values === null || array_any($arguments, static fn (Arg $argument): bool => $argument->name !== null || $argument->unpack)) {
                return false;
            }
        }

        return array_all($values, static fn (mixed $value): bool => $value === null || (is_int($value) && $value >= 1));
    }

    /**
     * Determine whether the query fetches the collection with the framework's constructor and methods, since a
     * custom collection can count, test or read its models differently.
     *
     * @param null|list<ClassReflection> $models
     */
    private function returnsFrameworkCollection(?array $models, string $fetch, string $method): bool
    {
        $collectionNames = [Collection::class];

        // Builder::get() collects the models with the model's newCollection(), and pluck() returns a base collection.
        if ($models !== null && $fetch !== 'pluck') {
            $collectionNames = [];

            foreach ($models as $model) {
                if (! $this->collectionHelper->usesFrameworkNewCollection($model)) {
                    return false;
                }

                $collectionNames = [...$collectionNames, ...$this->collectionHelper->determineCollectionClass($model->getName())->getObjectClassNames()];
            }
        }

        $methods = ['__construct', $method, ...self::DELEGATED_METHODS[$method] ?? []];

        return $collectionNames !== [] && array_all(
            $collectionNames,
            fn (string $collectionName): bool => $this->reflectionProvider->hasClass($collectionName)
                && CollectionHelper::usesFrameworkMethods($this->reflectionProvider->getClass($collectionName), $methods),
        );
    }

    /**
     * Determine whether Builder::pluck() returns the attribute's values as the collection reads them from its models.
     */
    private function plucksLikeCollection(ClassReflection $model, string $column): bool
    {
        return $this->modelRuleHelper->usesModelMethods($model, self::PLUCK_METHODS) && $this->readsColumnAsFetched($model, $column);
    }

    /**
     * Determine whether Builder::modelKeys() returns the keys the collection reads from its models.
     */
    private function plucksKeysLikeCollection(ClassReflection $model): bool
    {
        if (! $this->modelRuleHelper->usesModelMethods($model, self::KEY_METHODS)) {
            return false;
        }

        try {
            $keyName = $this->modelHelper->getInstance($model)->getKeyName();
        } catch (ReflectionException) {
            return false;
        }

        return $keyName !== null && $this->readsColumnAsFetched($model, $keyName);
    }

    /**
     * Determine whether a model reads the column as the query fetches it alone: a fetched column whose value no
     * get accessor or class cast computes from other attributes.
     */
    private function readsColumnAsFetched(ClassReflection $model, string $column): bool
    {
        return $this->readsAttributesAsModel($model)
            && in_array($column, $this->modelPropertyHelper->getDatabasePropertyNames($model) ?? [], true)
            && ! $this->modelRuleHelper->isComputedProperty($model, $column);
    }

    /**
     * Determine whether the model hydrates and reads attributes as Model does, with no retrieved handler that
     * could change them.
     */
    private function readsAttributesAsModel(ClassReflection $model): bool
    {
        return $this->plainAttributeReads[$model->getName()] ??= $this->resolvePlainAttributeReads($model);
    }

    /**
     * Resolve whether the model hydrates and reads attributes as Model does.
     */
    private function resolvePlainAttributeReads(ClassReflection $model): bool
    {
        if (! $this->modelRuleHelper->usesModelMethods($model, self::ATTRIBUTE_METHODS)
            || ReflectionHelper::findClassAttribute($model, ObservedBy::class) !== null) {
            return false;
        }

        try {
            $instance = $this->modelHelper->getInstance($model);
        } catch (ReflectionException) {
            return false;
        }

        $dispatcher = Model::getEventDispatcher();

        return ! isset($instance->dispatchesEvents()['retrieved'])
            && ($dispatcher === null || ! $dispatcher->hasListeners('eloquent.retrieved: ' . $model->getName()));
    }

    /**
     * Get the constant values a positional argument can have, or null when it can have others.
     *
     * @return null|list<null|bool|float|int|string>
     */
    private function constantValues(?Arg $argument, Scope $scope): ?array
    {
        if ($argument === null || $argument->unpack || $argument->name !== null) {
            return null;
        }

        $type = $scope->getType($argument->value);
        $values = $type->getConstantScalarValues();

        return $type->isConstantValue()->yes() && $values !== [] ? $values : null;
    }

    /**
     * Get the call's single argument when it is one constant string.
     */
    private function constantStringArgument(MethodCall|StaticCall $call, Scope $scope): ?string
    {
        $arguments = $call->getArgs();
        $values = count($arguments) === 1 ? $this->constantValues($arguments[0], $scope) : null;

        return $values !== null && count($values) === 1 && is_string($values[0]) ? $values[0] : null;
    }

    /**
     * Format the error.
     */
    protected function formatError(string $methodName, string $replacement): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            "Called '%s' on Hypervel collection, but could have been retrieved as a query with '%s'.",
            $methodName,
            $replacement,
        ))
            ->identifier('hypervel.noUnnecessaryCollectionCall')
            ->build();
    }
}
