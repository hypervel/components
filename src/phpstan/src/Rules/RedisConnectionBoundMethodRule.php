<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Closure;
use Hypervel\Redis\RedisManager;
use Hypervel\Redis\RedisProxy;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_fill_keys;
use function sprintf;
use function strtolower;

/**
 * Reports connection-bound Redis methods, such as client() or auth(), called through the
 * pooled proxy or the manager.
 *
 * The proxy runs each command on a pooled connection it releases afterwards, so its
 * __call() rejects methods that act on one connection. Its RedisConnection mixin still
 * documents them, which would let these always-failing calls pass analysis.
 *
 * @implements Rule<MethodCall>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkRedisConnectionBoundMethods%')]
class RedisConnectionBoundMethodRule implements Rule
{
    /**
     * The lowercase method names the proxy rejects, as keys.
     *
     * @var null|array<string, true>
     */
    private ?array $connectionBoundMethods = null;

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * Report connection-bound method calls that reach the proxy's __call().
     *
     * @param MethodCall $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->toString();

        if (! isset($this->getConnectionBoundMethods()[strtolower($methodName)])) {
            return [];
        }

        $classReflections = $scope->getType($node->var)->getObjectClassReflections();

        if ($classReflections === []) {
            return [];
        }

        foreach ($classReflections as $classReflection) {
            if (! $this->reachesProxyCall($classReflection, $methodName, $scope)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Redis connection method %s() must be called on a held Redis connection. Use Redis::withConnection() or Redis::withPinnedConnection().',
                $methodName,
            ))->identifier('hypervel.redisConnectionBoundMethod')->build(),
        ];
    }

    /**
     * Determine whether calling the method on the receiver runs the proxy's stock __call().
     */
    private function reachesProxyCall(ClassReflection $classReflection, string $methodName, Scope $scope): bool
    {
        // A native method the caller can reach runs instead, such as the proxy's own getConnection() calls.
        if ($classReflection->hasNativeMethod($methodName) && $scope->canCallMethod($classReflection->getNativeMethod($methodName))) {
            return false;
        }

        if ($classReflection->is(RedisProxy::class)) {
            return $this->declares($classReflection, '__call', RedisProxy::class);
        }

        // The manager forwards to the proxy its connection() returns.
        return $classReflection->is(RedisManager::class)
            && $this->declares($classReflection, '__call', RedisManager::class)
            && $this->declares($classReflection, 'connection', RedisManager::class);
    }

    /**
     * Determine whether the receiver's method is the given class's own, not an override.
     */
    private function declares(ClassReflection $classReflection, string $methodName, string $className): bool
    {
        return $classReflection->getNativeMethod($methodName)->getDeclaringClass()->getName() === $className;
    }

    /**
     * Get the lowercase method names the proxy rejects, read from the proxy itself.
     *
     * @return array<string, true>
     */
    private function getConnectionBoundMethods(): array
    {
        // The constant is private to the proxy, so it is read in the proxy's scope.
        return $this->connectionBoundMethods ??= array_fill_keys(
            Closure::bind(static fn (): array => self::CONNECTION_BOUND_METHODS, null, RedisProxy::class)(),
            true,
        );
    }
}
