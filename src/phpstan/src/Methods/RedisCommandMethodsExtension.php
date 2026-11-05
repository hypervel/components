<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\PHPStan\Reflection\ProxiedMethodReflection;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\Facades\Redis;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;

use function class_exists;
use function strtolower;

/**
 * Resolves a Redis command written in another casing than its documented tag.
 *
 * Redis connections lowercase a command's name before running it, so hexists(), hExists()
 * and HEXISTS() are one command, but PHPStan matches @method tags by their exact name.
 * Only RedisConnection's own command tags are matched this way; other magic names, such
 * as macros, keep their exact case. The proxy and manager reach this through their mixins.
 */
#[AutowiredService]
final class RedisCommandMethodsExtension implements MethodsClassReflectionExtension
{
    /**
     * RedisConnection's command tag names, keyed by their lowercase form.
     *
     * @var null|array<string, string>
     */
    private ?array $commandNames = null;

    /**
     * Create a new extension instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Determine whether the method is a documented Redis command in another casing.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        $commandName = $this->getCommandName($classReflection, $methodName);

        return $commandName !== null && $classReflection->hasMethod($commandName);
    }

    /**
     * Get the documented command's reflection, as the receiver resolves it.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        $method = $classReflection->getMethod($this->getCommandNames()[strtolower($methodName)], new OutOfClassScope);

        // The facade's tags are its own. A connection's tag is RedisConnection's, and PHPStan looks the
        // called name up again on a method's declaring class, whose macros may differ from the receiver's.
        return $classReflection->getName() === Redis::class
            ? $method
            : new ProxiedMethodReflection($classReflection, $method, static fn (Type $returnType): Type => $returnType);
    }

    /**
     * Get the tag name of the command that the method name calls in another casing.
     */
    private function getCommandName(ClassReflection $classReflection, string $methodName): ?string
    {
        if ($classReflection->is(RedisConnection::class)) {
            // Macroable keeps macros in static::$macros, which a subclass shares unless it declares its own.
            $macroClass = $classReflection->getNativeProperty('macros')->getDeclaringClass()->getName();
        } elseif ($classReflection->getName() === Redis::class) {
            // The facade's calls reach the pooled connections, which share RedisConnection's macros.
            $macroClass = RedisConnection::class;
        } else {
            return null;
        }

        $commandName = $this->getCommandNames()[strtolower($methodName)] ?? null;

        // __call() runs a macro registered under the exact name before it lowercases the name. Macros are
        // registered at runtime, so the registry of an owner the booted application never loaded is unavailable.
        if ($commandName === null || $commandName === $methodName || (class_exists($macroClass, false) && $macroClass::hasMacro($methodName))) {
            return null;
        }

        return $commandName;
    }

    /**
     * Get RedisConnection's command tag names, keyed by their lowercase form.
     *
     * @return array<string, string>
     */
    private function getCommandNames(): array
    {
        if ($this->commandNames === null) {
            $this->commandNames = [];

            foreach ($this->reflectionProvider->getClass(RedisConnection::class)->getMethodTags() as $name => $tag) {
                $this->commandNames[strtolower($name)] = $name;
            }
        }

        return $this->commandNames;
    }
}
