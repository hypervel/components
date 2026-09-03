<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Partials;

use Closure;

trait ForwardsToPartialsDefinition
{
    /**
     * Get the partial definition store.
     */
    abstract protected function getPartialsDefinition(): PartialsDefinition;

    /**
     * Include properties for the next transformation.
     */
    public function include(string ...$includes): static
    {
        foreach ($includes as $include) {
            $this->getPartialsDefinition()->add('include', $include);
        }

        return $this;
    }

    /**
     * Include properties for every transformation.
     */
    public function includePermanently(string ...$includes): static
    {
        foreach ($includes as $include) {
            $this->getPartialsDefinition()->add('include', $include, permanent: true);
        }

        return $this;
    }

    /**
     * Exclude lazy properties for the next transformation.
     */
    public function exclude(string ...$excludes): static
    {
        foreach ($excludes as $exclude) {
            $this->getPartialsDefinition()->add('exclude', $exclude);
        }

        return $this;
    }

    /**
     * Exclude lazy properties for every transformation.
     */
    public function excludePermanently(string ...$excludes): static
    {
        foreach ($excludes as $exclude) {
            $this->getPartialsDefinition()->add('exclude', $exclude, permanent: true);
        }

        return $this;
    }

    /**
     * Keep only properties for the next transformation.
     */
    public function only(string ...$only): static
    {
        foreach ($only as $onlyDefinition) {
            $this->getPartialsDefinition()->add('only', $onlyDefinition);
        }

        return $this;
    }

    /**
     * Keep only properties for every transformation.
     */
    public function onlyPermanently(string ...$only): static
    {
        foreach ($only as $onlyDefinition) {
            $this->getPartialsDefinition()->add('only', $onlyDefinition, permanent: true);
        }

        return $this;
    }

    /**
     * Exclude properties for the next transformation.
     */
    public function except(string ...$except): static
    {
        foreach ($except as $exceptDefinition) {
            $this->getPartialsDefinition()->add('except', $exceptDefinition);
        }

        return $this;
    }

    /**
     * Exclude properties for every transformation.
     */
    public function exceptPermanently(string ...$except): static
    {
        foreach ($except as $exceptDefinition) {
            $this->getPartialsDefinition()->add('except', $exceptDefinition, permanent: true);
        }

        return $this;
    }

    /**
     * Include a property when the condition passes.
     */
    public function includeWhen(string $include, bool|Closure $condition, bool $permanent = false): static
    {
        if ($condition instanceof Closure || $condition) {
            $this->getPartialsDefinition()->add(
                'include',
                $include,
                $permanent,
                $condition instanceof Closure ? $condition : null,
            );
        }

        return $this;
    }

    /**
     * Exclude a lazy property when the condition passes.
     */
    public function excludeWhen(string $exclude, bool|Closure $condition, bool $permanent = false): static
    {
        if ($condition instanceof Closure || $condition) {
            $this->getPartialsDefinition()->add(
                'exclude',
                $exclude,
                $permanent,
                $condition instanceof Closure ? $condition : null,
            );
        }

        return $this;
    }

    /**
     * Keep only a property when the condition passes.
     */
    public function onlyWhen(string $only, bool|Closure $condition, bool $permanent = false): static
    {
        if ($condition instanceof Closure || $condition) {
            $this->getPartialsDefinition()->add(
                'only',
                $only,
                $permanent,
                $condition instanceof Closure ? $condition : null,
            );
        }

        return $this;
    }

    /**
     * Exclude a property when the condition passes.
     */
    public function exceptWhen(string $except, bool|Closure $condition, bool $permanent = false): static
    {
        if ($condition instanceof Closure || $condition) {
            $this->getPartialsDefinition()->add(
                'except',
                $except,
                $permanent,
                $condition instanceof Closure ? $condition : null,
            );
        }

        return $this;
    }
}
