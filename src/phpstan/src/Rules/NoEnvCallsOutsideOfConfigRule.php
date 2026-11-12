<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Support\ConfigSourceResolver;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\Env;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function in_array;
use function sprintf;
use function strtolower;

/**
 * Catches environment reads outside of the config directory.
 *
 * Upstream matches only env(), which Env::get() and the other readers bypass, so every
 * env helper and Env reader is matched here. Raw repository, getenv() and superglobal
 * access are deliberate low-level reads and are not.
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
     * Create a new env calls rule instance.
     */
    public function __construct(
        private ConfigSourceResolver $configSourceResolver,
        private ReflectionProvider $reflectionProvider,
        private RuntimeInputs $runtimeInputs,
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
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $reader = $this->getReaderName($node, $scope);

        if ($reader === null) {
            return [];
        }

        // The booted application decides the config directories and package config files.
        $this->runtimeInputs->track($scope);

        if ($this->configSourceResolver->isConfigFile($scope->getFile())) {
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

        // An override's body is unknown, so only Env's own readers count.
        if (! $class->hasNativeMethod($call->name->toString())
            || $class->getNativeMethod($call->name->toString())->getDeclaringClass()->getName() !== Env::class) {
            return null;
        }

        return $class->getName() . '::' . $class->getNativeMethod($call->name->toString())->getName();
    }
}
