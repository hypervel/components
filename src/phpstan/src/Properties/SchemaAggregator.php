<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;
use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\SchemaParser\ColumnType;
use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\View;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use PhpParser;
use PhpParser\NodeFinder;
use PHPStan\Reflection\InitializerExprContext;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionException;
use WeakMap;

use function array_any;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function class_basename;
use function class_uses_recursive;
use function count;
use function in_array;
use function is_string;
use function mb_strtolower;
use function spl_object_id;
use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * Applies migrations to the schema facts of the connections they run on.
 *
 * Only constructs whose effect is certain are interpreted; anything else makes the facts it
 * could affect unknown, so that inference never claims a column is missing without proof.
 *
 * A state is a copy of the facts: the connections, whether an earlier migration could have changed
 * any connection, and the morph key type. A scope holds the migration class, the connection its
 * statements default to (null when it is unknown), inside a Blueprint callback the table it changes
 * and the Blueprint's variable, and the local variables holding Schema builders: those whose calls
 * are followed, and those that escape, whose uses make the connections they select unknown.
 *
 * @phpstan-type State array{array<string, SchemaConnection>, bool, ?string}
 * @phpstan-type TableScope array{connection: ConnectionConfig, key: string, variable: string}
 * @phpstan-type BuilderVariables array{builders: array<string, ?ConnectionConfig>, escaped: array<string, list<?ConnectionConfig>>}
 * @phpstan-type Scope array{class: PhpParser\Node\Stmt\Class_, connection: ?ConnectionConfig, table: ?TableScope, builders: array<string, ?ConnectionConfig>, escaped: array<string, list<?ConnectionConfig>>}
 *
 * @see https://github.com/psalm/laravel-psalm-plugin/blob/master/src/SchemaAggregator.php
 */
class SchemaAggregator
{
    /** Schema builder methods that change no relations or column types. withoutForeignKeyConstraints() runs a callback that is applied on its own. */
    private const array IGNORED_SCHEMA_METHODS = [
        'createdatabase', 'defaultstringlength', 'defaulttimeprecision', 'disableforeignkeyconstraints',
        'enableforeignkeyconstraints', 'getcolumn', 'getcolumnlisting', 'getcolumns', 'getcolumntype', 'getconnection',
        'getcurrentschemalisting', 'getcurrentschemaname', 'getforeignkeys', 'getindexes', 'getindexlisting',
        'getpartitions', 'getschemas', 'gettablelisting', 'gettables', 'gettypes', 'getviews', 'hascolumn',
        'hascolumns', 'hasforeignkey', 'hasindex', 'hastable', 'hasview', 'parseschemaandtable', 'qualifyindexname',
        'truncatetables', 'withoutcoveredpartitions', 'withoutforeignkeyconstraints',
    ];

    /** Schema builder methods that change one table, by the position and name of their table parameter. */
    private const array TABLE_SCHEMA_METHODS = [
        'create' => [0, 'table'], 'createrangepartition' => [1, 'partition'], 'drop' => [0, 'table'],
        'dropcolumns' => [0, 'table'], 'dropifexists' => [0, 'table'], 'table' => [0, 'table'],
        'whentabledoesnthavecolumn' => [0, 'table'], 'whentabledoesnthaveindex' => [0, 'table'],
        'whentablehascolumn' => [0, 'table'], 'whentablehasindex' => [0, 'table'],
    ];

    /** Schema builder methods that change the key type morphs() adds. */
    private const array MORPH_KEY_SCHEMA_METHODS = ['defaultmorphkeytype', 'morphusingulids', 'morphusinguuids'];

    /** Blueprint methods that add one column, with the Blueprint type they add. */
    private const array COLUMN_METHODS = [
        'bigincrements' => 'biginteger', 'biginteger' => 'biginteger', 'binary' => 'binary', 'boolean' => 'boolean',
        'char' => 'char', 'date' => 'date', 'datetime' => 'datetime', 'datetimetz' => 'datetimetz',
        'decimal' => 'decimal', 'double' => 'double', 'enum' => 'enum', 'float' => 'float', 'foreignid' => 'biginteger',
        'foreignulid' => 'char', 'foreignuuid' => 'uuid', 'geography' => 'geography', 'geometry' => 'geometry',
        'id' => 'biginteger', 'increments' => 'integer', 'integer' => 'integer', 'integerincrements' => 'integer',
        'ipaddress' => 'ipaddress', 'json' => 'json', 'jsonb' => 'jsonb', 'longbinary' => 'longbinary',
        'longtext' => 'longtext', 'macaddress' => 'macaddress', 'mediumbinary' => 'mediumbinary',
        'mediumincrements' => 'mediuminteger', 'mediuminteger' => 'mediuminteger', 'mediumtext' => 'mediumtext',
        'rawcolumn' => 'raw', 'remembertoken' => 'string', 'set' => 'set', 'smallincrements' => 'smallinteger',
        'smallinteger' => 'smallinteger', 'softdeletes' => 'timestamp', 'softdeletesdatetime' => 'datetime',
        'softdeletestz' => 'timestamptz', 'string' => 'string', 'text' => 'text', 'time' => 'time',
        'timestamp' => 'timestamp', 'timestamptz' => 'timestamptz', 'timetz' => 'timetz',
        'tinyincrements' => 'tinyinteger', 'tinyinteger' => 'tinyinteger', 'tinytext' => 'tinytext',
        'tsvector' => 'tsvector', 'ulid' => 'char', 'unsignedbiginteger' => 'biginteger',
        'unsignedinteger' => 'integer', 'unsignedmediuminteger' => 'mediuminteger',
        'unsignedsmallinteger' => 'smallinteger', 'unsignedtinyinteger' => 'tinyinteger', 'uuid' => 'uuid',
        'vector' => 'vector', 'year' => 'year',
    ];

    /** The column names Blueprint methods use when none is given. */
    private const array DEFAULT_COLUMN_NAMES = [
        'id' => 'id', 'ipaddress' => 'ip_address', 'macaddress' => 'mac_address', 'remembertoken' => 'remember_token',
        'softdeletes' => 'deleted_at', 'softdeletesdatetime' => 'deleted_at', 'softdeletestz' => 'deleted_at',
        'ulid' => 'ulid', 'uuid' => 'uuid',
    ];

    /** Blueprint methods that add an unsigned column. */
    private const array UNSIGNED_COLUMN_METHODS = [
        'bigincrements', 'foreignid', 'id', 'increments', 'integerincrements', 'mediumincrements', 'smallincrements',
        'tinyincrements', 'unsignedbiginteger', 'unsignedinteger', 'unsignedmediuminteger', 'unsignedsmallinteger',
        'unsignedtinyinteger',
    ];

    /** Blueprint methods whose third parameter decides whether the column is unsigned. */
    private const array SIGNABLE_COLUMN_METHODS = ['biginteger', 'integer', 'mediuminteger', 'smallinteger', 'tinyinteger'];

    /** Blueprint methods that add a nullable column. */
    private const array NULLABLE_COLUMN_METHODS = ['remembertoken', 'softdeletes', 'softdeletesdatetime', 'softdeletestz'];

    /** Blueprint methods that change no columns. */
    private const array IGNORED_BLUEPRINT_METHODS = [
        'charset', 'collation', 'comment', 'create', 'dropforeign', 'dropfulltext', 'dropindex', 'dropprimary',
        'dropspatialindex', 'dropunique', 'dropvectorindex', 'engine', 'foreign', 'fulltext', 'index', 'innodb',
        'partitionbyrange', 'primary', 'rawindex', 'renameindex', 'spatialindex', 'temporary', 'unique', 'vectorindex',
    ];

    /**
     * The type names each driver's schema grammar creates Blueprint types with, by Blueprint type.
     *
     * The grammar is not run: MySQL's date and year types read the live server version. Spatial,
     * vector and raw types have no fixed name, so their columns are unknown.
     */
    private const array GRAMMAR_TYPES = [
        'mysql' => [
            'biginteger' => 'bigint', 'binary' => 'blob', 'boolean' => 'tinyint', 'char' => 'char', 'date' => 'date',
            'datetime' => 'datetime', 'datetimetz' => 'datetime', 'decimal' => 'decimal', 'double' => 'double',
            'enum' => 'enum', 'float' => 'float', 'integer' => 'int', 'ipaddress' => 'varchar', 'json' => 'json',
            'jsonb' => 'json', 'longbinary' => 'longblob', 'longtext' => 'longtext', 'macaddress' => 'varchar',
            'mediumbinary' => 'mediumblob', 'mediuminteger' => 'mediumint', 'mediumtext' => 'mediumtext', 'set' => 'set',
            'smallinteger' => 'smallint', 'string' => 'varchar', 'text' => 'text', 'time' => 'time', 'timetz' => 'time',
            'timestamp' => 'timestamp', 'timestamptz' => 'timestamp', 'tinyinteger' => 'tinyint',
            'tinytext' => 'tinytext', 'uuid' => 'char', 'year' => 'year',
        ],
        'pgsql' => [
            'biginteger' => 'int8', 'binary' => 'bytea', 'boolean' => 'bool', 'char' => 'bpchar', 'date' => 'date',
            'datetime' => 'timestamp', 'datetimetz' => 'timestamptz', 'decimal' => 'numeric', 'double' => 'float8',
            'enum' => 'varchar', 'float' => 'float8', 'integer' => 'int4', 'ipaddress' => 'inet', 'json' => 'json',
            'jsonb' => 'jsonb', 'longbinary' => 'bytea', 'longtext' => 'text', 'macaddress' => 'macaddr',
            'mediumbinary' => 'bytea', 'mediuminteger' => 'int4', 'mediumtext' => 'text', 'smallinteger' => 'int2',
            'string' => 'varchar', 'text' => 'text', 'time' => 'time', 'timetz' => 'timetz', 'timestamp' => 'timestamp',
            'timestamptz' => 'timestamptz', 'tinyinteger' => 'int2', 'tinytext' => 'varchar', 'tsvector' => 'tsvector',
            'uuid' => 'uuid', 'year' => 'int4',
        ],
        'sqlite' => [
            'biginteger' => 'integer', 'binary' => 'blob', 'boolean' => 'tinyint', 'char' => 'varchar', 'date' => 'date',
            'datetime' => 'datetime', 'datetimetz' => 'datetime', 'decimal' => 'numeric', 'double' => 'double',
            'enum' => 'varchar', 'float' => 'float', 'integer' => 'integer', 'ipaddress' => 'varchar', 'json' => 'text',
            'jsonb' => 'text', 'longbinary' => 'blob', 'longtext' => 'text', 'macaddress' => 'varchar',
            'mediumbinary' => 'blob', 'mediuminteger' => 'integer', 'mediumtext' => 'text', 'smallinteger' => 'integer',
            'string' => 'varchar', 'text' => 'text', 'time' => 'time', 'timetz' => 'time', 'timestamp' => 'datetime',
            'timestamptz' => 'datetime', 'tinyinteger' => 'integer', 'tinytext' => 'text', 'uuid' => 'varchar',
            'year' => 'integer',
        ],
    ];

    /** Whether an earlier migration could have changed any connection, so connections first seen later are incomplete. */
    private bool $invalidated = false;

    /** The type of the key columns morphs() adds, or null when a migration changed it to an unknown value. */
    private ?string $morphKeyType;

    /** @var array<string, true> the helper methods being applied, to stop recursion */
    private array $appliedMethods = [];

    /** @var WeakMap<PhpParser\Node\FunctionLike, BuilderVariables> the builder variables of each function applied */
    private WeakMap $builderVariables;

    /**
     * @var array<string, true> the PHP files besides the migrations that the facts were read from, such as
     *                          a constant naming a table; those read for one table's columns are its sources
     */
    public array $sources = [];

    /**
     * Create a new schema aggregator instance.
     *
     * @param array<string, SchemaConnection> $connections the schema facts, by owning connection
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private InitializerExprTypeResolver $initializerExprTypeResolver,
        private ConnectionConfigResolver $connectionConfigResolver,
        public array $connections = [],
    ) {
        $this->morphKeyType = Builder::$defaultMorphKeyType;
        $this->builderVariables = new WeakMap;
    }

    /**
     * Apply the migration classes a file declares.
     *
     * @param array<int, PhpParser\Node\Stmt> $stmts
     */
    public function addStatements(array $stmts): void
    {
        $nodeFinder = new NodeFinder;

        /** @var PhpParser\Node\Stmt\Class_[] $classes */
        $classes = $nodeFinder->findInstanceOf($stmts, PhpParser\Node\Stmt\Class_::class);

        foreach ($classes as $stmt) {
            $this->addClassStatements($stmt);
        }
    }

    /**
     * Make every connection's facts unknown, for a migration that cannot be read.
     */
    public function invalidate(): void
    {
        foreach ($this->connections as $connection) {
            $connection->forget();
        }

        $this->invalidated = true;
    }

    /**
     * Apply a migration class's up() method.
     *
     * Unlike upstream, which applied every method but down() in declaration order, helper methods
     * are applied where up() calls them.
     */
    private function addClassStatements(PhpParser\Node\Stmt\Class_ $class): void
    {
        $up = $class->getMethod('up');

        if ($up === null || $up->stmts === null) {
            return;
        }

        $scope = ['class' => $class, 'connection' => $this->getMigrationConnection($class), 'table' => null, 'builders' => [], 'escaped' => []];

        $this->applyBody($up->stmts, $this->getFunctionScope($up, $scope));
    }

    /**
     * Apply a whole body, joining the outcomes that returned early with the one that reached its end.
     *
     * @param PhpParser\Node\Stmt[] $stmts
     * @param Scope $scope
     */
    private function applyBody(array $stmts, array $scope): void
    {
        [$continues, $returned] = $this->applyStatements($stmts, $scope);

        // Without an early return, the outcome that reached the end is already current.
        if ($returned !== null) {
            $this->restore($continues ? $this->mergeStates($returned, $this->snapshot()) : $returned);
        }
    }

    /**
     * Get the connection a migration's statements run on by default, or null when it is unknown.
     *
     * Migrator::runMethod() makes the routed connection of the migration's own connection, or the
     * default connection, the default while the migration runs.
     */
    private function getMigrationConnection(PhpParser\Node\Stmt\Class_ $class): ?ConnectionConfig
    {
        [$known, $name] = $this->evaluateGetConnection($class);

        if (! $known) {
            return null;
        }

        // The migrator takes an empty name for the default connection.
        $name = $name === null || $name === '' ? $this->connectionConfigResolver->getDefaultConnectionName() : $name;
        $connection = $name === null ? null : $this->connectionConfigResolver->connection($name);

        return $connection === null ? null : $this->connectionConfigResolver->connection($connection->owner);
    }

    /**
     * Evaluate the connection name a migration's getConnection() returns: whether it is known, and the name.
     *
     * Only an override that returns one expression is read. An inherited method is read only when it
     * is Migration's own, which returns the connection property.
     *
     * @return array{bool, ?string}
     */
    private function evaluateGetConnection(PhpParser\Node\Stmt\Class_ $class): array
    {
        $method = $class->getMethod('getConnection');

        if ($method === null) {
            return $this->inheritsFromMigration($class, 'getConnection') ? $this->evaluateConnectionProperty($class) : [false, null];
        }

        $stmts = $method->stmts ?? [];

        if (count($stmts) !== 1 || ! $stmts[0] instanceof PhpParser\Node\Stmt\Return_ || $stmts[0]->expr === null) {
            return [false, null];
        }

        [$known, $name] = $this->evaluateConnectionName($stmts[0]->expr, $class, false);

        // A string return type throws on null rather than selecting the default connection.
        if ($name === null && $method->returnType instanceof PhpParser\Node\Identifier && $method->returnType->toLowerString() === 'string') {
            return [false, null];
        }

        return [$known, $name];
    }

    /**
     * Evaluate a migration's connection property: whether its value is known, and the value.
     *
     * Its declared default is read, or Migration's own null default when it is inherited from Migration.
     *
     * @return array{bool, ?string}
     */
    private function evaluateConnectionProperty(PhpParser\Node\Stmt\Class_ $class): array
    {
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'connection') {
                    return match (true) {
                        $prop->default === null || $this->isNullLiteral($prop->default) => [true, null],
                        $prop->default instanceof PhpParser\Node\Scalar\String_ => [true, $prop->default->value],
                        default => [false, null],
                    };
                }
            }
        }

        return $this->inheritsFromMigration($class, 'connection') ? [true, null] : [false, null];
    }

    /**
     * Determine whether a class inherits a method, or the property when no method is named, from
     * Migration itself rather than from a class between them.
     */
    private function inheritsFromMigration(PhpParser\Node\Stmt\Class_ $class, string $member): bool
    {
        if ($class->extends === null || ! $this->reflectionProvider->hasClass($class->extends->toString())) {
            return false;
        }

        $parent = $this->reflectionProvider->getClass($class->extends->toString());

        $this->addSources(SourceFiles::forClass($parent, Migration::class), null);

        $declaringClass = $member === 'connection'
            ? ($parent->hasNativeProperty($member) ? $parent->getNativeProperty($member)->getDeclaringClass() : null)
            : ($parent->hasNativeMethod($member) ? $parent->getNativeMethod($member)->getDeclaringClass() : null);

        return $declaringClass?->getName() === Migration::class;
    }

    /**
     * Evaluate a connection name expression: whether its value is known, and the value.
     *
     * Only string and null literals, the migration's connection property and getConnection(), config
     * reads with a literal key and an evaluated default, and null coalescing of these are evaluated.
     * Coalescing evaluates its right side only when the left one is null, as it runs.
     *
     * @param bool $method whether getConnection() can be evaluated, which is not the case inside it
     * @return array{bool, ?string}
     */
    private function evaluateConnectionName(PhpParser\Node\Expr $expr, PhpParser\Node\Stmt\Class_ $class, bool $method = true): array
    {
        if ($expr instanceof PhpParser\Node\Scalar\String_) {
            return [true, $expr->value];
        }

        if ($this->isNullLiteral($expr)) {
            return [true, null];
        }

        if ($expr instanceof PhpParser\Node\Expr\BinaryOp\Coalesce) {
            $left = $this->evaluateConnectionName($expr->left, $class, $method);

            return $left[0] && $left[1] === null ? $this->evaluateConnectionName($expr->right, $class, $method) : $left;
        }

        if ($expr instanceof PhpParser\Node\Expr\PropertyFetch && $this->isThis($expr->var)
            && $expr->name instanceof PhpParser\Node\Identifier && $expr->name->toString() === 'connection') {
            return $this->evaluateConnectionProperty($class);
        }

        if ($method && $expr instanceof PhpParser\Node\Expr\MethodCall && $this->isThis($expr->var)
            && $expr->name instanceof PhpParser\Node\Identifier && $expr->name->toLowerString() === 'getconnection'
            && $expr->getArgs() === [] && ! $expr->isFirstClassCallable()) {
            return $this->evaluateGetConnection($class);
        }

        // config('key'), config()->get('key') or config()->string('key'), and the same Config facade methods.
        $repository = ($expr instanceof PhpParser\Node\Expr\MethodCall && $expr->var instanceof PhpParser\Node\Expr\FuncCall
                && $this->isConfigFunction($expr->var) && $expr->var->getArgs() === [])
            || ($expr instanceof PhpParser\Node\Expr\StaticCall && $this->isFacade($expr->class, Config::class));
        $read = match (true) {
            $expr instanceof PhpParser\Node\Expr\FuncCall && $this->isConfigFunction($expr) => 'get',
            $repository && $expr->name instanceof PhpParser\Node\Identifier => $expr->name->toLowerString(),
            default => null,
        };

        if (! in_array($read, ['get', 'string'], true)) {
            return [false, null];
        }

        /** @var PhpParser\Node\Expr\FuncCall|PhpParser\Node\Expr\MethodCall|PhpParser\Node\Expr\StaticCall $expr */
        $key = $this->getArgument($expr, 0, 'key');

        if (! $key instanceof PhpParser\Node\Scalar\String_) {
            return [false, null];
        }

        [$present, $value] = $this->connectionConfigResolver->getConfiguredName($key->value);

        if (! $present) {
            $default = $this->getArgument($expr, 1, 'default');
            [$known, $value] = $default === null ? [true, null] : $this->evaluateConnectionName($default, $class, $method);

            if (! $known) {
                return [false, null];
            }
        }

        // A value that is not a name is unknown, and Repository::string() throws for anything but a string.
        return $value === false || ($read === 'string' && $value === null) ? [false, null] : [true, $value];
    }

    /**
     * Determine whether a call is to the global config() helper.
     */
    private function isConfigFunction(PhpParser\Node\Expr\FuncCall $call): bool
    {
        return $call->name instanceof PhpParser\Node\Name && $call->name->toLowerString() === 'config' && ! $call->isFirstClassCallable();
    }

    /**
     * Determine whether an expression is $this.
     */
    private function isThis(PhpParser\Node\Expr $expr): bool
    {
        return $expr instanceof PhpParser\Node\Expr\Variable && $expr->name === 'this';
    }

    /**
     * Apply a body of statements, following branches and early returns.
     *
     * Each outcome of a branch applies to its own copy of the facts, and the copies merge
     * where the outcomes join. Other control flow makes the facts it could affect unknown.
     *
     * @param PhpParser\Node\Stmt[] $stmts
     * @param Scope $scope
     *
     * @return array{bool, ?State} whether an outcome reaches the end, which leaves its facts current,
     *                             and the joined facts of the outcomes that returned early
     */
    private function applyStatements(array $stmts, array $scope): array
    {
        $returned = null;

        foreach ($stmts as $stmt) {
            $expr = $stmt instanceof PhpParser\Node\Stmt\Expression || $stmt instanceof PhpParser\Node\Stmt\Return_ ? $stmt->expr : null;

            if ($expr !== null) {
                if ($scope['table'] === null) {
                    $this->applyExpression($expr, $scope);
                } else {
                    $this->processColumnUpdate($expr, $scope);
                }
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Return_) {
                return [false, $this->mergeStates($returned, $this->snapshot())];
            }

            if ($stmt instanceof PhpParser\Node\Stmt\If_ || $stmt instanceof PhpParser\Node\Stmt\Switch_) {
                [$continuing, $branchReturned] = $this->applyBranches($stmt, $scope);
                $returned = $this->mergeStates($returned, $branchReturned);

                if ($continuing === null) {
                    return [false, $returned];
                }

                $this->restore($continuing);
            } elseif (! $stmt instanceof PhpParser\Node\Stmt\Expression && ! $stmt instanceof PhpParser\Node\Stmt\Nop) {
                $this->forgetAffectedBy($stmt, $scope);
            }
        }

        return [true, $returned];
    }

    /**
     * Apply each outcome of an if or switch statement to its own copy of the facts.
     *
     * A condition decided from established facts skips the outcomes it rules out.
     *
     * @param Scope $scope
     *
     * @return array{?State, ?State} the joined facts of the outcomes that continue, and of those that returned
     */
    private function applyBranches(PhpParser\Node\Stmt\If_|PhpParser\Node\Stmt\Switch_ $stmt, array $scope): array
    {
        $this->forgetAffectedBy($stmt->cond, $scope);

        if ($stmt instanceof PhpParser\Node\Stmt\Switch_) {
            // Case expressions run until one matches, so their effects may reach any outcome.
            foreach ($stmt->cases as $case) {
                if ($case->cond !== null) {
                    $this->forgetAffectedBy($case->cond, $scope);
                }
            }
        }

        // The facts when every condition so far was false.
        $falsePath = $this->snapshot();
        $continuing = $returned = null;
        $fallthrough = true;

        if ($stmt instanceof PhpParser\Node\Stmt\Switch_) {
            $branches = [];

            // A case without a break falls into the next one, so each case runs the statements up to a break.
            foreach ($stmt->cases as $index => $case) {
                $body = [];

                foreach ($stmt->cases as $following => $next) {
                    if ($following < $index) {
                        continue;
                    }

                    foreach ($next->stmts as $caseStmt) {
                        if ($caseStmt instanceof PhpParser\Node\Stmt\Break_) {
                            break 2;
                        }

                        $body[] = $caseStmt;
                    }
                }

                $branches[] = [null, $body];
                $fallthrough = $fallthrough && $case->cond !== null;
            }
        } else {
            $branches = [[$stmt->cond, $stmt->stmts]];

            foreach ($stmt->elseifs as $elseif) {
                $branches[] = [$elseif->cond, $elseif->stmts];
            }

            if ($stmt->else !== null) {
                $branches[] = [null, $stmt->else->stmts];
                $fallthrough = false;
            }
        }

        foreach ($branches as $index => [$condition, $body]) {
            $this->restore($falsePath);

            // An elseif condition runs only after the earlier conditions were false.
            if ($condition !== null && $index > 0) {
                $this->forgetAffectedBy($condition, $scope);
                $falsePath = $this->snapshot();
            }

            $decision = $condition === null ? null : $this->decide($condition, $scope);

            if ($decision === false) {
                continue;
            }

            [$continues, $branchReturned] = $this->applyStatements($body, $scope);
            $returned = $this->mergeStates($returned, $branchReturned);
            $continuing = $continues ? $this->mergeStates($continuing, $this->snapshot()) : $continuing;

            // A condition that is certainly true rules out the later outcomes.
            if ($decision === true && ! $stmt instanceof PhpParser\Node\Stmt\Switch_) {
                $fallthrough = false;

                break;
            }
        }

        if ($fallthrough) {
            $continuing = $this->mergeStates($continuing, $falsePath);
        }

        return [$continuing, $returned];
    }

    /**
     * Decide a Schema::hasTable() or hasColumn() guard from established facts, or return null when it is unknown.
     *
     * Only a known connection and a complete table decide it, also through a selected builder,
     * negation and comparison with true or false.
     *
     * @param Scope $scope
     */
    private function decide(PhpParser\Node\Expr $condition, array $scope): ?bool
    {
        if ($condition instanceof PhpParser\Node\Expr\BooleanNot) {
            $decision = $this->decide($condition->expr, $scope);

            return $decision === null ? null : ! $decision;
        }

        if ($condition instanceof PhpParser\Node\Expr\BinaryOp\Identical || $condition instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
            foreach ([[$condition->left, $condition->right], [$condition->right, $condition->left]] as [$guard, $constant]) {
                if ($constant instanceof PhpParser\Node\Expr\ConstFetch && in_array($constant->name->toLowerString(), ['true', 'false'], true)) {
                    $decision = $this->decide($guard, $scope);

                    if ($decision === null) {
                        return null;
                    }

                    $expected = $constant->name->toLowerString() === 'true';

                    return ($decision === $expected) === $condition instanceof PhpParser\Node\Expr\BinaryOp\Identical;
                }
            }

            return null;
        }

        $call = $this->getSchemaCall($condition, $scope);

        if ($call === null || ! in_array($call['method'], ['hastable', 'hascolumn'], true) || $call['connection'] === null) {
            return null;
        }

        return $this->decideGuard(
            $call['connection'],
            $this->getNameArgument($call['call'], 0, 'table'),
            $call['method'] === 'hascolumn' ? $this->getNameArgument($call['call'], 1, 'column') : null,
            $call['method'] === 'hascolumn',
        );
    }

    /**
     * Decide whether a table, or a column of it, exists from established facts, or return null when it is unknown.
     */
    private function decideGuard(ConnectionConfig $connection, ?string $tableName, ?string $columnName, bool $checksColumn): ?bool
    {
        if ($tableName === null) {
            return null;
        }

        [$schema, $name] = $connection->physicalTable($tableName);
        $owner = $this->owner($connection);
        $key = $this->findTableKey($connection, $schema, $name);
        $table = is_string($key) ? $owner->tables[$key] : null;

        // The outcome the guard selects can change any table, so the files the table's facts were read from
        // become sources of every fact.
        if ($table !== null) {
            $this->sources += $table->sources;
        }

        // Both guards can match names in another letter case, depending on the database.
        if (! $checksColumn) {
            return match (true) {
                $table !== null && $table->complete => true,
                $key === null && $owner->complete
                    && ! array_any($owner->tables, static fn (SchemaTable $known): bool => mb_strtolower($known->name) === mb_strtolower($name)) => false,
                default => null,
            };
        }

        if ($columnName === null || $table === null || ! $table->complete) {
            return null;
        }

        $matches = array_filter($table->columns, static fn (SchemaColumn $column): bool => mb_strtolower($column->name) === mb_strtolower($columnName));

        // A column of unknown type may be one that only some earlier outcomes added.
        return match (true) {
            $matches === [] => false,
            array_any($matches, static fn (SchemaColumn $column): bool => $column->type !== null) => true,
            default => null,
        };
    }

    /**
     * Apply an expression statement of a migration body.
     *
     * @param Scope $scope
     */
    private function applyExpression(PhpParser\Node\Expr $expr, array $scope): void
    {
        // A first-class callable created as a statement is discarded without running.
        if ($expr instanceof PhpParser\Node\Expr\CallLike && $expr->isFirstClassCallable()) {
            return;
        }

        $call = $this->getSchemaCall($expr, $scope);

        if ($call !== null && in_array($call['method'], self::MORPH_KEY_SCHEMA_METHODS, true)) {
            $this->morphKeyType = match ($call['method']) {
                'morphusinguuids' => 'uuid',
                'morphusingulids' => 'ulid',
                default => $this->getNameArgument($call['call'], 0, 'type'),
            };

            return;
        }

        if ($call !== null && $call['connection'] !== null) {
            switch ($call['method']) {
                case 'create':
                case 'table':
                    $this->alterTable($call['call'], $call['connection'], $call['method'] === 'create', $scope);

                    return;
                case 'drop':
                case 'dropifexists':
                    $this->dropTable($call['call'], $call['connection']);

                    return;
                case 'rename':
                    $this->renameTableThroughStaticCall($call['call'], $call['connection']);

                    return;
                case 'withoutforeignkeyconstraints':
                    // The callback's Schema calls still run on the default connection.
                    $this->applyCallback($call['call'], 0, 'callback', $scope);

                    return;
                case 'whentablehascolumn':
                case 'whentabledoesnthavecolumn':
                case 'whentablehasindex':
                case 'whentabledoesnthaveindex':
                    $this->applyConditionalTable($call['call'], $call['connection'], $call['method'], $scope);

                    return;
            }
        }

        $statement = $this->literalDatabaseStatement($expr, $scope);

        if ($statement !== null && $statement[0] !== null && $statement[1] !== null) {
            $this->applyLiteralSql($statement[0], $statement[1]);

            return;
        }

        $method = $expr instanceof PhpParser\Node\Expr\MethodCall || $expr instanceof PhpParser\Node\Expr\StaticCall
            ? $this->getHelperMethod($expr, $scope['class'])
            : null;

        if ($method !== null && ! isset($this->appliedMethods[$method->name->toLowerString()])) {
            // The arguments run before the helper, which receives them in parameters it does not follow.
            $this->forgetAffectedBy($expr->getRawArgs(), $scope);

            $this->appliedMethods[$method->name->toLowerString()] = true;
            $this->applyBody($method->stmts ?? [], $this->getFunctionScope($method, $scope));
            unset($this->appliedMethods[$method->name->toLowerString()]);

            return;
        }

        $this->forgetAffectedBy($expr, $scope);
    }

    /**
     * Apply a Schema::whenTable...() helper, which runs table() with its callback when its guard holds.
     *
     * Index guards are never decided, since indexes are not tracked, so both outcomes are joined.
     *
     * @param Scope $scope
     */
    private function applyConditionalTable(PhpParser\Node\Expr\CallLike $call, ConnectionConfig $connection, string $method, array $scope): void
    {
        $decision = str_contains($method, 'column')
            ? $this->decideGuard($connection, $this->getNameArgument($call, 0, 'table'), $this->getNameArgument($call, 1, 'column'), true)
            : null;

        if ($decision !== null && str_contains($method, 'doesnt')) {
            $decision = ! $decision;
        }

        if ($decision === false) {
            return;
        }

        $skipped = $decision === null ? $this->snapshot() : null;

        $this->alterTable($call, $connection, false, $scope, 2);

        if ($skipped !== null) {
            $this->restore($this->mergeStates($this->snapshot(), $skipped));
        }
    }

    /**
     * Get the migration's own method a call on $this, self or static runs, which up() runs inline.
     */
    private function getHelperMethod(PhpParser\Node\Expr\MethodCall|PhpParser\Node\Expr\StaticCall $call, PhpParser\Node\Stmt\Class_ $class): ?PhpParser\Node\Stmt\ClassMethod
    {
        $own = $call instanceof PhpParser\Node\Expr\MethodCall
            ? $this->isThis($call->var)
            : $call->class instanceof PhpParser\Node\Name && in_array($call->class->toLowerString(), ['self', 'static'], true);

        return $own && $call->name instanceof PhpParser\Node\Identifier ? $class->getMethod($call->name->toString()) : null;
    }

    /**
     * Apply the body of a callback argument, or make what it could change unknown.
     *
     * @param Scope $scope
     */
    private function applyCallback(PhpParser\Node\Expr\CallLike $call, int $position, string $parameter, array $scope): void
    {
        $callback = $this->getArgument($call, $position, $parameter);

        // An arrow function reads the enclosing variables; a closure has its own.
        if ($callback instanceof PhpParser\Node\Expr\ArrowFunction) {
            $this->applyBody($this->getUpdateStatements($callback), $scope);
        } elseif ($callback instanceof PhpParser\Node\Expr\Closure) {
            $this->applyBody($this->getUpdateStatements($callback), $this->getFunctionScope($callback, $scope));
        } elseif ($callback !== null) {
            $this->forgetAffectedBy($callback, $scope);
        }
    }

    /**
     * Get a call to a Schema builder method, through the Schema facade, a selector or a builder
     * variable, with its connection.
     *
     * The connection is null when it is unknown. An explicit connection is not routed to its migration
     * connection, but its facts belong to the connection that owns its schema. A first-class callable
     * is included, since a kept one can run later with arguments that are not known.
     *
     * @param Scope $scope
     * @return null|array{call: PhpParser\Node\Expr\MethodCall|PhpParser\Node\Expr\StaticCall, method: lowercase-string, connection: ?ConnectionConfig}
     */
    private function getSchemaCall(PhpParser\Node\Expr $expr, array $scope): ?array
    {
        if (! ($expr instanceof PhpParser\Node\Expr\StaticCall || $expr instanceof PhpParser\Node\Expr\MethodCall)
            || ! $expr->name instanceof PhpParser\Node\Identifier) {
            return null;
        }

        if ($expr instanceof PhpParser\Node\Expr\StaticCall) {
            return $this->isFacade($expr->class, Schema::class) && $expr->name->toLowerString() !== 'connection'
                ? ['call' => $expr, 'method' => $expr->name->toLowerString(), 'connection' => $scope['connection']]
                : null;
        }

        if ($expr->var instanceof PhpParser\Node\Expr\Variable && is_string($expr->var->name) && array_key_exists($expr->var->name, $scope['builders'])) {
            return ['call' => $expr, 'method' => $expr->name->toLowerString(), 'connection' => $scope['builders'][$expr->var->name]];
        }

        $selector = $this->getSchemaSelector($expr->var, $scope);

        return $selector === null ? null : ['call' => $expr, 'method' => $expr->name->toLowerString(), 'connection' => $selector['connection']];
    }

    /**
     * Get the connection of an expression that selects a Schema builder, or null when it is not one.
     *
     * The selectors are Schema::connection(), DB::getSchemaBuilder() and DB::connection()->getSchemaBuilder().
     * The connection is null when it is unknown.
     *
     * @param Scope $scope
     * @return null|array{connection: ?ConnectionConfig}
     */
    private function getSchemaSelector(PhpParser\Node\Expr $expr, array $scope): ?array
    {
        return match (true) {
            $this->isFacadeCall($expr, Schema::class, 'connection') => ['connection' => $this->getNamedConnection($expr, $scope)],
            // A kept getSchemaBuilder() callable selects whichever connection is the default when it runs.
            $this->isFacadeCall($expr, DB::class, 'getschemabuilder') => ['connection' => $expr->isFirstClassCallable() ? null : $scope['connection']],
            $expr instanceof PhpParser\Node\Expr\MethodCall && $expr->name instanceof PhpParser\Node\Identifier
                && $expr->name->toLowerString() === 'getschemabuilder' && $this->isFacadeCall($expr->var, DB::class, 'connection') => ['connection' => $expr->isFirstClassCallable() ? null : $this->getNamedConnection($expr->var, $scope)],
            default => null,
        };
    }

    /**
     * Get the connection a Schema::connection() or DB::connection() call selects, or null when it is unknown.
     *
     * Without a name, it selects the default connection, which is the migration's own while it runs.
     * A kept first-class callable can select any connection.
     *
     * @param Scope $scope
     */
    private function getNamedConnection(PhpParser\Node\Expr\StaticCall $selector, array $scope): ?ConnectionConfig
    {
        if ($selector->isFirstClassCallable()) {
            return null;
        }

        $name = $this->getArgument($selector, 0, 'name');
        [$known, $value] = $name === null ? [true, null] : $this->evaluateConnectionName($name, $scope['class']);

        return match (true) {
            ! $known => null,
            // The database manager takes an empty name for the default connection.
            $value === null || $value === '' => $scope['connection'],
            default => $this->connectionConfigResolver->connection($value),
        };
    }

    /**
     * Get the scope of a function body the migration runs inline, with the builders its variables hold.
     *
     * @param Scope $scope
     * @return Scope
     */
    private function getFunctionScope(PhpParser\Node\Stmt\ClassMethod|PhpParser\Node\Expr\Closure $function, array $scope): array
    {
        $this->builderVariables[$function] ??= $this->getBuilderVariables($function, $scope);
        $variables = $this->builderVariables[$function];

        // A captured variable holds what it held in the enclosing function, unless the closure selects a builder into it.
        if ($function instanceof PhpParser\Node\Expr\Closure) {
            foreach ($function->uses as $use) {
                $name = $use->var->name;

                if (! is_string($name) || array_key_exists($name, $variables['builders']) || array_key_exists($name, $variables['escaped'])) {
                    continue;
                }

                if (array_key_exists($name, $scope['builders'])) {
                    $variables['builders'][$name] = $scope['builders'][$name];
                } elseif (array_key_exists($name, $scope['escaped'])) {
                    $variables['escaped'][$name] = $scope['escaped'][$name];
                }
            }
        }

        return $variables + $scope;
    }

    /**
     * Get the local variables of a function that hold Schema builders.
     *
     * A variable holds a builder of one connection when every write to it is an assignment statement
     * of a Schema selector, all selecting that connection, and every other use calls a method on it,
     * also in nested functions. Other variables assigned a Schema or DB selector escape: what their
     * uses run is unknown. A selector reaching a variable another way is not followed.
     *
     * @param Scope $scope
     * @return BuilderVariables
     */
    private function getBuilderVariables(PhpParser\Node\Stmt\ClassMethod|PhpParser\Node\Expr\Closure $function, array $scope): array
    {
        $stmts = $function->stmts ?? [];
        $nodes = (new NodeFinder)->find($stmts, static fn (PhpParser\Node $node): bool => $node instanceof PhpParser\Node\Expr\Variable
            || $node instanceof PhpParser\Node\Expr\MethodCall
            || $node instanceof PhpParser\Node\Expr\Assign
            || $node instanceof PhpParser\Node\Stmt\Expression);

        $allowed = [];
        $statements = [];
        $selections = [];
        $qualifying = [];

        foreach ($nodes as $node) {
            if ($node instanceof PhpParser\Node\Expr\MethodCall) {
                $allowed[spl_object_id($node->var)] = true;
            } elseif ($node instanceof PhpParser\Node\Stmt\Expression) {
                $statements[spl_object_id($node->expr)] = true;
            }
        }

        foreach ($nodes as $node) {
            if (! $node instanceof PhpParser\Node\Expr\Assign || ! $node->var instanceof PhpParser\Node\Expr\Variable || ! is_string($node->var->name)) {
                continue;
            }

            $selector = $this->getSchemaSelector($node->expr, $scope);

            // A kept selector callable is not a builder.
            if ($selector !== null && isset($statements[spl_object_id($node)])
                && ! ($node->expr instanceof PhpParser\Node\Expr\CallLike && $node->expr->isFirstClassCallable())) {
                $allowed[spl_object_id($node->var)] = true;
                $qualifying[$node->var->name][] = $selector['connection'];
            } elseif ($selector === null && $this->isFacadeCall($node->expr, DB::class, 'connection')) {
                $selector = ['connection' => $this->getNamedConnection($node->expr, $scope)];
            }

            if ($selector !== null) {
                $selections[$node->var->name][] = $selector['connection'];
            }
        }

        $disqualified = [];

        foreach ($nodes as $node) {
            if ($node instanceof PhpParser\Node\Expr\Variable && is_string($node->name) && ! isset($allowed[spl_object_id($node)])) {
                $disqualified[$node->name] = true;
            }
        }

        $variables = ['builders' => [], 'escaped' => []];

        foreach ($selections as $name => $connections) {
            $distinct = [];

            foreach ($connections as $connection) {
                if (! in_array($connection, $distinct, true)) {
                    $distinct[] = $connection;
                }
            }

            if (! isset($disqualified[$name]) && count($qualifying[$name] ?? []) === count($connections) && count($distinct) === 1) {
                $variables['builders'][$name] = $distinct[0];
            } else {
                $variables['escaped'][$name] = $distinct;
            }
        }

        return $variables;
    }

    /**
     * Make the facts that uninterpretable code could change unknown.
     *
     * Schema changes to a named table make that table unknown; other changes make their connection
     * unknown, and changes on an unknown connection make every connection unknown. The migration's
     * own methods are searched too. Inside a Blueprint callback, any use of the Blueprint makes its
     * table unknown. A selector whose builder or connection escapes, and any use of a variable holding
     * one, make the connection it selects unknown. Only query chains on a DB connection are kept.
     *
     * @param PhpParser\Node|PhpParser\Node[] $node
     * @param Scope $scope
     */
    private function forgetAffectedBy(PhpParser\Node|array $node, array $scope): void
    {
        $nodes = (new NodeFinder)->find($node, static fn (PhpParser\Node $found): bool => $found instanceof PhpParser\Node\Expr\Variable
            || $found instanceof PhpParser\Node\Expr\Assign
            || $found instanceof PhpParser\Node\Expr\StaticCall
            || $found instanceof PhpParser\Node\Expr\MethodCall);

        $calls = [];
        // The selectors a builder variable or a recognized call consumes, and every receiver of a method call.
        $consumed = [];
        $receivers = [];

        foreach ($nodes as $found) {
            if ($found instanceof PhpParser\Node\Expr\Variable && is_string($found->name)) {
                if ($scope['table'] !== null && $found->name === $scope['table']['variable']) {
                    $this->forgetTableByKey($scope['table']['connection'], $scope['table']['key']);
                }

                foreach ($scope['escaped'][$found->name] ?? [] as $connection) {
                    $this->forgetConnection($connection);
                }
            } elseif ($found instanceof PhpParser\Node\Expr\Assign) {
                if ($found->var instanceof PhpParser\Node\Expr\Variable && is_string($found->var->name) && array_key_exists($found->var->name, $scope['builders'])) {
                    $consumed[spl_object_id($found->expr)] = true;
                }
            } elseif ($found instanceof PhpParser\Node\Expr\StaticCall || $found instanceof PhpParser\Node\Expr\MethodCall) {
                $calls[] = $found;

                if ($found instanceof PhpParser\Node\Expr\MethodCall) {
                    $receivers[spl_object_id($found->var)] = true;
                }
            }
        }

        foreach ($calls as $call) {
            $helper = $this->getHelperMethod($call, $scope['class']);

            if ($helper !== null) {
                if (! isset($this->appliedMethods[$helper->name->toLowerString()])) {
                    $this->appliedMethods[$helper->name->toLowerString()] = true;
                    $this->forgetAffectedBy($helper->stmts ?? [], ['table' => null] + $this->getFunctionScope($helper, $scope));
                    unset($this->appliedMethods[$helper->name->toLowerString()]);
                }

                continue;
            }

            $schemaCall = $this->getSchemaCall($call, $scope);

            if ($schemaCall === null) {
                $statement = $this->literalDatabaseStatement($call, $scope);

                if ($statement !== null) {
                    $this->forgetConnection($statement[0]);
                }

                continue;
            }

            if ($call instanceof PhpParser\Node\Expr\MethodCall) {
                $consumed[spl_object_id($call->var)] = true;
            }

            $method = $schemaCall['method'];
            $connection = $schemaCall['connection'];

            if (in_array($method, self::MORPH_KEY_SCHEMA_METHODS, true)) {
                $this->morphKeyType = null;
            } elseif ($connection === null || ! isset(self::TABLE_SCHEMA_METHODS[$method])) {
                if (! in_array($method, self::IGNORED_SCHEMA_METHODS, true)) {
                    $this->forgetConnection($connection);
                }
            } else {
                $tableName = $this->getNameArgument($call, ...self::TABLE_SCHEMA_METHODS[$method]);

                if ($tableName === null) {
                    $this->owner($connection)->forget();
                } else {
                    $this->forgetTableByKey($connection, $this->resolveTable($connection, ...$connection->physicalTable($tableName)));
                }
            }
        }

        foreach ($calls as $call) {
            if (isset($consumed[spl_object_id($call)])) {
                continue;
            }

            $selector = $this->getSchemaSelector($call, $scope);

            if ($selector !== null) {
                $this->forgetConnection($selector['connection']);
            } elseif ($this->isFacadeCall($call, DB::class, 'connection') && ! isset($receivers[spl_object_id($call)])) {
                $this->forgetConnection($this->getNamedConnection($call, $scope));
            }
        }
    }

    /**
     * Make every fact of a connection unknown, or of every connection when it is unknown.
     */
    private function forgetConnection(?ConnectionConfig $connection): void
    {
        if ($connection === null) {
            $this->invalidate();
        } else {
            $this->owner($connection)->forget();
        }
    }

    /**
     * Make a table unknown by its identity key, unless an earlier statement removed it.
     */
    private function forgetTableByKey(ConnectionConfig $connection, string $key): void
    {
        $owner = $this->owner($connection);
        $table = $owner->tables[$key] ?? null;

        if ($table !== null) {
            $owner->forgetTable($table->schema, $table->name);
        }
    }

    /**
     * Get the schema facts of a connection's owner, starting them when it has none yet.
     *
     * Migrations start from an empty database, unless an earlier migration could have changed any connection.
     */
    private function owner(ConnectionConfig $connection): SchemaConnection
    {
        if (! isset($this->connections[$connection->owner])) {
            $this->connections[$connection->owner] = new SchemaConnection($connection->owner, $connection->driver);
            $this->connections[$connection->owner]->complete = ! $this->invalidated;
        }

        return $this->connections[$connection->owner];
    }

    /**
     * Find the identity key of the relation a physical name refers to on a connection.
     *
     * @return null|false|string null when it is absent, and false when it is ambiguous
     */
    private function findTableKey(ConnectionConfig $connection, ?string $schema, string $name): false|string|null
    {
        return $this->owner($connection)->findTableKey($schema, $name, $connection->defaultSchema, $connection->searchPath);
    }

    /**
     * Get the identity key of the relation a changed physical name refers to, adding an unknown one when it is not known.
     *
     * An ambiguous name could refer to any of its candidates, so it makes the connection unknown.
     */
    private function resolveTable(ConnectionConfig $connection, ?string $schema, string $name): string
    {
        $owner = $this->owner($connection);
        $key = $this->findTableKey($connection, $schema, $name);
        $this->forgetCaseVariants($connection, $schema, $name);

        if (is_string($key)) {
            return $key;
        }

        if ($key === false) {
            $owner->forget();
        }

        $schema ??= $connection->definitionSchema();
        $owner->forgetTable($schema, $name);
        $key = $owner->key($schema, $name);
        $owner->tables[$key]->searchPath = $schema === null ? $connection->searchPath : null;

        return $key;
    }

    /**
     * Make the known relations that a change to a physical name could reach through letter case unknown.
     */
    private function forgetCaseVariants(ConnectionConfig $connection, ?string $schema, string $name): void
    {
        foreach ($this->owner($connection)->caseVariants($schema ?? $connection->defaultSchema, $name) as $key) {
            $this->forgetTableByKey($connection, $key);
        }
    }

    /**
     * Copy the current facts.
     *
     * @return State
     */
    private function snapshot(): array
    {
        return [array_map(static fn (SchemaConnection $connection): SchemaConnection => clone $connection, $this->connections), $this->invalidated, $this->morphKeyType];
    }

    /**
     * Make a copy of earlier facts current.
     *
     * @param State $state
     */
    private function restore(array $state): void
    {
        [$connections, $this->invalidated, $this->morphKeyType] = $state;
        $this->connections = array_map(static fn (SchemaConnection $connection): SchemaConnection => clone $connection, $connections);
    }

    /**
     * Join the facts of two outcomes, keeping only what both establish.
     *
     * A connection missing from one outcome has its initial facts there, since no migration used it yet.
     *
     * @param ?State $first
     * @param ?State $second
     *
     * @return ($first is null ? ($second is null ? null : State) : State)
     */
    private function mergeStates(?array $first, ?array $second): ?array
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        $connections = [];

        foreach ($first[0] + $second[0] as $name => $connection) {
            $connections[$name] = $this->mergeConnections(
                $first[0][$name] ?? $this->initialConnection($connection, $first[1]),
                $second[0][$name] ?? $this->initialConnection($connection, $second[1]),
            );
        }

        return [$connections, $first[1] || $second[1], $first[2] === $second[2] ? $first[2] : null];
    }

    /**
     * Get the facts a connection starts with before any migration uses it.
     */
    private function initialConnection(SchemaConnection $connection, bool $invalidated): SchemaConnection
    {
        $initial = new SchemaConnection($connection->name, $connection->driver);
        $initial->complete = ! $invalidated;

        return $initial;
    }

    /**
     * Join the facts of a connection in two outcomes.
     *
     * A table in one outcome only may exist, so it is incomplete; its columns are unknown too unless
     * the other outcome rules the table out. A column whose types differ, or that one outcome lacks,
     * has an unknown type. A user type that one outcome lacks or defines differently is uncertain.
     */
    private function mergeConnections(SchemaConnection $first, SchemaConnection $second): SchemaConnection
    {
        $merged = new SchemaConnection($first->name, $first->driver);
        $merged->complete = $first->complete && $second->complete;
        $merged->unusableDumps = $first->unusableDumps;

        foreach ($first->types + $second->types as $key => $type) {
            $merged->types[$key] = array_key_exists($key, $first->types) && array_key_exists($key, $second->types)
                && $first->types[$key] == $second->types[$key] ? $type : null;
        }

        foreach ($first->tables + $second->tables as $key => $table) {
            $firstTable = $first->tables[$key] ?? null;
            $secondTable = $second->tables[$key] ?? null;
            $table = clone $table;
            $table->complete = $firstTable !== null && $secondTable !== null && $firstTable->complete && $secondTable->complete;
            $table->sources = ($firstTable->sources ?? []) + ($secondTable->sources ?? []);

            if ($firstTable === null || $secondTable === null) {
                if (! ($firstTable === null ? $first : $second)->complete) {
                    $table->columns = [];
                }
            } else {
                foreach ($firstTable->columns + $secondTable->columns as $name => $column) {
                    $firstColumn = $firstTable->columns[$name] ?? null;
                    $secondColumn = $secondTable->columns[$name] ?? null;

                    $table->columns[$name] = $firstColumn !== null && $secondColumn !== null
                        && $firstColumn->type == $secondColumn->type && $firstColumn->options === $secondColumn->options
                            ? new SchemaColumn($column->name, $column->type, $firstColumn->nullable || $secondColumn->nullable, $column->options)
                            : new SchemaColumn($column->name, null, true);
                }
            }

            $merged->tables[$key] = $table;
        }

        return $merged;
    }

    /**
     * Apply Schema::create() or Schema::table() and the Blueprint callback it runs.
     *
     * A table changed without being known keeps the columns the callback establishes, but no claim
     * about others. PostgreSQL changes its known descendants too.
     *
     * @param Scope $scope
     * @param int $callbackPosition the position of the Blueprint callback parameter
     */
    private function alterTable(PhpParser\Node\Expr\CallLike $call, ConnectionConfig $connection, bool $creating, array $scope, int $callbackPosition = 1): void
    {
        $owner = $this->owner($connection);
        $tableName = $this->getNameArgument($call, 0, 'table');

        if ($tableName === null) {
            $owner->forget();

            return;
        }

        [$schema, $name] = $connection->physicalTable($tableName);

        if ($creating) {
            $schema ??= $connection->definitionSchema();
            $key = $owner->key($schema, $name);
            $owner->tables[$key] = new SchemaTable($name, $schema);
            $owner->tables[$key]->searchPath = $schema === null ? $connection->searchPath : null;
        } else {
            $key = $this->resolveTable($connection, $schema, $name);
            $owner->forgetDescendants($key);
        }

        $callback = $this->getArgument($call, $callbackPosition, 'callback');
        $variable = $callback === null ? null : $this->getBlueprintVariable($callback);

        if ($variable === null) {
            $this->forgetTableByKey($connection, $key);

            if ($callback !== null) {
                $this->forgetAffectedBy($callback, $scope);
            }

            return;
        }

        /** @var PhpParser\Node\Expr\ArrowFunction|PhpParser\Node\Expr\Closure $callback */
        $this->applyBody(
            $this->getUpdateStatements($callback),
            ['table' => ['connection' => $connection, 'key' => $key, 'variable' => $variable]] + $scope,
        );
    }

    /**
     * Get the variable a Blueprint callback receives the Blueprint in, or null when the callback cannot be followed.
     */
    private function getBlueprintVariable(PhpParser\Node\Expr $callback): ?string
    {
        $parameter = $callback instanceof PhpParser\Node\Expr\Closure || $callback instanceof PhpParser\Node\Expr\ArrowFunction
            ? $callback->params[0] ?? null
            : null;

        return $parameter !== null
            && $parameter->var instanceof PhpParser\Node\Expr\Variable
            && is_string($parameter->var->name)
            && (! $parameter->type instanceof PhpParser\Node\Name || $this->isClass($parameter->type, Blueprint::class))
                ? $parameter->var->name
                : null;
    }

    /**
     * Apply a statement of a Blueprint callback to its table.
     *
     * Only a call chain on the Blueprint is followed: its first call, with the last nullable() and
     * unsigned() modifiers of the chain. A method it does not know, such as a macro, or a name that
     * is not constant makes the table unknown. Other statements make the table unknown when they use
     * the Blueprint. Creating a first-class callable runs nothing.
     *
     * @param Scope $scope
     */
    private function processColumnUpdate(PhpParser\Node\Expr $expr, array $scope): void
    {
        /** @var TableScope $tableScope */
        $tableScope = $scope['table'];
        $call = $expr;
        $modifiers = [];

        while ($call instanceof PhpParser\Node\Expr\MethodCall && $call->var instanceof PhpParser\Node\Expr\MethodCall) {
            // The outermost modifier runs last, so it decides.
            if ($call->name instanceof PhpParser\Node\Identifier && ! $call->isFirstClassCallable()) {
                $modifiers[$call->name->toLowerString()] ??= $call;
            }

            $call = $call->var;
        }

        if (! $call instanceof PhpParser\Node\Expr\MethodCall
            || ! $call->var instanceof PhpParser\Node\Expr\Variable
            || $call->var->name !== $tableScope['variable']) {
            $this->forgetAffectedBy($expr, $scope);

            return;
        }

        $table = $this->owner($tableScope['connection'])->tables[$tableScope['key']] ?? null;
        $method = $call->name instanceof PhpParser\Node\Identifier ? $call->name->toLowerString() : null;

        // An earlier drop() or rename() removed the table, so later changes would fail.
        if ($table === null || $call->isFirstClassCallable() || in_array($method, self::IGNORED_BLUEPRINT_METHODS, true)) {
            return;
        }

        // removeColumn() returns the Blueprint, so the calls chained after it change the table too.
        if ($method === 'removecolumn' && $call !== $expr) {
            $this->forgetTableByKey($tableScope['connection'], $tableScope['key']);

            return;
        }

        if ($method === null || ! $this->processStatementAlterMethod($method, $call, $table, $modifiers, $scope)) {
            $this->forgetTableByKey($tableScope['connection'], $tableScope['key']);
        }
    }

    /**
     * Apply a Blueprint method to its table, or return false when its effect is unknown.
     *
     * @param array<string, PhpParser\Node\Expr\MethodCall> $modifiers the last call of each modifier in the chain
     * @param Scope $scope
     */
    private function processStatementAlterMethod(
        string $method,
        PhpParser\Node\Expr\MethodCall $call,
        SchemaTable $table,
        array $modifiers,
        array $scope,
    ): bool {
        /** @var TableScope $tableScope */
        $tableScope = $scope['table'];
        $connection = $tableScope['connection'];

        if (isset(self::COLUMN_METHODS[$method]) || $method === 'addcolumn') {
            // addColumn() merges its parameters over the type and name, and the chain's modifiers then override them.
            $parameters = $method === 'addcolumn' ? $this->getColumnParameters($call, $table) : [];

            // Parameters that are not a literal array can set any attribute, including the name and change().
            if ($parameters === null) {
                return false;
            }

            if ($method === 'addcolumn') {
                $typeArgument = $parameters['type'] ?? $this->getArgument($call, 0, 'type');
                $nameArgument = $parameters['name'] ?? $this->getArgument($call, 1, 'name');
                $type = $typeArgument === null ? null : $this->resolveName($typeArgument, $table);
                $name = $nameArgument === null ? null : $this->resolveName($nameArgument, $table);
            } else {
                $type = self::COLUMN_METHODS[$method];
                $name = $this->getNameArgument($call, 0, 'column', self::DEFAULT_COLUMN_NAMES[$method] ?? null, $table);
            }

            $type = $type === null ? null : strtolower($type);

            if ($name === null) {
                return $this->addUnnamedColumn($table, isset($modifiers['change']) || isset($parameters['change']));
            }

            $this->setColumn(
                $connection,
                $table,
                $name,
                $type,
                $this->getModifier($modifiers, 'unsigned', match (true) {
                    in_array($method, self::UNSIGNED_COLUMN_METHODS, true) => true,
                    in_array($method, self::SIGNABLE_COLUMN_METHODS, true) => $this->getBooleanArgument($call, 2, 'unsigned', false),
                    isset($parameters['unsigned']) => $this->resolveBoolean($parameters['unsigned']),
                    default => false,
                }),
                $this->getModifier($modifiers, 'nullable', isset($parameters['nullable'])
                    ? $this->resolveBoolean($parameters['nullable'])
                    : in_array($method, self::NULLABLE_COLUMN_METHODS, true)) ?? true,
                in_array($type, ['enum', 'set'], true)
                    ? $this->getAllowedValues($method === 'addcolumn' ? $parameters['allowed'] ?? null : $this->getArgument($call, 1, 'allowed'), $table)
                    : null,
            );

            return true;
        }

        switch ($method) {
            case 'foreignidfor':
            case 'foreignuuidfor':
            case 'foreignulidfor':
                $reference = $this->getForeignModel($call, $table);

                if ($reference === null) {
                    return $this->addUnnamedColumn($table, isset($modifiers['change']));
                }

                [$model, $name] = $reference;
                $type = match (true) {
                    $method === 'foreignuuidfor' => 'uuid',
                    $method === 'foreignulidfor' => 'char',
                    $model->getKeyType() === 'int' => 'biginteger',
                    isset(class_uses_recursive($model)[HasUlids::class]) => 'char',
                    default => 'uuid',
                };

                $this->setColumn(
                    $connection,
                    $table,
                    $name,
                    $type,
                    $this->getModifier($modifiers, 'unsigned', $type === 'biginteger'),
                    $this->getModifier($modifiers, 'nullable', false) ?? true,
                );

                return true;
            case 'morphs':
            case 'nullablemorphs':
            case 'numericmorphs':
            case 'nullablenumericmorphs':
            case 'uuidmorphs':
            case 'nullableuuidmorphs':
            case 'ulidmorphs':
            case 'nullableulidmorphs':
                $name = $this->getNameArgument($call, 0, 'name', table: $table);

                if ($name === null) {
                    return $this->addUnnamedColumn($table, isset($modifiers['change']));
                }

                $keyType = match (true) {
                    str_contains($method, 'numeric') => 'int',
                    str_contains($method, 'uuid') => 'uuid',
                    str_contains($method, 'ulid') => 'ulid',
                    default => $this->morphKeyType,
                };
                $nullable = str_starts_with($method, 'nullable');

                $this->setColumn($connection, $table, "{$name}_type", 'string', false, $nullable);
                $this->setColumn($connection, $table, "{$name}_id", match ($keyType) {
                    null => null,
                    'uuid' => 'uuid',
                    'ulid' => 'char',
                    default => 'biginteger',
                }, true, $nullable);

                return true;
            case 'timestamps':
            case 'nullabletimestamps':
            case 'timestampstz':
            case 'nullabletimestampstz':
            case 'datetimes':
                $type = match ($method) {
                    'timestampstz', 'nullabletimestampstz' => 'timestamptz',
                    'datetimes' => 'datetime',
                    default => 'timestamp',
                };

                $this->setColumn($connection, $table, 'created_at', $type, false, true);
                $this->setColumn($connection, $table, 'updated_at', $type, false, true);

                return true;
            case 'renamecolumn':
                $from = $this->getNameArgument($call, 0, 'from', table: $table);
                $from = $from === null ? null : $this->resolveColumnName($connection, $table, $from);
                $to = $this->getNameArgument($call, 1, 'to', table: $table);

                if ($from === null || $to === null) {
                    return false;
                }

                $table->renameColumn($from, $to);

                return true;
            case 'after':
                $callback = $this->getArgument($call, 1, 'callback');
                $variable = $callback === null ? null : $this->getBlueprintVariable($callback);

                if ($variable === null) {
                    if ($callback !== null) {
                        $this->forgetAffectedBy($callback, $scope);
                    }

                    return false;
                }

                /** @var PhpParser\Node\Expr\ArrowFunction|PhpParser\Node\Expr\Closure $callback */
                $this->applyBody($this->getUpdateStatements($callback), ['table' => ['variable' => $variable] + $tableScope] + $scope);

                return true;
            case 'removecolumn':
                $name = $this->getNameArgument($call, 0, 'name', table: $table);

                if ($name === null) {
                    return false;
                }

                // Unlike upstream's drop, it only cancels the column definitions this Blueprint queued, so the
                // column may keep its earlier type, or not exist.
                $table->setColumn(new SchemaColumn($name, null, true));

                return true;
            case 'drop':
            case 'dropifexists':
                $this->owner($connection)->dropTable($tableScope['key']);

                return true;
            case 'rename':
                $this->renameTable($connection, $tableScope['key'], $this->getNameArgument($call, 0, 'to'));

                return true;
            case 'dropmorphs':
                $name = $this->getNameArgument($call, 0, 'name', table: $table);
                $columns = $name === null ? [null] : ["{$name}_type", "{$name}_id"];

                break;
            default:
                $columns = match ($method) {
                    'dropcolumn' => $this->getColumnNames($call, $table),
                    'dropconstrainedforeignid' => [$this->getNameArgument($call, 0, 'column', table: $table)],
                    'dropforeignidfor', 'dropconstrainedforeignidfor' => [$this->getForeignModel($call, $table)[1] ?? null],
                    'dropremembertoken' => ['remember_token'],
                    'dropsoftdeletes', 'dropsoftdeletestz' => [$this->getNameArgument($call, 0, 'column', 'deleted_at', $table)],
                    'droptimestamps', 'droptimestampstz' => ['created_at', 'updated_at'],
                    // Macros and other methods it does not know.
                    default => [null],
                };
        }

        if (in_array(null, $columns, true)) {
            return false;
        }

        foreach ($columns as $column) {
            $column = $this->resolveColumnName($connection, $table, $column);

            if ($column === null) {
                return false;
            }

            $table->dropColumn($column);
        }

        return true;
    }

    /**
     * Get the parameters addColumn() merges over the column's attributes, or null when they are not a literal
     * array with constant keys.
     *
     * @return null|array<string, PhpParser\Node\Expr>
     */
    private function getColumnParameters(PhpParser\Node\Expr\MethodCall $call, SchemaTable $table): ?array
    {
        $argument = $this->getArgument($call, 2, 'parameters');

        if ($argument === null) {
            return [];
        }

        if (! $argument instanceof PhpParser\Node\Expr\Array_) {
            return null;
        }

        $parameters = [];

        foreach ($argument->items as $item) {
            $key = $item->unpack || $item->key === null ? null : $this->resolveName($item->key, $table);

            if ($key === null) {
                return null;
            }

            $parameters[$key] = $item->value;
        }

        return $parameters;
    }

    /**
     * Get the stored name of the column a statement names, or null when several columns could match.
     *
     * MySQL, MariaDB and SQLite match column names in any letter case, and the column keeps its stored spelling.
     */
    private function resolveColumnName(ConnectionConfig $connection, SchemaTable $table, string $name): ?string
    {
        if (isset($table->columns[$name]) || ! in_array($connection->driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return $name;
        }

        $matches = array_values(array_filter(
            array_keys($table->columns),
            static fn (string $column): bool => mb_strtolower($column) === mb_strtolower($name),
        ));

        return match (count($matches)) {
            0 => $name,
            1 => $matches[0],
            default => null,
        };
    }

    /**
     * Record that a table gained columns whose names are unknown, or return false when change() may modify a known one.
     *
     * Adding a column cannot change the existing ones, so they keep their facts.
     */
    private function addUnnamedColumn(SchemaTable $table, bool $change): bool
    {
        if ($change) {
            return false;
        }

        $table->complete = false;

        return true;
    }

    /**
     * Get the value the last nullable() or unsigned() modifier of a chain sets, the default without one, or null when it is not a literal.
     *
     * @param array<string, PhpParser\Node\Expr\MethodCall> $modifiers
     */
    private function getModifier(array $modifiers, string $name, ?bool $default): ?bool
    {
        return isset($modifiers[$name]) ? $this->getBooleanArgument($modifiers[$name], 0, 'value', true) : $default;
    }

    /**
     * Add or replace a column with the type the connection's schema grammar creates for a Blueprint type.
     *
     * @param ?string $type the lowercase Blueprint type, or null when it is unknown
     * @param ?bool $unsigned whether the column is unsigned, or null when it is unknown
     * @param null|list<string> $values the values an enum or set allows, or null when they are unknown
     */
    private function setColumn(
        ConnectionConfig $connection,
        SchemaTable $table,
        string $name,
        ?string $type,
        ?bool $unsigned,
        bool $nullable,
        ?array $values = null,
    ): void {
        $driver = $connection->driver === 'mariadb' ? 'mysql' : $connection->driver;
        $typeName = match (true) {
            $connection->driver === 'mariadb' && $type === 'uuid' => 'uuid',
            // SQLite's grammar creates json and jsonb columns only when the connection opts in, and text otherwise.
            $driver === 'sqlite' && $type === 'json' && $connection->nativeJson => 'json',
            $driver === 'sqlite' && $type === 'jsonb' && $connection->nativeJsonb => 'jsonb',
            default => self::GRAMMAR_TYPES[$driver][$type ?? ''] ?? null,
        };
        // MySQL enums and sets hold their values in the type; the other grammars check an enum's string.
        $typeValues = $driver === 'mysql' && in_array($type, ['enum', 'set'], true);

        // Only MySQL reads the sign, so an unknown sign only makes its type unknown.
        if ($driver === 'mysql' && $unsigned === null) {
            $typeName = null;
        }

        $storedName = $this->resolveColumnName($connection, $table, $name);

        // Only change() can name an existing column in another letter case, and the spelling the column
        // then keeps depends on the database's statement, so neither spelling is known.
        if ($storedName !== $name) {
            if ($storedName !== null) {
                $table->dropColumn($storedName);
            }

            $table->complete = false;

            return;
        }

        $table->setColumn(new SchemaColumn(
            $name,
            $typeName === null ? null : new ColumnType(
                $typeName,
                $driver === 'pgsql' ? 'pg_catalog' : null,
                $type === 'boolean' && $driver !== 'pgsql' ? ['1'] : [],
                unsigned: $driver === 'mysql' && $unsigned === true,
                values: $typeValues ? $values ?? [] : [],
            ),
            $nullable,
            $type === 'enum' && ! $typeValues ? $values ?? [] : [],
        ));
    }

    /**
     * Get the model a foreignIdFor()-style call references and the column it names, or null when either is unknown.
     *
     * The model's key and foreign key name come from its class, parents and traits, which become sources of the table's columns.
     *
     * @return null|array{Model, string}
     */
    private function getForeignModel(PhpParser\Node\Expr\MethodCall $call, SchemaTable $table): ?array
    {
        $modelArgument = $this->getArgument($call, 0, 'model');
        $class = match (true) {
            $modelArgument instanceof PhpParser\Node\Expr\ClassConstFetch
                && $modelArgument->class instanceof PhpParser\Node\Name
                && $modelArgument->name instanceof PhpParser\Node\Identifier
                && $modelArgument->name->toLowerString() === 'class' => $modelArgument->class->toString(),
            $modelArgument instanceof PhpParser\Node\Scalar\String_ => $modelArgument->value,
            default => null,
        };

        if ($class === null || ! $this->reflectionProvider->hasClass($class) || ! $this->reflectionProvider->getClass($class)->is(Model::class)) {
            return null;
        }

        $classReflection = $this->reflectionProvider->getClass($class);

        try {
            $model = ModelHelper::newInstanceWithoutConstructor($classReflection);
        } catch (ReflectionException) {
            return null;
        }

        $this->addSources(SourceFiles::forInstance($this->reflectionProvider, $classReflection, Model::class), $table);

        $columnArgument = $this->getArgument($call, 1, 'column');
        $column = $columnArgument === null || $this->isNullLiteral($columnArgument) ? '' : $this->resolveName($columnArgument, $table);

        // Blueprint uses the model's foreign key for an empty name too.
        return $column === null ? null : [$model, $column === '' ? $model->getForeignKey() : $column];
    }

    /**
     * Get the column names dropColumn() receives as an array or as separate arguments, with null for each one that is not constant.
     *
     * @return non-empty-list<?string>
     */
    private function getColumnNames(PhpParser\Node\Expr\MethodCall $call, SchemaTable $table): array
    {
        $columns = $this->getArgument($call, 0, 'columns');
        $values = $columns instanceof PhpParser\Node\Expr\Array_
            ? array_map(static fn (PhpParser\Node\ArrayItem $item): ?PhpParser\Node\Expr => $item->unpack ? null : $item->value, $columns->items)
            : array_map(static fn (PhpParser\Node\Arg $argument): ?PhpParser\Node\Expr => $argument->unpack ? null : $argument->value, $call->getArgs());

        return array_map(fn (?PhpParser\Node\Expr $value): ?string => $value === null ? null : $this->resolveName($value, $table), $values) ?: [null];
    }

    /**
     * Get the values an enum() or set() column allows, or null when they are not constant.
     *
     * @return null|list<string>
     */
    private function getAllowedValues(?PhpParser\Node\Expr $allowed, SchemaTable $table): ?array
    {
        if (! $allowed instanceof PhpParser\Node\Expr\Array_) {
            return null;
        }

        $values = [];

        foreach ($allowed->items as $item) {
            $value = $item->unpack ? null : $this->resolveName($item->value, $table);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * Get the constant string an expression names: a literal, or a class constant with a string value.
     *
     * A constant's files are sources of the table's column facts when a table is given, and of every
     * fact otherwise.
     */
    private function resolveName(PhpParser\Node\Expr $value, ?SchemaTable $table = null): ?string
    {
        if ($value instanceof PhpParser\Node\Scalar\String_) {
            return $value->value;
        }

        if (
            ! $value instanceof PhpParser\Node\Expr\ClassConstFetch
            || ! $value->class instanceof PhpParser\Node\Name\FullyQualified
            || ! $value->name instanceof PhpParser\Node\Identifier
            || ! $this->reflectionProvider->hasClass($value->class->name)
        ) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($value->class->name);

        if (
            ! $class->hasConstant($value->name->toString())
            || ($class->isEnum() && $class->hasEnumCase($value->name->toString()))
        ) {
            return null;
        }

        $constant = $class->getConstant($value->name->toString());
        $strings = $this->initializerExprTypeResolver->getType(
            $constant->getValueExpr(),
            InitializerExprContext::fromClassReflection($constant->getDeclaringClass()),
        )->getConstantStrings();

        if (count($strings) !== 1) {
            return null;
        }

        $this->addSources(SourceFiles::forConstant($this->reflectionProvider, $class, $value->name->toString()), $table);

        return $strings[0]->getValue();
    }

    /**
     * Record PHP files besides the migrations as sources of a table's column facts, or of every fact without a table.
     *
     * @param list<string> $files
     */
    private function addSources(array $files, ?SchemaTable $table): void
    {
        foreach ($files as $file) {
            if ($table === null) {
                $this->sources[$file] = true;
            } else {
                $table->sources[$file] = true;
            }
        }
    }

    /**
     * Apply Schema::drop() or dropIfExists().
     */
    private function dropTable(PhpParser\Node\Expr\CallLike $call, ConnectionConfig $connection): void
    {
        $owner = $this->owner($connection);
        $tableName = $this->getNameArgument($call, 0, 'table');

        if ($tableName === null) {
            $owner->forget();

            return;
        }

        [$schema, $name] = $connection->physicalTable($tableName);
        $key = $this->findTableKey($connection, $schema, $name);
        $this->forgetCaseVariants($connection, $schema, $name);

        if ($key === false) {
            $owner->forget();
        } elseif ($key !== null) {
            $owner->dropTable($key);
        }
    }

    /**
     * Apply Schema::rename().
     */
    private function renameTableThroughStaticCall(PhpParser\Node\Expr\CallLike $call, ConnectionConfig $connection): void
    {
        $from = $this->getNameArgument($call, 0, 'from');

        if ($from === null) {
            $this->owner($connection)->forget();

            return;
        }

        $this->renameTable(
            $connection,
            $this->resolveTable($connection, ...$connection->physicalTable($from)),
            $this->getNameArgument($call, 1, 'to'),
        );
    }

    /**
     * Move a table's facts to its new name.
     *
     * PostgreSQL and SQLite rename a table within its schema, while MySQL resolves the new name like
     * any other. A new name that is not constant leaves an unknown relation.
     */
    private function renameTable(ConnectionConfig $connection, string $key, ?string $to): void
    {
        $owner = $this->owner($connection);
        $table = $owner->tables[$key];
        $owner->dropTable($key);

        if ($to === null) {
            $owner->complete = false;

            return;
        }

        [$schema, $table->name] = $connection->physicalTable($to);

        if (in_array($connection->driver, ['mysql', 'mariadb'], true)) {
            $table->schema = $schema ?? $connection->defaultSchema;
        }

        $owner->tables[$owner->key($table->schema, $table->name)] = $table;
    }

    /**
     * Get the expression a call passes for a parameter, by name or position.
     *
     * An unpacked argument stands for its position and every later one, so its value is not constant.
     */
    private function getArgument(PhpParser\Node\Expr\CallLike $call, int $position, string $parameter): ?PhpParser\Node\Expr
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        foreach ($call->getArgs() as $index => $argument) {
            $matches = $argument->name !== null
                ? $argument->name->toString() === $parameter
                : $index === $position || ($argument->unpack && $index < $position);

            if ($matches) {
                return $argument->value;
            }
        }

        return null;
    }

    /**
     * Get the literal boolean a call passes for a parameter, the default when it passes none, or null when it is not a literal.
     */
    private function getBooleanArgument(PhpParser\Node\Expr\CallLike $call, int $position, string $parameter, bool $default): ?bool
    {
        $argument = $this->getArgument($call, $position, $parameter);

        return $argument === null ? $default : $this->resolveBoolean($argument);
    }

    /**
     * Get the boolean an expression is a literal of, or null when it is not a boolean literal.
     */
    private function resolveBoolean(PhpParser\Node\Expr $expr): ?bool
    {
        return $expr instanceof PhpParser\Node\Expr\ConstFetch && in_array($expr->name->toLowerString(), ['true', 'false'], true)
            ? $expr->name->toLowerString() === 'true'
            : null;
    }

    /**
     * Get the constant name a call passes for a parameter, the default when it passes none, or null when it is not constant.
     */
    private function getNameArgument(PhpParser\Node\Expr\CallLike $call, int $position, string $parameter, ?string $default = null, ?SchemaTable $table = null): ?string
    {
        $argument = $this->getArgument($call, $position, $parameter);

        return $argument === null ? $default : $this->resolveName($argument, $table);
    }

    /**
     * Determine whether an expression is the null literal.
     */
    private function isNullLiteral(PhpParser\Node\Expr $expr): bool
    {
        return $expr instanceof PhpParser\Node\Expr\ConstFetch && $expr->name->toLowerString() === 'null';
    }

    /**
     * Get the statements a callback runs.
     *
     * Unlike upstream, which applied the expressions inside if statements unconditionally, branches
     * are applied as branches.
     *
     * @return PhpParser\Node\Stmt[]
     */
    private function getUpdateStatements(PhpParser\Node\Expr\Closure|PhpParser\Node\Expr\ArrowFunction $callback): array
    {
        return $callback instanceof PhpParser\Node\Expr\ArrowFunction
            ? [new PhpParser\Node\Stmt\Expression($callback->expr)]
            : $callback->stmts;
    }

    /**
     * Get the connection and SQL of a statement() or unprepared() call, or null when the expression is not one.
     *
     * The call runs on the default connection through the DB facade, or on a selected one through
     * DB::connection() or Schema's getConnection(). The connection is null when it is unknown, and
     * the SQL when it is not a literal, as for a first-class callable.
     *
     * @param Scope $scope
     * @return null|array{?ConnectionConfig, ?string}
     */
    private function literalDatabaseStatement(PhpParser\Node\Expr $expr, array $scope): ?array
    {
        if (! ($expr instanceof PhpParser\Node\Expr\StaticCall || $expr instanceof PhpParser\Node\Expr\MethodCall)
            || ! $expr->name instanceof PhpParser\Node\Identifier
            || ! in_array($expr->name->toLowerString(), ['statement', 'unprepared'], true)) {
            return null;
        }

        if ($expr instanceof PhpParser\Node\Expr\StaticCall) {
            if (! $this->isFacade($expr->class, DB::class)) {
                return null;
            }

            $connection = $scope['connection'];
        } elseif (($schemaCall = $this->getSchemaCall($expr->var, $scope)) !== null && $schemaCall['method'] === 'getconnection') {
            $connection = $schemaCall['connection'];
        } elseif ($this->isFacadeCall($expr->var, DB::class, 'connection')) {
            $connection = $this->getNamedConnection($expr->var, $scope);
        } else {
            return null;
        }

        $sql = $this->getArgument($expr, 0, 'query');

        return [$connection, $sql instanceof PhpParser\Node\Scalar\String_ ? $sql->value : null];
    }

    /**
     * Determine whether a class name refers to a facade, also through its global alias.
     */
    private function isFacade(PhpParser\Node $class, string $facade): bool
    {
        return $class instanceof PhpParser\Node\Name
            && ($class->toString() === class_basename($facade) || $this->isClass($class, $facade));
    }

    /**
     * Determine whether an expression calls a facade method.
     *
     * @param lowercase-string $method
     * @phpstan-assert-if-true =PhpParser\Node\Expr\StaticCall $expr
     */
    private function isFacadeCall(PhpParser\Node $expr, string $facade, string $method): bool
    {
        return $expr instanceof PhpParser\Node\Expr\StaticCall
            && $expr->name instanceof PhpParser\Node\Identifier
            && $expr->name->toLowerString() === $method
            && $this->isFacade($expr->class, $facade);
    }

    /**
     * Determine whether a class name refers to a class or one of its subclasses.
     */
    private function isClass(PhpParser\Node\Name $name, string $class): bool
    {
        return $this->reflectionProvider->hasClass($name->toString())
            && $this->reflectionProvider->getClass($name->toString())->is($class);
    }

    /**
     * Apply raw SQL to the facts of its connection.
     *
     * Each parse starts from an empty schema, so its definitions only describe the relations and types
     * the SQL creates, and its impact names the earlier relations and types it changes. A definition of
     * a relation that may already exist could be a no-op, such as CREATE TABLE IF NOT EXISTS, so it
     * makes the relation unknown. Session state that separate calls carry, such as USE or SET
     * search_path, is not tracked: each call resolves names with the connection's configured
     * database and search path.
     */
    private function applyLiteralSql(ConnectionConfig $connection, string $sql): void
    {
        $owner = $this->owner($connection);

        try {
            $schema = $connection->createParser()?->parse($sql);
        } catch (ParseException) {
            $schema = null;
        }

        if ($schema === null || $schema->impact->unknown) {
            $owner->forget();

            return;
        }

        foreach ($schema->impact->relations as $relation) {
            $this->forgetTableByKey($connection, $this->resolveTable($connection, $relation->schema, $relation->name));
        }

        foreach ($schema->impact->types as $type) {
            $owner->forgetType($type->schema, $type->name, $connection->searchPath);
        }

        foreach ([...$schema->tables, ...$schema->views] as $definition) {
            $exists = ! $owner->complete
                || $this->findTableKey($connection, $definition->schema, $definition->name) !== null
                || $owner->caseVariants($definition->schema ?? $connection->defaultSchema, $definition->name) !== [];

            if ($definition instanceof View || $exists) {
                $this->forgetTableByKey($connection, $this->resolveTable($connection, $definition->schema, $definition->name));

                continue;
            }

            $owner->addTable($definition, $connection, $schema->issues === []);
        }

        // A type definition with an issue could have other labels or a different base.
        foreach ([...$schema->enums, ...$schema->domains] as $type) {
            $owner->types[$owner->key($type->schema, $type->name)] = $schema->issues === [] ? $type : null;
        }
    }
}
