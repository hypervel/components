<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use ArrayAccess;
use FilesystemIterator;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\PHPStan\Internal\RecursionGuard;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Analyser\StatementContext;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_any;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_pop;
use function array_search;
use function array_slice;
use function basename;
use function count;
use function explode;
use function glob;
use function implode;
use function in_array;
use function is_int;
use function ksort;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strnatcmp;
use function substr;

use const DIRECTORY_SEPARATOR;
use const SORT_NATURAL;

/**
 * Types config values from the sources Hypervel loads: the application's config files,
 * the framework's config files beneath them, and the package files providers merge.
 * The merges and writes the analysis finds widen the composed types. A write it cannot
 * follow, such as one through a local variable in a branch the analysis boot does not
 * run, is not modeled.
 *
 * Upstream types only the application's files and treats a key no file declares as
 * holding its default.
 */
#[AutowiredService]
class ConfigParser
{
    /**
     * Config file paths keyed like Hypervel's config keys: `nested/stripe.php`
     * in a config directory is keyed `nested.stripe`. Null until loaded.
     *
     * @var null|array<string, string>
     */
    private ?array $configFiles = null;

    /**
     * Key prefixes with config files below them, whose values Hypervel merges
     * from several files.
     *
     * @var array<string, true>
     */
    private array $mergedConfigKeys = [];

    /**
     * The framework config files merged beneath the application's, keyed by name.
     *
     * @var array<string, string>
     */
    private array $frameworkConfigFiles = [];

    /**
     * Each config key's presence and value type, or null when its value is unknown. A read
     * while an application config file loads is keyed by that file's key, a NUL byte and the key.
     *
     * @var array<string, null|array{TrinaryLogic, Type}>
     */
    private array $parsedConfigs = [];

    /**
     * Each top-level key's composed value, held in an otherwise empty root array, keyed as
     * the parsed values are.
     *
     * @var array<string, ConfigArray>
     */
    private array $composedConfigs = [];

    /**
     * Each config file's return statement, the statements before it and its namespace,
     * or null when the file has no single return value.
     *
     * @var array<string, null|array{Return_, list<Node\Stmt>, null|string}>
     */
    private array $parsedConfigFiles = [];

    /**
     * The scope at each config file's return statement, or null when the
     * statements before it always terminate.
     *
     * @var array<string, null|Scope>
     */
    private array $returnScopes = [];

    /**
     * Files and directories the statements before a config file's return depend on,
     * such as the config files they read, each path mapped to whether it is a directory.
     *
     * @var array<string, array<string, bool>>
     */
    private array $configFileDependencies = [];

    /**
     * Files and directories a config key's value depends on besides the key's own
     * sources, such as config files its expression reads.
     *
     * @var array<string, array<string, bool>>
     */
    private array $keyDependencies = [];

    /**
     * Files and directories each top-level key's composed value depends on.
     *
     * @var array<string, array<string, bool>>
     */
    private array $compositionDependencies = [];

    /**
     * The config file each value expression is in, keyed by the expression's object ID.
     *
     * @var array<int, string>
     */
    private array $expressionFiles = [];

    /**
     * Each value expression's type, or null when unknown, keyed by its object ID.
     *
     * @var array<int, null|Type>
     */
    private array $expressionTypes = [];

    /**
     * Files and directories each value expression's type depends on, keyed by its object ID.
     *
     * @var array<int, array<string, bool>>
     */
    private array $expressionDependencies = [];

    /**
     * Dependencies collected for the config files and keys being typed, so
     * a cached result can declare them again to every file reading it.
     *
     * @var list<array<string, bool>>
     */
    private array $dependencyCollectors = [];

    /**
     * The paths whose contents can change each config key's value, each mapped to whether it is a directory.
     *
     * @var array<string, array<string, bool>>
     */
    private array $keySources = [];

    /**
     * How many lookups found their config file, expression or composed value already
     * being typed further up the call stack. Results typed meanwhile are not cached.
     */
    private int $inProgressLookups = 0;

    /**
     * Create a new parser instance.
     */
    public function __construct(
        private FileHelper $fileHelper,
        #[AutowiredParameter(ref: '@currentPhpVersionRichParser')]
        private Parser $parser,
        private FileTypeMapper $fileTypeMapper,
        private ScopeFactory $scopeFactory,
        private NodeScopeResolver $nodeScopeResolver,
        private ConfigSourceResolver $configSourceResolver,
    ) {
    }

    /**
     * Get whether a config key is present and its value's type, or null when unknown.
     *
     * The presence decides whether a lookup's default can apply: a present key returns
     * its value even when it is null, and an absent one returns the default.
     *
     * @param DependencyTracker&Scope $scope
     * @return null|array{TrinaryLogic, Type}
     */
    public function getValue(string $key, Scope $scope): ?array
    {
        // Every analysed file reading the key depends on its sources, parsed or served from the cache.
        $this->trackDependencies($scope, $this->keySources[$key] ??= $this->configSourceResolver->getKeySources(
            $key,
            $this->configSourceResolver->getConfigDirectories(),
        ));
        $this->loadConfigFiles();

        // A config file reads the configuration as it stands while the file loads. Framework
        // files load before any value is set, and providers merge package files in an order
        // the analysis does not follow, so only an application file's reads are typed.
        $file = $this->fileHelper->normalizePath($scope->getFile());
        $loadingKey = array_search($file, $this->configFiles ?? [], true);

        if ($loadingKey === false && (in_array($file, $this->frameworkConfigFiles, true) || $this->configSourceResolver->isPackageConfigFile($file))) {
            return null;
        }

        // A function or closure body may run while the file loads or long after.
        if ($loadingKey !== false && ($scope->getFunction() !== null || $scope->isInAnonymousFunction())) {
            return null;
        }

        $loadingKey = $loadingKey === false ? null : (string) $loadingKey;
        $cacheKey = $loadingKey === null ? $key : $loadingKey . "\0" . $key;

        if (array_key_exists($cacheKey, $this->parsedConfigs)) {
            $this->trackDependencies($scope, $this->keyDependencies[$cacheKey] ?? []);

            return $this->parsedConfigs[$cacheKey];
        }

        // Writes run only once every config file has loaded.
        if (array_key_exists($key, $this->mergedConfigKeys) || ($loadingKey === null && $this->configSourceResolver->hasUnknownWrites())) {
            return null;
        }

        $inProgressLookups = $this->inProgressLookups;
        $segments = explode('.', $key);

        [$value, $dependencies] = $this->collectDependencies(function () use ($segments, $loadingKey, $scope): ?array {
            $root = $this->getComposedConfig($segments[0], $loadingKey, $scope);

            return $root === null ? null : $this->lookup($root, $segments, $scope);
        });

        $this->trackDependencies($scope, $dependencies);

        if ($this->inProgressLookups === $inProgressLookups) {
            $this->keyDependencies[$cacheKey] = $dependencies;
            $this->parsedConfigs[$cacheKey] = $value;
        }

        return $value;
    }

    /**
     * Walk a composed value to a key, as Arr::get() does.
     *
     * @param list<string> $segments
     * @param DependencyTracker&Scope $scope
     * @return null|array{TrinaryLogic, Type}
     */
    private function lookup(ConfigArray $root, array $segments, Scope $scope): ?array
    {
        $presence = TrinaryLogic::createYes();
        $value = $root;

        foreach ($segments as $segment) {
            $key = $this->toArrayKey($segment);

            if ($value instanceof Expr) {
                $value = $this->getExpressionType($value, $scope);
            }

            if ($value instanceof ConfigArray) {
                if (! array_key_exists($key, $value->entries)) {
                    return $value->open ? null : [TrinaryLogic::createNo(), new NeverType];
                }

                if (isset($value->optionalKeys[$key])) {
                    $presence = $presence->and(TrinaryLogic::createMaybe());
                }

                $value = $value->entries[$key];

                continue;
            }

            if ($value === null) {
                return null;
            }

            if ($value->isArray()->yes()) {
                $offset = $this->getKeyType($key);
                $hasOffset = $value->hasOffsetValueType($offset);

                if ($hasOffset->no()) {
                    return [TrinaryLogic::createNo(), new NeverType];
                }

                $presence = $presence->and($hasOffset);
                $value = $value->getOffsetValueType($offset);

                continue;
            }

            // Arr::get() stops at a value it cannot index into, and returns the default.
            if ($value->isArray()->no() && (new ObjectType(ArrayAccess::class))->isSuperTypeOf($value)->no()) {
                return [TrinaryLogic::createNo(), new NeverType];
            }

            return null;
        }

        $type = $this->toType($value, $scope);

        return $type === null ? null : [$presence, $type];
    }

    /**
     * Get the root array holding a top-level key's composed value, or null while it is
     * being composed further up the call stack.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function getComposedConfig(string $name, ?string $loadingKey, Scope $scope): ?ConfigArray
    {
        $cacheKey = $loadingKey === null ? $name : $loadingKey . "\0" . $name;

        if (array_key_exists($cacheKey, $this->composedConfigs)) {
            $this->trackDependencies($scope, $this->compositionDependencies[$cacheKey]);

            return $this->composedConfigs[$cacheKey];
        }

        $inProgressLookups = $this->inProgressLookups;

        [$result, $dependencies] = $this->collectDependencies(fn (): ?array => $this->guard(
            'hypervel.configComposition.' . $cacheKey,
            fn (): ConfigArray => $this->composeConfig($name, $loadingKey, $scope),
        ));

        if ($result === null) {
            return null;
        }

        if ($this->inProgressLookups === $inProgressLookups) {
            $this->compositionDependencies[$cacheKey] = $dependencies;
            $this->composedConfigs[$cacheKey] = $result[0];
        }

        return $result[0];
    }

    /**
     * Compose a top-level key's value in the order Hypervel loads it, then widen it for the
     * writes and merges that may or may not run.
     *
     * With a loading key, compose only what is set while that application config file loads:
     * the framework entries without a file of their own and the files before it in load order.
     * Providers merge and write only once every file has loaded.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function composeConfig(string $name, ?string $loadingKey, Scope $scope): ConfigArray
    {
        $root = new ConfigArray;
        $frameworkFile = $this->frameworkConfigFiles[$name] ?? null;
        $applicationFile = $this->configFiles[$name] ?? null;
        $mergeableOptions = $this->configSourceResolver->getMergeableOptions($name);
        $isLoaded = static fn (string $configKey): bool => $loadingKey === null || strnatcmp($configKey, $loadingKey) < 0;

        // The framework entry is set with the application file, and files nested below come after it.
        if ($applicationFile !== null && ! $isLoaded($name)) {
            return $root;
        }

        if ($frameworkFile !== null) {
            $this->trackDependencies($scope, [$frameworkFile => false]);
            $root = new ConfigArray([$name => $this->getFileValue($frameworkFile)]);
        }

        if ($applicationFile !== null) {
            $value = $this->getFileValue($applicationFile);

            $root = new ConfigArray([$name => $frameworkFile === null ? $value : $this->merge(
                $root->entries[$name],
                $value,
                $mergeableOptions,
                $scope,
            )]);
        }

        foreach ($this->configFiles ?? [] as $configKey => $path) {
            if (str_starts_with((string) $configKey, $name . '.') && $isLoaded((string) $configKey)) {
                $root = $this->setPath($root, explode('.', (string) $configKey), fn (): ConfigArray|Expr|Type|null => $this->getFileValue($path), $scope);
            }
        }

        if ($loadingKey !== null) {
            return $root;
        }

        $merges = array_filter(
            $this->configSourceResolver->getMerges(),
            static fn (array $merge): bool => explode('.', $merge['key'])[0] === $name,
        );

        foreach ($merges as $merge) {
            if ($merge['definite'] && $merge['path'] !== null) {
                $path = $merge['path'];
                $this->trackDependencies($scope, [$path => false]);

                $root = $this->setPath($root, explode('.', $merge['key']), function (ConfigArray|Expr|Type|null $current, bool $exists) use ($merge, $path, $scope): ?ConfigArray {
                    $package = $this->getFileValue($path);
                    $current = $exists ? $current : new ConfigArray;

                    return $merge['recursive']
                        ? $this->replaceRecursive($package, $current, $scope)
                        : $this->merge($package, $current, $merge['options'], $scope);
                }, $scope);
            }
        }

        // Framework code adds named entries to mergeable options at runtime, such as log channels.
        foreach ($mergeableOptions as $option) {
            $root = $this->openRegistry($root, [$name, $option], $scope);
        }

        foreach ($merges as $merge) {
            foreach ($merge['options'] as $option) {
                $root = $this->openRegistry($root, [...explode('.', $merge['key']), $option], $scope);
            }

            if (! $merge['definite']) {
                $root = $this->widenForMerge($root, explode('.', $merge['key']), $merge['options'], $merge['recursive'], $scope);
            }
        }

        foreach ($this->configSourceResolver->getWrittenKeys() as $writtenKey) {
            $segments = explode('.', $writtenKey);

            if ($segments[0] === $name) {
                $root = $this->widenForWrite($root, $segments, $scope);
            }
        }

        return $root;
    }

    /**
     * Update the value at a path as Arr::set() reaches it: a missing or non-array
     * intermediate becomes an empty array.
     *
     * @param list<string> $segments
     * @param callable(null|ConfigArray|Expr|Type, bool): (null|ConfigArray|Expr|Type) $update receives the current value and whether it exists
     * @param DependencyTracker&Scope $scope
     */
    private function setPath(ConfigArray $array, array $segments, callable $update, Scope $scope): ConfigArray
    {
        $key = $this->toArrayKey($segments[0]);
        $exists = array_key_exists($key, $array->entries);
        $current = $exists ? $array->entries[$key] : null;

        if (count($segments) === 1) {
            $value = $update($current, $exists && ! isset($array->optionalKeys[$key]));
        } else {
            $child = $exists ? $this->toConfigArray($current, $scope) : new ConfigArray;

            if ($child === null || isset($array->optionalKeys[$key])) {
                // An intermediate that may not be an array keeps its other keys only if it is one.
                $child = $this->isArray($current, $scope)->no() ? new ConfigArray : new ConfigArray(open: true);
            }

            $value = $this->setPath($child, array_slice($segments, 1), $update, $scope);
        }

        $entries = $array->entries;
        $entries[$key] = $value;
        $optionalKeys = $array->optionalKeys;
        unset($optionalKeys[$key]);

        return new ConfigArray($entries, $optionalKeys, $array->open);
    }

    /**
     * Widen a composed value for a write that may run: values at and below the key become
     * unknown, as does the first intermediate that is not known to be an array.
     *
     * @param list<string> $segments
     * @param DependencyTracker&Scope $scope
     */
    private function widenForWrite(ConfigArray $array, array $segments, Scope $scope): ConfigArray
    {
        return $this->widenPath($array, $segments, static fn (): null => null, $scope);
    }

    /**
     * Widen a composed value for a merge that may run.
     *
     * array_merge() keeps the known string keys at the key and at the provider's mergeable
     * options and adds others, so those arrays become open. It renumbers integer keys, so
     * an array with any becomes unknown. array_replace_recursive() opens every level.
     *
     * @param list<string> $segments
     * @param list<string> $options
     * @param DependencyTracker&Scope $scope
     */
    private function widenForMerge(ConfigArray $array, array $segments, array $options, bool $recursive, Scope $scope): ConfigArray
    {
        return $this->widenPath($array, $segments, function (ConfigArray|Expr|Type|null $current) use ($options, $recursive, $scope): ?ConfigArray {
            $current = $this->toConfigArray($current, $scope);

            if ($current === null) {
                return null;
            }

            if ($recursive) {
                return $this->openRecursively($current, $scope);
            }

            if (array_any(array_keys($current->entries), is_int(...))) {
                return null;
            }

            $entries = $current->entries;

            foreach ($options as $option) {
                if (array_key_exists($option, $entries)) {
                    $optionArray = $this->toConfigArray($entries[$option], $scope);

                    $entries[$option] = $optionArray === null || array_any(array_keys($optionArray->entries), is_int(...))
                        ? null
                        : new ConfigArray($optionArray->entries, $optionArray->optionalKeys, true);
                }
            }

            return new ConfigArray($entries, $current->optionalKeys, true);
        }, $scope);
    }

    /**
     * Replace the existing value at a path for an update that may run. A missing key, or
     * an intermediate not known to be an array, becomes unknown instead.
     *
     * @param list<string> $segments
     * @param callable(null|ConfigArray|Expr|Type): (null|ConfigArray|Expr|Type) $update
     * @param DependencyTracker&Scope $scope
     */
    private function widenPath(ConfigArray $array, array $segments, callable $update, Scope $scope): ConfigArray
    {
        $key = $this->toArrayKey($segments[0]);
        $entries = $array->entries;
        $optionalKeys = $array->optionalKeys;

        if (! array_key_exists($key, $entries)) {
            if ($array->open) {
                return $array;
            }

            $entries[$key] = null;
            $optionalKeys[$key] = true;

            return new ConfigArray($entries, $optionalKeys);
        }

        if (count($segments) === 1) {
            $entries[$key] = $update($entries[$key]);
        } else {
            $child = $this->toConfigArray($entries[$key], $scope);
            $entries[$key] = $child === null ? null : $this->widenPath($child, array_slice($segments, 1), $update, $scope);
        }

        return new ConfigArray($entries, $optionalKeys, $array->open);
    }

    /**
     * Open a mergeable option's array, which framework code extends with named entries.
     *
     * HandleExceptions fills a log channel whose value is falsy, so an entry that may be
     * falsy becomes unknown.
     *
     * @param list<string> $segments
     * @param DependencyTracker&Scope $scope
     */
    private function openRegistry(ConfigArray $array, array $segments, Scope $scope): ConfigArray
    {
        $key = $this->toArrayKey($segments[0]);

        if (! array_key_exists($key, $array->entries)) {
            return $array;
        }

        $entries = $array->entries;
        $current = $entries[$key];

        if (count($segments) > 1) {
            $child = $this->toConfigArray($current, $scope);
            $entries[$key] = $child === null ? $current : $this->openRegistry($child, array_slice($segments, 1), $scope);
        } elseif (($registry = $this->toConfigArray($current, $scope)) !== null) {
            $registryEntries = [];

            foreach ($registry->entries as $entryKey => $entry) {
                $registryEntries[$entryKey] = $this->isTruthy($entry, $scope) ? $entry : null;
            }

            $entries[$key] = new ConfigArray($registryEntries, $registry->optionalKeys, true);
        } else {
            $entries[$key] = $this->isArray($current, $scope)->no() ? $current : null;
        }

        return new ConfigArray($entries, $array->optionalKeys, $array->open);
    }

    /**
     * Open an array and every array below it; a value that may be an array becomes unknown.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function openRecursively(ConfigArray $array, Scope $scope): ConfigArray
    {
        $entries = [];

        foreach ($array->entries as $key => $entry) {
            $entries[$key] = match (true) {
                $entry instanceof ConfigArray => $this->openRecursively($entry, $scope),
                $this->isArray($entry, $scope)->no() => $entry,
                default => null,
            };
        }

        return new ConfigArray($entries, $array->optionalKeys, true);
    }

    /**
     * Merge two values as array_merge() does, then merge the options both set the same way.
     *
     * @param list<string> $options
     * @param DependencyTracker&Scope $scope
     */
    private function merge(ConfigArray|Expr|Type|null $lower, ConfigArray|Expr|Type|null $higher, array $options, Scope $scope): ?ConfigArray
    {
        $lowerArray = $this->toConfigArray($lower, $scope);
        $higherArray = $this->toConfigArray($higher, $scope);

        if ($lowerArray === null || $higherArray === null) {
            return null;
        }

        $open = $lowerArray->open || $higherArray->open;
        $entries = [];
        $optionalKeys = [];

        foreach ([$lowerArray, $higherArray] as $array) {
            foreach ($array->entries as $key => $entry) {
                $optional = isset($array->optionalKeys[$key]);

                if (is_int($key)) {
                    // array_merge() renumbers integer keys, which an optional or unknown entry would shift.
                    if ($optional || $open) {
                        return null;
                    }

                    $entries[] = $entry;
                } elseif ($optional && array_key_exists($key, $entries)) {
                    $entries[$key] = TypeCombinator::union($this->toType($entries[$key], $scope) ?? new MixedType, $this->toType($entry, $scope) ?? new MixedType);
                } else {
                    $entries[$key] = $entry;

                    if ($optional) {
                        $optionalKeys[$key] = true;
                    } else {
                        unset($optionalKeys[$key]);
                    }
                }
            }
        }

        // The higher array's unknown entries may replace any lower entry it does not list.
        if ($higherArray->open) {
            foreach (array_keys($lowerArray->entries) as $key) {
                if (! array_key_exists($key, $higherArray->entries)) {
                    $entries[$key] = null;
                }
            }
        }

        foreach ($options as $option) {
            if (! array_key_exists($option, $lowerArray->entries) || ! array_key_exists($option, $higherArray->entries)) {
                continue;
            }

            $lowerNull = $this->isNull($lowerArray->entries[$option], $scope);
            $higherNull = $this->isNull($higherArray->entries[$option], $scope);

            // The option is merged only when isset() holds on both sides.
            if ($lowerNull->yes() || $higherNull->yes()) {
                continue;
            }

            $entries[$option] = $lowerNull->no() && $higherNull->no()
                ? $this->merge($lowerArray->entries[$option], $higherArray->entries[$option], [], $scope)
                : null;
        }

        return new ConfigArray($entries, $optionalKeys, $open);
    }

    /**
     * Merge two values as array_replace_recursive() does.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function replaceRecursive(ConfigArray|Expr|Type|null $lower, ConfigArray|Expr|Type|null $higher, Scope $scope): ?ConfigArray
    {
        $lowerArray = $this->toConfigArray($lower, $scope);
        $higherArray = $this->toConfigArray($higher, $scope);

        if ($lowerArray === null || $higherArray === null) {
            return null;
        }

        $entries = $lowerArray->entries;
        $optionalKeys = $lowerArray->optionalKeys;

        foreach ($higherArray->entries as $key => $entry) {
            $higherOptional = isset($higherArray->optionalKeys[$key]);

            if (! array_key_exists($key, $entries)) {
                $entries[$key] = $entry;

                if ($higherOptional) {
                    $optionalKeys[$key] = true;
                }

                continue;
            }

            // Either side may be absent, so the result may be either value or both merged.
            if ($higherOptional || isset($optionalKeys[$key])) {
                $entries[$key] = null;

                if (! $higherOptional) {
                    unset($optionalKeys[$key]);
                }

                continue;
            }

            $bothArrays = $this->isArray($entries[$key], $scope)->and($this->isArray($entry, $scope));

            $entries[$key] = match (true) {
                $bothArrays->yes() => $this->replaceRecursive($entries[$key], $entry, $scope),
                $bothArrays->no() => $entry,
                default => null,
            };
        }

        // The higher array's unknown entries may replace or merge into any lower entry it does not list.
        if ($higherArray->open) {
            foreach (array_keys($lowerArray->entries) as $key) {
                if (! array_key_exists($key, $higherArray->entries)) {
                    $entries[$key] = null;
                }
            }
        }

        return new ConfigArray($entries, $optionalKeys, $lowerArray->open || $higherArray->open);
    }

    /**
     * Get a value as a config array when it is one array of known keys.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function toConfigArray(ConfigArray|Expr|Type|null $value, Scope $scope): ?ConfigArray
    {
        if ($value instanceof Expr) {
            $value = $this->getExpressionType($value, $scope);
        }

        if (! $value instanceof Type) {
            return $value;
        }

        $constantArrays = $value->getConstantArrays();

        if (count($constantArrays) !== 1 || ! $value->isConstantArray()->yes()) {
            return null;
        }

        $entries = [];
        $optionalKeys = [];
        $valueTypes = $constantArrays[0]->getValueTypes();

        foreach ($constantArrays[0]->getKeyTypes() as $index => $keyType) {
            $entries[$keyType->getValue()] = $valueTypes[$index];

            if ($constantArrays[0]->isOptionalKey($index)) {
                $optionalKeys[$keyType->getValue()] = true;
            }
        }

        return new ConfigArray($entries, $optionalKeys, $constantArrays[0]->isUnsealed()->yes());
    }

    /**
     * Get the type of a value, or null when it is unknown.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function toType(ConfigArray|Expr|Type|null $value, Scope $scope): ?Type
    {
        if ($value instanceof Expr) {
            return $this->getExpressionType($value, $scope);
        }

        if (! $value instanceof ConfigArray) {
            return $value;
        }

        $builder = ConstantArrayTypeBuilder::createEmpty();

        foreach ($value->entries as $key => $entry) {
            $builder->setOffsetValueType($this->getKeyType($key), $this->toType($entry, $scope) ?? new MixedType, isset($value->optionalKeys[$key]));
        }

        if ($value->open) {
            $builder->makeUnsealed(new UnionType([new IntegerType, new StringType]), new MixedType);
        }

        return $builder->getArray();
    }

    /**
     * Determine whether a value is an array.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function isArray(ConfigArray|Expr|Type|null $value, Scope $scope): TrinaryLogic
    {
        if ($value instanceof ConfigArray) {
            return TrinaryLogic::createYes();
        }

        return $this->toType($value, $scope)?->isArray() ?? TrinaryLogic::createMaybe();
    }

    /**
     * Determine whether a value is null.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function isNull(ConfigArray|Expr|Type|null $value, Scope $scope): TrinaryLogic
    {
        if ($value instanceof ConfigArray) {
            return TrinaryLogic::createNo();
        }

        return $this->toType($value, $scope)?->isNull() ?? TrinaryLogic::createMaybe();
    }

    /**
     * Determine whether a value is known to be truthy.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function isTruthy(ConfigArray|Expr|Type|null $value, Scope $scope): bool
    {
        if ($value instanceof ConfigArray) {
            return array_any(array_keys($value->entries), static fn (int|string $key): bool => ! isset($value->optionalKeys[$key]));
        }

        return $this->toType($value, $scope)?->toBoolean()->isTrue()->yes() ?? false;
    }

    /**
     * Get the value a config file returns: an array of its literal keys, its @return
     * type, or the expression it returns. Null when the file has no single return value.
     */
    private function getFileValue(string $path): ConfigArray|Expr|Type|null
    {
        if (! array_key_exists($path, $this->parsedConfigFiles)) {
            $this->parsedConfigFiles[$path] = $this->parseConfigFile($path);
        }

        $parsedConfigFile = $this->parsedConfigFiles[$path];

        // We could not parse the file or couldn't find the return array
        if ($parsedConfigFile === null) {
            return null;
        }

        [$return] = $parsedConfigFile;

        // Check if we have a type from the docblock. Upstream reads it only when
        // treatPhpDocTypesAsCertain is on, but that setting only relaxes always-true
        // checks; PHPStan still infers from PHPDoc types when it is off.
        $docComment = $return->getDocComment();

        if ($docComment !== null) {
            // Names in the docblock are resolved with the config file's imports
            $resolvedPhpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
                $path,
                null,
                null,
                null,
                $docComment->getText(),
            );

            $returnTag = $resolvedPhpDoc->getReturnTag();

            if ($returnTag !== null) {
                return $returnTag->getType();
            }
        }

        return $return->expr === null ? null : $this->toValue($return->expr, $path);
    }

    /**
     * Get an expression in a config file as a config array when it is an array literal
     * with literal keys, so its values are typed only when a lookup reaches them.
     */
    private function toValue(Expr $expr, string $path): ConfigArray|Expr
    {
        if ($expr instanceof Array_) {
            $entries = [];

            // PHP normalizes the keys and numbers the items without one, and the last
            // item with a key wins, as in the config file.
            foreach ($expr->items as $item) {
                if ($item->unpack || ($item->key !== null && ! $item->key instanceof String_ && ! $item->key instanceof Int_)) {
                    $entries = null;

                    break;
                }

                if ($item->key === null) {
                    $entries[] = $this->toValue($item->value, $path);
                } else {
                    $entries[$item->key->value] = $this->toValue($item->value, $path);
                }
            }

            if ($entries !== null) {
                return new ConfigArray($entries);
            }
        }

        $this->expressionFiles[spl_object_id($expr)] = $path;

        return $expr;
    }

    /**
     * Get the type of an expression a config file returns, in the scope at its return
     * statement. Null when unknown.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function getExpressionType(Expr $expr, Scope $scope): ?Type
    {
        $id = spl_object_id($expr);

        if (array_key_exists($id, $this->expressionTypes)) {
            $this->trackDependencies($scope, $this->expressionDependencies[$id]);

            return $this->expressionTypes[$id];
        }

        $inProgressLookups = $this->inProgressLookups;
        $path = $this->expressionFiles[$id];

        [$result, $dependencies] = $this->collectDependencies(fn (): ?array => $this->guard('hypervel.configExpression.' . $id, function () use ($expr, $path, $scope): ?Type {
            $returnScope = $this->getReturnScope($path);

            if ($returnScope === null) {
                return null;
            }

            $this->trackDependencies($scope, $this->configFileDependencies[$path] ?? []);

            // An undefined variable, such as one an included file assigns, is unknown rather than an error
            return TypeTraverser::map($returnScope->getType($expr), static function (Type $type, callable $traverse): Type {
                if ($type instanceof ErrorType) {
                    return new MixedType;
                }

                return $traverse($type);
            });
        }));

        $this->trackDependencies($scope, $dependencies);

        $type = $result[0] ?? null;

        if ($this->inProgressLookups === $inProgressLookups) {
            $this->expressionDependencies[$id] = $dependencies;
            $this->expressionTypes[$id] = $type;
        }

        return $type;
    }

    /**
     * Run a callback under the recursion guard, counting a refused run as an in-progress lookup.
     *
     * @template T
     *
     * @param callable(): T $callback
     * @return null|array{T}
     */
    private function guard(string $key, callable $callback): ?array
    {
        $result = RecursionGuard::run($key, static fn (): array => [$callback()]);

        if ($result === null) {
            ++$this->inProgressLookups;
        }

        return $result;
    }

    /**
     * Get the array key PHP stores a config key segment as: a decimal integer string
     * becomes an integer.
     */
    private function toArrayKey(string $segment): int|string
    {
        $integer = (int) $segment;

        return (string) $integer === $segment ? $integer : $segment;
    }

    /**
     * Get the constant type of an array key.
     */
    private function getKeyType(int|string $key): ConstantIntegerType|ConstantStringType
    {
        return is_int($key) ? new ConstantIntegerType($key) : new ConstantStringType($key);
    }

    /**
     * Get the scope at the config file's return statement, as the statements before
     * it leave it. The file is typed on its own, never in the scope of a file
     * reading it, so the variables, imports and magic constants are its own.
     *
     * Returns null when the statements before the return always terminate, or
     * while the file's statements are being processed, when they read the file
     * itself.
     */
    private function getReturnScope(string $path): ?Scope
    {
        if (array_key_exists($path, $this->returnScopes)) {
            return $this->returnScopes[$path];
        }

        $result = $this->guard('hypervel.configFile.' . $path, function () use ($path): ?Scope {
            /** @var array{Return_, list<Node\Stmt>, null|string} $parsedConfigFile a file with a value expression parsed */
            $parsedConfigFile = $this->parsedConfigFiles[$path];
            [$return, $statements, $namespace] = $parsedConfigFile;

            $scope = $this->scopeFactory->create(ScopeContext::create($path));

            if ($namespace !== null) {
                $scope = $scope->enterNamespace($namespace);
            }

            if ($statements === []) {
                $this->configFileDependencies[$path] = [];

                return $scope;
            }

            [$statementResult, $dependencies] = $this->collectDependencies(fn () => $this->nodeScopeResolver->processStmtNodes(
                $return,
                $statements,
                $scope,
                static function (): void {
                },
                StatementContext::createTopLevel(),
            ));

            $this->configFileDependencies[$path] = $dependencies;

            return $statementResult->isAlwaysTerminating() ? null : $statementResult->getScope();
        });

        if ($result === null) {
            return null;
        }

        return $this->returnScopes[$path] = $result[0];
    }

    /**
     * Declare the files and config directories on the scope and on the config files
     * and keys being typed, whose cached results declare them again later.
     *
     * @param DependencyTracker&Scope $scope
     * @param array<string, bool> $dependencies each path mapped to whether it is a directory
     */
    private function trackDependencies(Scope $scope, array $dependencies): void
    {
        foreach ($dependencies as $path => $directory) {
            if ($directory) {
                $scope->trackDirectoryDependency($path, ConfigSourceResolver::CONFIG_FILE_PATTERN);
            } else {
                $scope->trackFileDependency($path);
            }

            foreach (array_keys($this->dependencyCollectors) as $index) {
                $this->dependencyCollectors[$index][$path] = $directory;
            }
        }
    }

    /**
     * Run the callback, collecting the files and directories declared while it runs.
     *
     * @param callable(): T $callback
     *
     * @return array{T, array<string, bool>} the callback's result and the paths declared while it ran
     *
     * @template T
     */
    private function collectDependencies(callable $callback): array
    {
        $this->dependencyCollectors[] = [];

        try {
            $result = $callback();
        } finally {
            $dependencies = array_pop($this->dependencyCollectors);
        }

        return [$result, $dependencies];
    }

    /**
     * Load the application's and the framework's config files on first use, once the
     * application has booted.
     */
    private function loadConfigFiles(): void
    {
        if ($this->configFiles !== null) {
            return;
        }

        $configFiles = [];

        foreach ($this->configSourceResolver->getConfigDirectories() as $configPath) {
            if (! is_dir($configPath)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = substr($files->getSubPathname(), 0, -strlen('.php'));
                $configKey = str_replace(['/', DIRECTORY_SEPARATOR], '.', $relativePath);

                // The first config directory that has a file for a key wins
                $configFiles[$configKey] ??= $this->fileHelper->normalizePath($file->getPathname());

                $keyParts = explode('.', $configKey);

                for ($length = count($keyParts) - 1; $length > 0; --$length) {
                    $this->mergedConfigKeys[implode('.', array_slice($keyParts, 0, $length))] = true;
                }
            }
        }

        // LoadConfiguration loads the files in natural key order, so a file comes before the files nested below it.
        ksort($configFiles, SORT_NATURAL);

        $this->configFiles = $configFiles;

        if ($this->configSourceResolver->shouldMergeFrameworkConfiguration()) {
            foreach (glob(LoadConfiguration::frameworkConfigPath() . '/*.php') ?: [] as $file) {
                $this->frameworkConfigFiles[basename($file, '.php')] = $this->fileHelper->normalizePath($file);
            }
        }
    }

    /**
     * Find the value the config file returns: its only return statement
     * outside functions and classes, at the top level of the file or of its
     * namespace, so it always runs once the statements before it do.
     *
     * @return null|array{Return_, list<Node\Stmt>, null|string} the return statement, the statements before it and the file's namespace
     */
    private function parseConfigFile(string $path): ?array
    {
        try {
            $stmts = $this->parser->parseFile($path);
        } catch (ParserErrorsException) {
            return null;
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Return_> */
            public array $returns = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Return_) {
                    $this->returns[] = $node;
                }

                return null;
            }
        };

        (new NodeTraverser($visitor))->traverse($stmts);

        if (count($visitor->returns) !== 1) {
            return null;
        }

        $return = $visitor->returns[0];
        $namespace = null;
        $statements = [];

        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Namespace_) {
                $statements[] = $stmt;
                continue;
            }

            if ($namespace !== null) {
                return null;
            }

            $namespace = $stmt->name?->toString() ?? '';

            foreach ($stmt->stmts as $namespacedStmt) {
                $statements[] = $namespacedStmt;
            }
        }

        $returnIndex = array_search($return, $statements, true);

        if ($returnIndex === false) {
            return null;
        }

        return [$return, array_slice($statements, 0, $returnIndex), $namespace === '' ? null : $namespace];
    }
}
