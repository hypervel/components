<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\PHPStan\Support\ConfigSourceResolver;
use Hypervel\Support\Env;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\File\FileHelper;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function in_array;
use function sprintf;
use function str_starts_with;
use function strtolower;

use const DIRECTORY_SEPARATOR;

/**
 * Catches environment reads outside of the config directory.
 *
 * Upstream matches only env(), which Env::get() and the other readers bypass, so every
 * env helper and Env reader is matched here. Raw repository, getenv() and superglobal
 * access are deliberate low-level reads and are not.
 *
 * Besides the application's config directories, the framework's config and config:publish
 * stub directories and package config files are config files: those providers merge, and
 * those they publish into the config directory.
 *
 * @implements Rule<CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%noEnvCallsOutsideOfConfig%')]
class NoEnvCallsOutsideOfConfigRule implements Rule
{
    /**
     * The global helpers that read the environment, including app_id(), which supplies config defaults.
     */
    private const array FUNCTIONS = ['env', 'env_array', 'env_or_fail', 'app_id'];

    /**
     * The Env methods that read a variable, lowercased.
     */
    private const array METHODS = ['get', 'getarray', 'getorfail'];

    /**
     * The single reflection files are PHPStan's normalized `--tmp-file` and
     * `--instead-of` paths, which are set in editor mode.
     */
    public function __construct(
        private ConfigSourceResolver $configSourceResolver,
        private FileHelper $fileHelper,
        private ReflectionProvider $reflectionProvider,
        #[AutowiredParameter]
        private ?string $singleReflectionFile = null,
        #[AutowiredParameter]
        private ?string $singleReflectionInsteadOfFile = null,
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
     * Report environment reads outside of config files.
     *
     * @param CallLike $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $reader = $this->getReaderName($node, $scope);

        if ($reader === null || ! $this->isCalledOutsideOfConfig($node, $scope)) {
            return [];
        }

        // Upstream says env() returns null once the config is cached; it still reads the
        // process environment, but the .env file is no longer loaded.
        return [
            RuleErrorBuilder::message(sprintf("Called '%s' outside of the config directory. The .env file is not loaded when the configuration is cached, so use 'config' instead.", $reader))
                ->identifier('hypervel.noEnvCallsOutsideOfConfig')
                ->line($node->getStartLine())
                ->file($scope->getFile(), $scope->getFileDescription())
                ->build(),
        ];
    }

    /**
     * Get the name of the env helper or Env reader the call makes, or null when it makes neither.
     */
    private function getReaderName(CallLike $call, Scope $scope): ?string
    {
        if ($call instanceof FuncCall && $call->name instanceof Name) {
            $function = strtolower($this->reflectionProvider->resolveFunctionName($call->name, $scope) ?? '');

            return in_array($function, self::FUNCTIONS, true) ? $function : null;
        }

        if (! $call instanceof StaticCall
            || ! $call->class instanceof Name
            || ! $call->name instanceof Identifier
            || ! in_array($call->name->toLowerString(), self::METHODS, true)) {
            return null;
        }

        $className = $scope->resolveName($call->class);

        if (! $this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($className);

        if (! $class->is(Env::class)) {
            return null;
        }

        return $class->getName() . '::' . $class->getNativeMethod($call->name->toString())->getName();
    }

    /**
     * Determine whether the call is outside of every config directory and package config file.
     */
    protected function isCalledOutsideOfConfig(CallLike $call, Scope $scope): bool
    {
        $file = $this->fileHelper->normalizePath($this->resolveAnalysedFile($scope->getFile()));

        $configDirectories = [
            ...$this->configSourceResolver->getConfigDirectories(),
            $this->fileHelper->normalizePath(LoadConfiguration::frameworkConfigPath()),
            $this->fileHelper->normalizePath(LoadConfiguration::frameworkConfigStubPath()),
        ];

        foreach ($configDirectories as $configDirectory) {
            // Upstream's bare prefix match lets /app/config permit /app/config-sibling.
            if (str_starts_with($file, $configDirectory . DIRECTORY_SEPARATOR)) {
                return false;
            }
        }

        return ! $this->configSourceResolver->isPackageConfigFile($file);
    }

    /**
     * In editor mode PHPStan analyses the temporary file, so map it back to
     * the file it stands in for.
     */
    private function resolveAnalysedFile(string $scopeFile): string
    {
        if ($this->singleReflectionFile === null) {
            return $scopeFile;
        }

        if ($this->fileHelper->normalizePath($scopeFile) !== $this->singleReflectionFile) {
            return $scopeFile;
        }

        return $this->singleReflectionInsteadOfFile ?? $scopeFile;
    }
}
