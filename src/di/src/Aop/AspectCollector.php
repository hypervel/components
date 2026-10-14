<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Hypervel\Di\Exceptions\InvalidDefinitionException;
use Hypervel\Support\ClassMetadataCache;
use ReflectionProperty;

/**
 * Worker-wide aspect rules used to generate proxies and compile dispatch chains.
 */
class AspectCollector
{
    /**
     * Aspect rules indexed by aspect class name.
     *
     * @var array<string, array{priority: int, classes: array<int, string>}>
     */
    protected static array $aspectRules = [];

    protected static bool $sealed = false;

    /**
     * Register an aspect using its public default targeting rules and priority.
     *
     * Boot or tests only. Register before application proxy generation, or explicitly
     * reopen registration for focused tests. Loaded classes cannot be rewritten.
     *
     * @param class-string $aspect
     */
    public static function register(string $aspect): void
    {
        $reflectionClass = ClassMetadataCache::reflectClass($aspect);
        $properties = $reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC);

        $classes = [];
        $priority = null;

        foreach ($properties as $property) {
            if ($property->getName() === 'classes') {
                $classes = $property->getDefaultValue();
            } elseif ($property->getName() === 'priority') {
                $priority = $property->getDefaultValue();
            }
        }

        static::setAround($aspect, $classes, $priority);
    }

    /**
     * Register an aspect with its class targeting rules.
     *
     * Boot or tests only. Aspect rules persist in a static property used by the
     * compile-time proxy generator; runtime use cannot retroactively apply
     * aspects to already-loaded classes.
     */
    public static function setAround(string $aspect, array $classes, ?int $priority = null): void
    {
        if (static::$sealed) {
            throw new InvalidDefinitionException(
                "Cannot register aspect [{$aspect}] after proxy generation. "
                . 'Register aspects in a service provider\'s register() method, before boot().'
            );
        }

        $priority ??= 0;

        // Repeated boots re-register aspects. Duplicate rules would change the shared
        // fingerprint, regenerating every proxy, and wildcards would rescan the class map.
        $existing = static::$aspectRules[$aspect]['classes'] ?? [];
        $classes = array_values(array_unique(array_merge($existing, $classes)));

        static::$aspectRules[$aspect] = [
            'priority' => $priority,
            'classes' => $classes,
        ];

        AspectManager::clear();
    }

    /**
     * Close aspect registration after application proxy generation.
     *
     * Boot-only. Later registrations in this worker are rejected.
     */
    public static function seal(): void
    {
        static::$sealed = true;
    }

    /**
     * Reopen aspect registration while retaining the registered rules.
     *
     * Boot or tests only. This permits changes to worker-wide rules for a new
     * application bootstrap or focused tests; it does not rewrite loaded classes.
     */
    public static function unseal(): void
    {
        static::$sealed = false;
    }

    /**
     * Determine if any aspects have been registered.
     */
    public static function hasAspects(): bool
    {
        return static::$aspectRules !== [];
    }

    /**
     * Remove a specific aspect from the registry.
     *
     * Tests only. Mutates the worker-wide aspect registry; runtime use cannot
     * retroactively update already-generated proxies.
     */
    public static function forgetAspect(string $aspect): void
    {
        unset(static::$aspectRules[$aspect]);

        AspectManager::clear();
    }

    /**
     * Get the class-targeting rules for every registered aspect.
     *
     * @return array<string, array<int, string>>
     */
    public static function getClassRules(): array
    {
        $classRules = [];

        foreach (static::$aspectRules as $aspect => $rule) {
            $classRules[$aspect] = $rule['classes'];
        }

        return $classRules;
    }

    /**
     * Get the rules for a specific aspect.
     *
     * @return array{priority: int, classes: array<int, string>}|array{}
     */
    public static function getRule(string $aspect): array
    {
        return static::$aspectRules[$aspect] ?? [];
    }

    /**
     * Get the priority for a specific aspect.
     */
    public static function getPriority(string $aspect): int
    {
        return static::$aspectRules[$aspect]['priority'] ?? 0;
    }

    /**
     * Get all aspect rules.
     *
     * @return array<string, array{priority: int, classes: array<int, string>}>
     */
    public static function getRules(): array
    {
        return static::$aspectRules;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$aspectRules = [];
        static::unseal();
        AspectManager::clear();
    }
}
