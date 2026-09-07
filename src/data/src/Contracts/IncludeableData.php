<?php

declare(strict_types=1);

namespace Hypervel\Data\Contracts;

use Closure;
use Hypervel\Data\Support\Partials\PartialsDefinition;

interface IncludeableData
{
    /**
     * Include the given properties.
     */
    public function include(string ...$includes): object;

    /**
     * Include the given properties for every transformation.
     */
    public function includePermanently(string ...$includes): object;

    /**
     * Exclude the given properties.
     */
    public function exclude(string ...$excludes): object;

    /**
     * Exclude the given lazy properties for every transformation.
     */
    public function excludePermanently(string ...$excludes): object;

    /**
     * Include only the given properties.
     */
    public function only(string ...$only): object;

    /**
     * Include only the given properties for every transformation.
     */
    public function onlyPermanently(string ...$only): object;

    /**
     * Include every property except the given properties.
     */
    public function except(string ...$except): object;

    /**
     * Exclude the given properties for every transformation.
     */
    public function exceptPermanently(string ...$except): object;

    /**
     * Include a property when the condition passes.
     */
    public function includeWhen(string $include, bool|Closure $condition, bool $permanent = false): object;

    /**
     * Exclude a property when the condition passes.
     */
    public function excludeWhen(string $exclude, bool|Closure $condition, bool $permanent = false): object;

    /**
     * Include only a property when the condition passes.
     */
    public function onlyWhen(string $only, bool|Closure $condition, bool $permanent = false): object;

    /**
     * Exclude a property when the condition passes.
     */
    public function exceptWhen(string $except, bool|Closure $condition, bool $permanent = false): object;

    /**
     * Determine whether this object has partial definitions.
     */
    public function hasPartialsDefinition(): bool;

    /**
     * Get the current partial definitions.
     */
    public function getPartialsDefinition(): PartialsDefinition;
}
