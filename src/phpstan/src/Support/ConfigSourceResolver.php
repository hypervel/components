<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use Closure;
use Hypervel\Contracts\Config\Repository as RepositoryContract;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Configuration\ConfigMutationTracker;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Internal\RecursionGuard;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Bootstrap\LoadConfigurationWithWorkbench;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureExpression;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\MagicConst\File;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionClassConstant;
use ReflectionProperty;

use function array_any;
use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function dirname;
use function explode;
use function function_exists;
use function Hypervel\Testbench\workbench_path;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function spl_object_id;
use function str_starts_with;
use function strrpos;
use function strtolower;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Finds what a booted application loads into its configuration besides its own config
 * files: the framework defaults, the package files providers merge, and the keys that
 * providers and bootstrappers write. ConfigParser composes these into config types.
 *
 * Provider sources are read from their syntax trees and the booted providers' metadata.
 * No provider or configuration code runs.
 *
 * @internal
 */
#[AutowiredService]
class ConfigSourceResolver
{
    use HasContainer;

    /**
     * The names of the files a config directory loads, as a directory dependency pattern.
     */
    public const string CONFIG_FILE_PATTERN = '*.php';

    /**
     * Whether each merge method replaces recursively, keyed by lowercase name.
     */
    private const array MERGE_METHODS = ['mergeconfigfrom' => false, 'replaceconfigrecursivelyfrom' => true];

    /**
     * The repository methods that write a key, keyed by lowercase name, with the name of the
     * parameter holding the key.
     */
    private const array WRITE_METHODS = ['set' => 'key', 'push' => 'key', 'prepend' => 'key', 'offsetset' => 'offset', 'offsetunset' => 'offset'];

    /**
     * The repository methods that only read.
     */
    private const array READ_METHODS = [
        'all', 'array', 'boolean', 'collection', 'float', 'get', 'getmany',
        'has', 'integer', 'offsetexists', 'offsetget', 'string',
    ];

    /** @var null|list<string> */
    private ?array $configDirectories = null;

    /** @var null|list<string> */
    private ?array $bootedConfigDirectories = null;

    /**
     * The sources discovered from the loaded providers, or null before discovery.
     *
     * @var null|array{
     *     merges: list<array{key: string, path: null|string, recursive: bool, definite: bool, options: list<string>}>,
     *     writtenKeys: list<string>,
     *     unknownWrites: bool,
     *     packageFiles: array<string, true>,
     *     packageDirectories: list<string>,
     * }
     */
    private ?array $providerSources = null;

    /**
     * Provider files parsed during discovery, keyed by path.
     *
     * @var array<string, list<Stmt>>
     */
    private array $parsedFiles = [];

    /**
     * Create a new resolver instance.
     *
     * The single reflection files are PHPStan's normalized `--tmp-file` and
     * `--instead-of` paths, which are set in editor mode.
     *
     * @param list<non-empty-string> $configuredDirectories
     */
    public function __construct(
        private FileHelper $fileHelper,
        #[AutowiredParameter(ref: '@currentPhpVersionSimpleDirectParser')]
        private Parser $parser,
        private ReflectionProvider $reflectionProvider,
        #[AutowiredParameter(ref: '%configDirectories%')]
        private array $configuredDirectories,
        #[AutowiredParameter]
        private ?string $singleReflectionFile = null,
        #[AutowiredParameter]
        private ?string $singleReflectionInsteadOfFile = null,
    ) {
    }

    /**
     * Get the application's config directories, highest priority first.
     *
     * Configured directories replace the booted config path. Testbench's workbench
     * config directory overlays the booted path when its config discovery is on.
     *
     * @return list<string>
     */
    public function getConfigDirectories(): array
    {
        if ($this->configDirectories !== null) {
            return $this->configDirectories;
        }

        return $this->configDirectories = $this->configuredDirectories === []
            ? $this->getBootedConfigDirectories()
            : DirectoryResolver::resolve($this->configuredDirectories, $this->fileHelper);
    }

    /**
     * Get the config directories the booted application loaded, highest priority first.
     *
     * Testbench's workbench config directory overlays the config path when its config
     * discovery is on.
     *
     * @return list<string>
     */
    public function getBootedConfigDirectories(): array
    {
        if ($this->bootedConfigDirectories !== null) {
            return $this->bootedConfigDirectories;
        }

        $directories = [];
        $bootstrapper = $this->getApplication()->make(LoadConfiguration::class);

        if ($bootstrapper instanceof LoadConfigurationWithWorkbench
            && (fn (): bool => $this->usesWorkbenchConfigFile)->call($bootstrapper)) {
            $directories[] = workbench_path('config');
        }

        $directories[] = $this->getApplication()->configPath();

        return $this->bootedConfigDirectories = array_map($this->fileHelper->normalizePath(...), $directories);
    }

    /**
     * Get the paths whose contents can change a config key's loaded value, each mapped to
     * whether it is a directory of config files.
     *
     * In each config directory, these are the file of every prefix of the key, existing or
     * not, and the directory below the key, whose files make its value a merged one. Then
     * the framework's file for the key and the package files merged into an overlapping
     * key.
     *
     * @param list<string> $directories the config directories the value loads from
     * @return array<string, bool>
     */
    public function getKeySources(string $key, array $directories): array
    {
        $sources = [];
        $segments = explode('.', $key);

        foreach ($directories as $directory) {
            for ($length = count($segments); $length > 0; --$length) {
                $sources[$directory . '/' . implode('/', array_slice($segments, 0, $length)) . '.php'] = false;
            }

            $sources[$directory . '/' . implode('/', $segments)] = true;
        }

        if ($this->shouldMergeFrameworkConfiguration()) {
            $sources[$this->fileHelper->normalizePath(LoadConfiguration::frameworkConfigPath() . '/' . $segments[0] . '.php')] = false;
        }

        foreach ($this->getMerges() as $merge) {
            if ($merge['path'] !== null && $this->keysOverlap($key, $merge['key'])) {
                $sources[$merge['path']] = false;
            }
        }

        return $sources;
    }

    /**
     * Get the paths whose contents can change any loaded config value, each mapped to whether
     * it is a directory of config files: the config directories, the framework's config
     * directory and the package files merged into the configuration.
     *
     * @param list<string> $directories the config directories the values load from
     * @return array<string, bool>
     */
    public function getAllSources(array $directories): array
    {
        $sources = array_fill_keys($directories, true);

        if ($this->shouldMergeFrameworkConfiguration()) {
            $sources[$this->fileHelper->normalizePath(LoadConfiguration::frameworkConfigPath())] = true;
        }

        foreach ($this->getMerges() as $merge) {
            if ($merge['path'] !== null) {
                $sources[$merge['path']] = false;
            }
        }

        return $sources;
    }

    /**
     * Determine whether the framework's config files are merged beneath the application's.
     */
    public function shouldMergeFrameworkConfiguration(): bool
    {
        return $this->getApplication()->shouldMergeFrameworkConfiguration();
    }

    /**
     * Get the named-entry options LoadConfiguration merges for a framework config file.
     *
     * @return list<string>
     */
    public function getMergeableOptions(string $name): array
    {
        $bootstrapper = $this->getApplication()->make(LoadConfiguration::class);

        return array_values((fn (): array => $this->mergeableOptions($name))->call($bootstrapper));
    }

    /**
     * Get the package config files providers merge, in registration order.
     *
     * A merge is definite when every boot runs it; other merges may or may not run.
     * The options are the provider's named-entry options for the key.
     *
     * @return list<array{key: string, path: null|string, recursive: bool, definite: bool, options: list<string>}>
     */
    public function getMerges(): array
    {
        return $this->getProviderSources()['merges'];
    }

    /**
     * Get the config keys that providers and bootstrappers write.
     *
     * @return list<string>
     */
    public function getWrittenKeys(): array
    {
        return $this->getProviderSources()['writtenKeys'];
    }

    /**
     * Determine whether a provider writes config keys that cannot be found statically.
     */
    public function hasUnknownWrites(): bool
    {
        return $this->getProviderSources()['unknownWrites'];
    }

    /**
     * Determine whether the analyzed file is a config file.
     *
     * Besides the application's config directories, the framework's config and config:publish
     * stub directories and package config files are config files: those providers merge, and
     * those they publish into the config directory. In editor mode PHPStan analyzes a temporary
     * file, which stands in for the edited one.
     */
    public function isConfigFile(string $file): bool
    {
        $file = $this->fileHelper->normalizePath($file);

        if ($file === $this->singleReflectionFile) {
            $file = $this->singleReflectionInsteadOfFile ?? $file;
        }

        $configDirectories = [
            ...$this->getConfigDirectories(),
            $this->fileHelper->normalizePath(LoadConfiguration::frameworkConfigPath()),
            $this->fileHelper->normalizePath(LoadConfiguration::frameworkConfigStubPath()),
        ];

        foreach ($configDirectories as $configDirectory) {
            // Upstream's bare prefix match lets /app/config permit /app/config-sibling.
            if (str_starts_with($file, $configDirectory . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return $this->isPackageConfigFile($file);
    }

    /**
     * Determine whether the file is a package config file: one that a provider merges, or
     * one that a provider publishes into the config directory.
     */
    public function isPackageConfigFile(string $path): bool
    {
        $sources = $this->getProviderSources();
        $path = $this->fileHelper->normalizePath($path);

        if (isset($sources['packageFiles'][$path])) {
            return true;
        }

        foreach ($sources['packageDirectories'] as $directory) {
            if (str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Discover the configuration sources of the loaded providers.
     *
     * @return array{
     *     merges: list<array{key: string, path: null|string, recursive: bool, definite: bool, options: list<string>}>,
     *     writtenKeys: list<string>,
     *     unknownWrites: bool,
     *     packageFiles: array<string, true>,
     *     packageDirectories: list<string>,
     * }
     */
    private function getProviderSources(): array
    {
        if ($this->providerSources !== null) {
            return $this->providerSources;
        }

        $app = $this->getApplication();
        $merges = [];
        $writtenKeys = [];
        $mergedFiles = [];
        $unknownWrites = false;

        foreach ($app->getProviders(ServiceProvider::class) as $provider) {
            $unknownWrites = $this->discoverProvider($provider, $merges, $writtenKeys, $mergedFiles) || $unknownWrites;
        }

        // Providers run in an order this analysis does not follow, so merges of the same
        // or nested keys by different providers are not definite.
        foreach ($merges as $index => $merge) {
            foreach ($merges as $other) {
                if ($merge['definite'] && $other['provider'] !== $merge['provider'] && $this->keysOverlap($merge['key'], $other['key'])) {
                    $merges[$index]['definite'] = false;
                }
            }
        }

        // Direct writes after LoadConfiguration are recorded, such as Testbench's testing
        // connection and provider set() calls. Only their keys are read.
        $mutations = (fn (): array => $this->mutations)->call($app->make(ConfigMutationTracker::class));

        foreach ($mutations as $mutation) {
            if (! $mutation instanceof Closure) {
                foreach (array_keys($mutation) as $key) {
                    $writtenKeys[] = (string) $key;
                }
            }
        }

        $packageFiles = array_fill_keys($mergedFiles, true);
        $packageDirectories = [];

        $configDirectory = $this->fileHelper->normalizePath($app->configPath());

        foreach (ServiceProvider::pathsToPublish() as $source => $target) {
            $target = $this->fileHelper->normalizePath($target);

            if ($target !== $configDirectory && ! str_starts_with($target, $configDirectory . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (is_dir($source)) {
                $packageDirectories[] = $this->fileHelper->normalizePath($source);
            } else {
                $packageFiles[$this->fileHelper->normalizePath($source)] = true;
            }
        }

        $this->parsedFiles = [];

        return $this->providerSources = [
            'merges' => array_map(static function (array $merge): array {
                unset($merge['provider']);

                return $merge;
            }, $merges),
            // The direct pass also scans configureUsing() callbacks, so keys repeat.
            'writtenKeys' => array_values(array_unique($writtenKeys)),
            'unknownWrites' => $unknownWrites,
            'packageFiles' => $packageFiles,
            'packageDirectories' => $packageDirectories,
        ];
    }

    /**
     * Collect a provider's merges and the keys it writes, in configureUsing() callbacks
     * and directly.
     *
     * Returns whether the provider writes keys that cannot be found statically.
     *
     * @param list<array{key: string, path: null|string, recursive: bool, definite: bool, options: list<string>, provider: string}> $merges
     * @param list<string> $writtenKeys
     * @param list<string> $mergedFiles the files merged, including those whose key is unknown
     */
    private function discoverProvider(ServiceProvider $provider, array &$merges, array &$writtenKeys, array &$mergedFiles): bool
    {
        // An anonymous provider cannot be reflected by name. Neither can any provider when PHPStan
        // validates stub files that are not in the vendor directory, against the stubs alone.
        if (! $this->reflectionProvider->hasClass($provider::class)) {
            return true;
        }

        $providerClass = $this->reflectionProvider->getClass($provider::class);
        $classLikes = $this->getClassLikes($providerClass);

        if ($classLikes === null) {
            return true;
        }

        /** @var list<array{MethodCall, string, string, bool}> $definiteCalls */
        $definiteCalls = [];
        $this->collectDefiniteMerges($providerClass, $providerClass, 'register', $definiteCalls);

        $calls = [];

        foreach ($definiteCalls as [$call, $file, $selfClass, $recursive]) {
            $calls[] = [$call, $file, $selfClass, $recursive, true];
        }

        $reached = [];

        foreach ($definiteCalls as [$call]) {
            $reached[spl_object_id($call)] = true;
        }

        $unknownWrites = false;
        $nodeFinder = new NodeFinder;

        foreach ($classLikes as [$classLike, $file, $selfClass]) {
            $context = [$file, $selfClass, $provider];

            foreach ($nodeFinder->findInstanceOf($classLike, MethodCall::class) as $call) {
                if (! $this->isThisCall($call) || ! $call->name instanceof Identifier) {
                    continue;
                }

                $name = $call->name->toLowerString();

                // A callable created from a merge method has no arguments, so its merge stays unknown.
                if (isset(self::MERGE_METHODS[$name]) && ! isset($reached[spl_object_id($call)])) {
                    $calls[] = [$call, $file, $selfClass, self::MERGE_METHODS[$name], false];
                }

                if ($name === 'configureusing') {
                    $unknownWrites = ! $this->collectCallbackWrites($call, $context, $writtenKeys) || $unknownWrites;
                }
            }

            foreach ($nodeFinder->findInstanceOf($classLike, FunctionLike::class) as $function) {
                $unknownWrites = ! $this->collectDirectWrites($function, $context, $writtenKeys) || $unknownWrites;
            }
        }

        foreach ($calls as [$call, $file, $selfClass, $recursive, $definite]) {
            $key = $this->evaluateArgument($call, 'key', 1, $file, $selfClass, $provider);
            $path = $this->evaluateArgument($call, 'path', 0, $file, $selfClass, $provider);
            $path = is_string($path) ? $this->fileHelper->normalizePath($path) : null;

            // A known file is a package config file even when the key it merges under is not known.
            if ($path !== null) {
                $mergedFiles[] = $path;
            }

            if (! is_string($key)) {
                $unknownWrites = true;

                continue;
            }

            $merges[] = [
                'key' => $key,
                'path' => $path,
                'recursive' => $recursive,
                'definite' => $definite && $path !== null,
                'options' => $recursive ? [] : array_values((fn (): array => $this->mergeableOptions($key))->call($provider)),
                'provider' => $provider::class,
            ];
        }

        return $unknownWrites;
    }

    /**
     * Collect the merge calls on the straight line through a provider method: its top-level
     * statements, following parent::register() and $this->helper() statements, until a
     * statement that can return. A helper called twice runs twice.
     *
     * @param list<array{MethodCall, string, string, bool}> $definiteCalls the merge calls, files, self classes and whether each merge is recursive
     */
    private function collectDefiniteMerges(ClassReflection $providerClass, ClassReflection $startClass, string $methodName, array &$definiteCalls): void
    {
        $method = $this->findMethod($startClass, $methodName);

        if ($method === null) {
            return;
        }

        [$methodNode, $file, $selfClass, $declaringClass] = $method;

        RecursionGuard::run('hypervel.configProvider.' . $declaringClass->getName() . '::' . $methodNode->name->toLowerString(), function () use ($providerClass, $methodNode, $file, $selfClass, $declaringClass, &$definiteCalls): bool {
            foreach ($methodNode->stmts ?? [] as $stmt) {
                // Creating a callable runs nothing, so it is neither a merge nor a call to follow.
                $call = $stmt instanceof Expression && $stmt->expr instanceof CallLike && ! $stmt->expr->isPartialFunctionApplication()
                    ? $stmt->expr
                    : null;

                if ($call instanceof MethodCall && $this->isThisCall($call) && $call->name instanceof Identifier) {
                    if (isset(self::MERGE_METHODS[$call->name->toLowerString()])) {
                        $definiteCalls[] = [$call, $file, $selfClass, self::MERGE_METHODS[$call->name->toLowerString()]];
                    } else {
                        $this->collectDefiniteMerges($providerClass, $providerClass, $call->name->toString(), $definiteCalls);
                    }
                }

                if ($call instanceof StaticCall
                    && $call->class instanceof Name
                    && $call->class->toLowerString() === 'parent'
                    && $call->name instanceof Identifier
                    && $call->name->toLowerString() === 'register'
                    && $declaringClass->getParentClass() !== null) {
                    $this->collectDefiniteMerges($providerClass, $declaringClass->getParentClass(), 'register', $definiteCalls);
                }

                if ($this->canReturn($stmt)) {
                    break;
                }
            }

            return true;
        });
    }

    /**
     * Collect the keys a configureUsing() callback writes.
     *
     * The tracker records only the callback, so every write it can make must be found here.
     * Returns false when one cannot be: the callback is not a closure, as when
     * configureUsing(...) creates a callable, or its repository escapes or receives an
     * unknown call.
     *
     * @param array{string, string, ServiceProvider} $context the file, self class and provider
     * @param list<string> $writtenKeys
     */
    private function collectCallbackWrites(MethodCall $call, array $context, array &$writtenKeys): bool
    {
        $callback = $call->getArg('callback', 0)?->value;

        if (! $callback instanceof ClosureExpression && ! $callback instanceof ArrowFunction) {
            return false;
        }

        $receivers = [];

        if ($callback->params !== []) {
            $variable = $callback->params[0]->var;

            if ($callback->params[0]->byRef || $callback->params[0]->variadic || ! $variable instanceof Variable || ! is_string($variable->name)) {
                return false;
            }

            $receivers[$variable->name] = true;
        }

        foreach ($callback->getStmts() as $stmt) {
            if (! $this->collectWrites($stmt, $receivers, true, $context, $writtenKeys)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Collect the keys a provider function writes directly through a recognized repository,
     * including in branches the analysis boot did not run.
     *
     * The tracker already records every write the boot ran, whatever its receiver, so a
     * repository flow this cannot follow, such as a local variable, is left to it. Returns
     * false for a recognized write whose key cannot be bounded.
     *
     * @param array{string, string, ServiceProvider} $context
     * @param list<string> $writtenKeys
     */
    private function collectDirectWrites(FunctionLike $function, array $context, array &$writtenKeys): bool
    {
        $receivers = [];

        foreach ($function->getParams() as $parameter) {
            if ($parameter->var instanceof Variable && is_string($parameter->var->name) && $this->isRepositoryType($parameter->type)) {
                $receivers[$parameter->var->name] = true;
            }
        }

        foreach ($function->getStmts() ?? [] as $stmt) {
            if (! $this->collectWrites($stmt, $receivers, false, $context, $writtenKeys)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Collect the config writes in a node.
     *
     * Writes are calls and offset writes on a recognized repository, the config([...])
     * helper and the Config facade. Returns false when a write's key cannot be bounded. When
     * strict, as for callbacks, it also returns false when the repository escapes or receives
     * an unknown call, including a bare config() used as a value. Otherwise those flows are
     * left to the tracker, and nested functions are skipped, being collected on their own.
     *
     * @param array<string, true> $receivers the variables holding the repository
     * @param array{string, string, ServiceProvider} $context
     * @param list<string> $writtenKeys
     */
    private function collectWrites(Node $node, array $receivers, bool $strict, array $context, array &$writtenKeys): bool
    {
        $children = $node->getSubNodeNames();

        if (! $strict && $node instanceof FunctionLike) {
            return true;
        }

        if (($node instanceof MethodCall && $this->isRepository($node->var, $receivers, $context))
            || ($node instanceof StaticCall && $node->class instanceof Name && $this->isConfigFacade($node->class))) {
            if (! $this->collectMethodWrites($node, $strict, $context, $writtenKeys)) {
                return false;
            }

            $children = ['args'];
        } elseif ($node instanceof FuncCall && $node->name instanceof Name && $this->isGlobalFunction($node->name, 'config') && $node->getRawArgs() !== []) {
            // config(...) creates a callable that can later write any key.
            if ($node->isPartialFunctionApplication()) {
                return ! $strict;
            }

            $key = $node->getArg('key', 0)?->value;

            if ($key instanceof Expr\Array_) {
                if (! $this->collectArrayKeys($key, $context, $writtenKeys)) {
                    return false;
                }
            } elseif ($strict && ! $key instanceof Scalar && ! $key instanceof Concat) {
                // Another key, including an unpacked one, may be an array of values to set.
                return false;
            }
        } elseif (($node instanceof Assign || $node instanceof AssignRef || $node instanceof AssignOp)
            && $node->var instanceof ArrayDimFetch
            && $this->getRootDimension($node->var, $receivers, $context) !== null) {
            if (! $this->collectDimensionWrite($node->var, $receivers, $context, $writtenKeys)) {
                return false;
            }

            $children = ['expr'];
        } elseif ($node instanceof Unset_) {
            foreach ($node->vars as $variable) {
                if ($variable instanceof ArrayDimFetch && $this->getRootDimension($variable, $receivers, $context) !== null) {
                    if (! $this->collectDimensionWrite($variable, $receivers, $context, $writtenKeys)) {
                        return false;
                    }
                } elseif (! $this->collectWrites($variable, $receivers, $strict, $context, $writtenKeys)) {
                    return false;
                }
            }

            $children = [];
        } elseif ($node instanceof ArrayDimFetch && $this->getRootDimension($node, $receivers, $context) !== null) {
            // An offset read; only its dimensions can hold writes.
            for ($fetch = $node; $fetch instanceof ArrayDimFetch; $fetch = $fetch->var) {
                if ($fetch->dim !== null && ! $this->collectWrites($fetch->dim, $receivers, $strict, $context, $writtenKeys)) {
                    return false;
                }
            }

            $children = [];
        } elseif ($node instanceof ClosureExpression) {
            foreach ($node->uses as $use) {
                if (is_string($use->var->name) && isset($receivers[$use->var->name])) {
                    return false;
                }
            }
        } elseif ($node instanceof Expr && $this->isRepository($node, $receivers, $context)) {
            // The repository is passed, stored or returned.
            return ! $strict;
        }

        foreach ($children as $child) {
            $subNodes = $node->{$child};

            foreach (is_array($subNodes) ? $subNodes : [$subNodes] as $subNode) {
                if ($subNode instanceof Node && ! $this->collectWrites($subNode, $receivers, $strict, $context, $writtenKeys)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Collect the key a repository or Config facade method call writes.
     *
     * @param array{string, string, ServiceProvider} $context
     * @param list<string> $writtenKeys
     */
    private function collectMethodWrites(MethodCall|StaticCall $call, bool $strict, array $context, array &$writtenKeys): bool
    {
        if (! $call->name instanceof Identifier) {
            return ! $strict;
        }

        $name = $call->name->toLowerString();

        // A callable created from a read method can only read.
        if (in_array($name, self::READ_METHODS, true)) {
            return true;
        }

        // An unknown method, or a callable created from a write method, may write any key.
        if ($call->isPartialFunctionApplication() || ! isset(self::WRITE_METHODS[$name])) {
            return ! $strict;
        }

        // An unpacked key is not found, so it stays unknown.
        $key = $call->getArg(self::WRITE_METHODS[$name], 0)?->value;

        if ($name === 'set' && $key instanceof Expr\Array_) {
            return $this->collectArrayKeys($key, $context, $writtenKeys);
        }

        return $key !== null && $this->collectKey($key, $context, $writtenKeys);
    }

    /**
     * Collect the keys of an array of config values to set.
     *
     * @param array{string, string, ServiceProvider} $context
     * @param list<string> $writtenKeys
     */
    private function collectArrayKeys(Expr\Array_ $array, array $context, array &$writtenKeys): bool
    {
        foreach ($array->items as $item) {
            if ($item->key === null || ! $this->collectKey($item->key, $context, $writtenKeys)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Collect the key an offset write on a recognized repository writes.
     *
     * @param array<string, true> $receivers
     * @param array{string, string, ServiceProvider} $context
     * @param list<string> $writtenKeys
     */
    private function collectDimensionWrite(ArrayDimFetch $fetch, array $receivers, array $context, array &$writtenKeys): bool
    {
        /** @var ArrayDimFetch $root */
        $root = $this->getRootDimension($fetch, $receivers, $context);

        return $root->dim !== null && $this->collectKey($root->dim, $context, $writtenKeys);
    }

    /**
     * Collect a written key. A key that does not resolve is bounded by its constant leading
     * segments: `'filesystems.disks.' . $name` writes below `filesystems.disks`, and
     * `'filesystems.disks' . $suffix` below `filesystems`, since the suffix may extend `disks`.
     *
     * Returns false when not even the first segment is constant.
     *
     * @param array{string, string, ServiceProvider} $context
     * @param list<string> $writtenKeys
     */
    private function collectKey(Expr $key, array $context, array &$writtenKeys): bool
    {
        $value = $this->evaluate($key, ...$context);

        if (is_string($value) || is_int($value)) {
            $writtenKeys[] = (string) $value;

            return true;
        }

        $prefix = $this->getConstantPrefix($key, $context);
        $boundary = $prefix === null ? false : strrpos($prefix, '.');

        if ($boundary === false || $boundary === 0) {
            return false;
        }

        $writtenKeys[] = substr($prefix, 0, $boundary);

        return true;
    }

    /**
     * Get the constant leading part of a string expression built by concatenation or
     * interpolation, or null when it has none.
     *
     * @param array{string, string, ServiceProvider} $context
     */
    private function getConstantPrefix(Expr $expr, array $context): ?string
    {
        if ($expr instanceof Concat) {
            $left = $this->evaluate($expr->left, ...$context);

            return is_string($left)
                ? $left . ($this->getConstantPrefix($expr->right, $context) ?? '')
                : $this->getConstantPrefix($expr->left, $context);
        }

        if ($expr instanceof InterpolatedString) {
            $prefix = '';

            foreach ($expr->parts as $part) {
                if (! $part instanceof InterpolatedStringPart) {
                    break;
                }

                $prefix .= $part->value;
            }

            return $prefix;
        }

        $value = $this->evaluate($expr, ...$context);

        return is_string($value) ? $value : null;
    }

    /**
     * Get the offset fetch applied directly to a recognized repository beneath the fetch, if any.
     *
     * @param array<string, true> $receivers
     * @param array{string, string, ServiceProvider} $context
     */
    private function getRootDimension(ArrayDimFetch $fetch, array $receivers, array $context): ?ArrayDimFetch
    {
        while ($fetch->var instanceof ArrayDimFetch) {
            $fetch = $fetch->var;
        }

        return $this->isRepository($fetch->var, $receivers, $context) ? $fetch : null;
    }

    /**
     * Determine whether the expression is the config repository: a variable holding it, a
     * bare config(), app() or resolve() with its id, or the provider application's make()
     * or get() with its id. The container has no ArrayAccess, so `$this->app['config']` is
     * not a form.
     *
     * @param array<string, true> $receivers
     * @param array{string, string, ServiceProvider} $context
     */
    private function isRepository(Expr $expr, array $receivers, array $context): bool
    {
        if ($expr instanceof Variable) {
            return is_string($expr->name) && isset($receivers[$expr->name]);
        }

        // A created callable is not the repository: getArg() finds no argument past a "..." placeholder.
        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            if ($this->isGlobalFunction($expr->name, 'config')) {
                return $expr->getRawArgs() === [];
            }

            $id = match (true) {
                $this->isGlobalFunction($expr->name, 'app') => $expr->getArg('abstract', 0),
                $this->isGlobalFunction($expr->name, 'resolve') => $expr->getArg('name', 0),
                default => null,
            };

            return $id !== null && $this->isRepositoryId($id->value, $context);
        }

        if (! $expr instanceof MethodCall
            || ! $expr->var instanceof PropertyFetch
            || ! $expr->var->var instanceof Variable
            || $expr->var->var->name !== 'this'
            || ! $expr->var->name instanceof Identifier
            || $expr->var->name->toString() !== 'app'
            || ! $expr->name instanceof Identifier) {
            return false;
        }

        $id = match ($expr->name->toLowerString()) {
            'make' => $expr->getArg('abstract', 0),
            'get' => $expr->getArg('id', 0),
            default => null,
        };

        return $id !== null && $this->isRepositoryId($id->value, $context);
    }

    /**
     * Determine whether a container id resolves the config repository.
     *
     * @param array{string, string, ServiceProvider} $context
     */
    private function isRepositoryId(Expr $id, array $context): bool
    {
        $id = $this->evaluate($id, ...$context);

        return is_string($id) && ($id === 'config' || $this->isRepositoryClass($id));
    }

    /**
     * Determine whether a parameter type is the config repository's contract or a class implementing it.
     */
    private function isRepositoryType(?Node $type): bool
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        return $type instanceof Name && ! $type->isSpecialClassName() && $this->isRepositoryClass($type->toString());
    }

    /**
     * Determine whether the class is the config repository's contract or implements it.
     */
    private function isRepositoryClass(string $class): bool
    {
        return $this->reflectionProvider->hasClass($class)
            && $this->reflectionProvider->getClass($class)->is(RepositoryContract::class);
    }

    /**
     * Determine whether the class name refers to the Config facade.
     */
    private function isConfigFacade(Name $class): bool
    {
        $name = $class->toString();

        return ! $class->isSpecialClassName()
            && $this->reflectionProvider->hasClass($name)
            && $this->reflectionProvider->getClass($name)->is(Config::class);
    }

    /**
     * Determine whether the call's name refers to the given global function.
     */
    private function isGlobalFunction(Name $name, string $function): bool
    {
        $namespacedName = $name->getAttribute('namespacedName');

        // An unqualified call resolves to a namespaced function of the same name when one exists.
        if ($namespacedName instanceof FullyQualified && function_exists($namespacedName->toString())) {
            return false;
        }

        return $name->toLowerString() === $function;
    }

    /**
     * Determine whether the call is made on $this.
     */
    private function isThisCall(MethodCall $call): bool
    {
        return $call->var instanceof Variable && $call->var->name === 'this';
    }

    /**
     * Determine whether two config keys are the same or one is nested in the other.
     */
    private function keysOverlap(string $key, string $other): bool
    {
        return $key === $other || str_starts_with($key, $other . '.') || str_starts_with($other, $key . '.');
    }

    /**
     * Determine whether the statement can return from its function.
     */
    private function canReturn(Stmt $stmt): bool
    {
        $visitor = new class extends NodeVisitorAbstract {
            public bool $returns = false;

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\FunctionLike || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Return_) {
                    $this->returns = true;

                    return NodeVisitor::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        (new NodeTraverser($visitor))->traverse([$stmt]);

        return $visitor->returns;
    }

    /**
     * Evaluate a call argument as a constant expression, or return null.
     */
    private function evaluateArgument(MethodCall $call, string $name, int $position, string $file, string $selfClass, ServiceProvider $provider): mixed
    {
        $argument = $call->getArg($name, $position);

        return $argument === null ? null : $this->evaluate($argument->value, $file, $selfClass, $provider);
    }

    /**
     * Evaluate a constant expression in provider code, or return null when it is not constant.
     *
     * Besides literals, it resolves the file's __DIR__ and __FILE__, dirname() calls, class
     * constants and the current value of a static string property, which providers such as
     * Sentry's use as boot metadata. PHPStan does not fold path constants by default.
     */
    private function evaluate(Expr $expr, string $file, string $selfClass, ServiceProvider $provider): mixed
    {
        $evaluator = new ConstExprEvaluator(function (Expr $expr) use (&$evaluator, $file, $selfClass, $provider): mixed {
            // Errors such as an undefined constant surface as ConstExprEvaluationException through evaluateSilently().
            if ($expr instanceof Dir) {
                return dirname($file);
            }

            if ($expr instanceof File) {
                return $file;
            }

            if ($expr instanceof FuncCall && $expr->name instanceof Name && $this->isGlobalFunction($expr->name, 'dirname')) {
                $path = $expr->getArg('path', 0);
                $levels = $expr->getArg('levels', 1);

                if ($path === null || array_any($expr->getArgs(), static fn (Node\Arg $argument): bool => $argument->unpack)) {
                    throw new ConstExprEvaluationException('Unpacked arguments are not constant.');
                }

                return dirname($evaluator->evaluateDirectly($path->value), $levels === null ? 1 : $evaluator->evaluateDirectly($levels->value));
            }

            if ($expr instanceof ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier) {
                $class = $this->resolveClassName($expr->class, $selfClass, $provider);

                // Reflection reads protected and private constants, which providers often use.
                return $expr->name->toLowerString() === 'class'
                    ? $class
                    : (new ReflectionClassConstant($class, $expr->name->toString()))->getValue();
            }

            if ($expr instanceof StaticPropertyFetch
                && $expr->class instanceof Name
                && in_array($expr->class->toLowerString(), ['self', 'static'], true)
                && $expr->name instanceof VarLikeIdentifier) {
                $value = (new ReflectionProperty($this->resolveClassName($expr->class, $selfClass, $provider), $expr->name->toString()))->getValue();

                if (is_string($value)) {
                    return $value;
                }
            }

            throw new ConstExprEvaluationException('Expression is not constant.');
        });

        try {
            return $evaluator->evaluateSilently($expr);
        } catch (ConstExprEvaluationException) {
            return null;
        }
    }

    /**
     * Resolve a class name in provider code, including self, static and parent.
     *
     * @throws ConstExprEvaluationException
     */
    private function resolveClassName(Name $class, string $selfClass, ServiceProvider $provider): string
    {
        return match ($class->toLowerString()) {
            'self' => $selfClass,
            'static' => $provider::class,
            'parent' => $this->reflectionProvider->getClass($selfClass)->getParentClass()?->getName()
                ?? throw new ConstExprEvaluationException('The class has no parent.'),
            default => $class->toString(),
        };
    }

    /**
     * Find a method's declaration, searching the class, its traits and its parents below ServiceProvider.
     *
     * @return null|array{ClassMethod, string, string, ClassReflection} the method, its file, its self class and its declaring class
     */
    private function findMethod(ClassReflection $class, string $methodName): ?array
    {
        for ($current = $class; $current !== null && $current->getName() !== ServiceProvider::class; $current = $current->getParentClass()) {
            foreach ($this->getOwnClassLikes($current) ?? [] as [$classLike, $file]) {
                $method = $classLike->getMethod($methodName);

                if ($method !== null) {
                    return [$method, $file, $current->getName(), $current];
                }
            }
        }

        return null;
    }

    /**
     * Get the class declarations of a provider's hierarchy below ServiceProvider, with their traits.
     *
     * Returns null when a declaration has no source file to read.
     *
     * @return null|list<array{ClassLike, string, string}> each declaration, its file and its self class
     */
    private function getClassLikes(ClassReflection $class): ?array
    {
        $classLikes = [];

        for ($current = $class; $current !== null && $current->getName() !== ServiceProvider::class; $current = $current->getParentClass()) {
            $ownClassLikes = $this->getOwnClassLikes($current);

            if ($ownClassLikes === null) {
                return null;
            }

            foreach ($ownClassLikes as [$classLike, $file]) {
                $classLikes[] = [$classLike, $file, $current->getName()];
            }
        }

        return $classLikes;
    }

    /**
     * Get a class's own declaration followed by those of its traits, depth first.
     *
     * @return null|list<array{ClassLike, string}>
     */
    private function getOwnClassLikes(ClassReflection $class): ?array
    {
        $file = $class->getFileName();

        if ($file === null) {
            return null;
        }

        $this->parsedFiles[$file] ??= $this->parser->parseFile($file);

        $classLike = (new NodeFinder)->findFirst(
            $this->parsedFiles[$file],
            static fn (Node $node): bool => $node instanceof ClassLike
                && strtolower($node->namespacedName?->toString() ?? '') === strtolower($class->getName()),
        );

        if (! $classLike instanceof ClassLike) {
            return null;
        }

        $classLikes = [[$classLike, $file]];

        foreach ($class->getTraits() as $trait) {
            $traitClassLikes = $this->getOwnClassLikes($trait);

            if ($traitClassLikes === null) {
                return null;
            }

            foreach ($traitClassLikes as $traitClassLike) {
                $classLikes[] = $traitClassLike;
            }
        }

        return $classLikes;
    }

    /**
     * Get the booted application.
     */
    private function getApplication(): Application
    {
        return $this->getContainer()->make(Application::class);
    }
}
