<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

/**
 * Static registry of aspect class rules and priorities.
 *
 * Tracks which aspect classes target which classes/methods,
 * and their execution priority. Used by ProxyManager to determine
 * which classes need proxy generation and by ProxyTrait at runtime
 * to resolve the aspect pipeline for each method call.
 */
class AspectCollector
{
    /**
     * Aspect rules indexed by aspect class name.
     *
     * @var array<string, array{priority: int, classes: array<int, string>}>
     */
    protected static array $aspectRules = [];

    /**
     * Register an aspect with its class targeting rules.
     *
     * Boot-only. Aspect rules persist in a static property used by the
     * compile-time proxy generator; runtime use cannot retroactively apply
     * aspects to already-loaded classes.
     */
    public static function setAround(string $aspect, array $classes, ?int $priority = null): void
    {
        $priority ??= 0;

        // Merge idempotently: a provider re-registering the same aspect on a repeated
        // boot in the same worker must not append duplicate class rules; the proxy
        // generator scans every rule against the whole class map, so duplicates make
        // each boot slower than the last.
        $existing = static::$aspectRules[$aspect]['classes'] ?? [];
        $classes = array_values(array_unique(array_merge($existing, $classes)));

        static::$aspectRules[$aspect] = [
            'priority' => $priority,
            'classes' => $classes,
        ];
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
    }
}
