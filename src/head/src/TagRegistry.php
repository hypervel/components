<?php

declare(strict_types=1);

namespace Hypervel\Head;

use Hypervel\Head\Tags\AlternateLinks;
use Hypervel\Head\Tags\Canonical;
use Hypervel\Head\Tags\Description;
use Hypervel\Head\Tags\FeedLinks;
use Hypervel\Head\Tags\GenericLinks;
use Hypervel\Head\Tags\MetaTags;
use Hypervel\Head\Tags\OpenGraph;
use Hypervel\Head\Tags\PaginationLinks;
use Hypervel\Head\Tags\PerformanceLinks;
use Hypervel\Head\Tags\Robots;
use Hypervel\Head\Tags\Schemas;
use Hypervel\Head\Tags\TagBuilder;
use Hypervel\Head\Tags\Title;
use Hypervel\Head\Tags\Twitter;
use InvalidArgumentException;

class TagRegistry
{
    /** @var array<int, class-string<TagBuilder>> */
    protected array $extensions = [];

    /** @var null|array<string, class-string<TagBuilder>> */
    protected ?array $routeAttributeKeys = null;

    /**
     * Get the tag builders rendered for every head, in render order.
     *
     * @return array<int, class-string<TagBuilder>>
     */
    public function builders(): array
    {
        return [...$this->defaults(), ...$this->extensions];
    }

    /**
     * Register a custom tag builder.
     *
     * Boot-only. Registered builders affect every request using this shared registry.
     *
     * @param class-string $builder
     */
    public function extend(string $builder): static
    {
        if (! is_subclass_of($builder, TagBuilder::class)) {
            throw new InvalidArgumentException('Head tag extensions must extend ' . TagBuilder::class . '.');
        }

        if (in_array($builder, $this->defaults(), true)) {
            throw new InvalidArgumentException('Built-in head tag builders are already registered.');
        }

        $duplicates = array_values(array_intersect($builder::routeAttributeKeys(), array_keys($this->routeAttributeKeys())));

        if ($duplicates !== []) {
            throw new InvalidArgumentException(sprintf(
                'Head tag extensions may not register route attributes already registered by another builder: %s.',
                implode(', ', $duplicates),
            ));
        }

        if (! in_array($builder, $this->extensions, true)) {
            $this->extensions[] = $builder;
            $this->routeAttributeKeys = null;
        }

        return $this;
    }

    /**
     * Map each supported route attribute key to its tag builder.
     *
     * @return array<string, class-string<TagBuilder>>
     */
    public function routeAttributeKeys(): array
    {
        if ($this->routeAttributeKeys !== null) {
            return $this->routeAttributeKeys;
        }

        $keys = [];

        foreach ($this->builders() as $builder) {
            foreach ($builder::routeAttributeKeys() as $key) {
                $keys[$key] = $builder;
            }
        }

        return $this->routeAttributeKeys = $keys;
    }

    /**
     * Get the built-in tag builders in render order.
     *
     * @return array<int, class-string<TagBuilder>>
     */
    protected function defaults(): array
    {
        return [
            Title::class,
            Description::class,
            Canonical::class,
            Robots::class,
            OpenGraph::class,
            Twitter::class,
            PaginationLinks::class,
            AlternateLinks::class,
            FeedLinks::class,
            PerformanceLinks::class,
            MetaTags::class,
            GenericLinks::class,
            Schemas::class,
        ];
    }
}
