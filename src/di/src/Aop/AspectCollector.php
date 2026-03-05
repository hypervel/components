<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Hypervel\Support\Arr;

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
     * Container indexed by type ('classes') and aspect class name.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    protected static array $container = [];

    /**
     * Aspect rules indexed by aspect class name.
     *
     * @var array<string, array{priority: int, classes: array<int, string>}>
     */
    protected static array $aspectRules = [];

    /**
     * Register an aspect with its class targeting rules.
     */
    public static function setAround(string $aspect, array $classes, ?int $priority = null): void
    {
        $priority ??= 0;

        $existing = static::get('classes.' . $aspect, []);
        static::set('classes.' . $aspect, array_merge($existing, $classes));

        if (isset(static::$aspectRules[$aspect])) {
            static::$aspectRules[$aspect] = [
                'priority' => $priority,
                'classes' => array_merge(static::$aspectRules[$aspect]['classes'], $classes),
            ];
        } else {
            static::$aspectRules[$aspect] = [
                'priority' => $priority,
                'classes' => $classes,
            ];
        }
    }

    /**
     * Determine if any aspects have been registered.
     */
    public static function hasAspects(): bool
    {
        return static::$aspectRules !== [];
    }

    /**
     * Retrieve metadata by dot-notated key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Arr::get(static::$container, $key) ?? $default;
    }

    /**
     * Set metadata by dot-notated key.
     */
    public static function set(string $key, mixed $value): void
    {
        Arr::set(static::$container, $key, $value);
    }

    /**
     * Clear a specific aspect or all aspects.
     */
    public static function clear(?string $key = null): void
    {
        if ($key !== null) {
            unset(static::$container['classes'][$key], static::$aspectRules[$key]);
        } else {
            static::$container = [];
            static::$aspectRules = [];
        }
    }

    /**
     * Get the rules for a specific aspect.
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
     */
    public static function getRules(): array
    {
        return static::$aspectRules;
    }

    /**
     * Return all metadata.
     */
    public static function list(): array
    {
        return static::$container;
    }
}
