<?php

declare(strict_types=1);

namespace Hypervel\Head;

use Hypervel\Contracts\Container\Transient;
use Hypervel\Head\Tags\TagBuilder;

/**
 * Normalized head state: a collection of tag builders with layer semantics.
 */
class HeadData implements Transient
{
    /** @var array<class-string<TagBuilder>, TagBuilder> */
    protected array $builders = [];

    /**
     * Get all tag builders.
     *
     * @return array<class-string<TagBuilder>, TagBuilder>
     */
    public function builders(): array
    {
        return $this->builders;
    }

    /**
     * Get an existing tag builder.
     *
     * @param class-string<TagBuilder> $builder
     */
    public function get(string $builder): ?TagBuilder
    {
        return $this->builders[$builder] ?? null;
    }

    /**
     * Resolve a mutable tag builder instance, creating and storing it on first use.
     *
     * @template TBuilder of TagBuilder
     *
     * @param class-string<TBuilder> $class
     * @return TBuilder
     */
    public function builder(string $class): TagBuilder
    {
        /** @var TBuilder */
        return $this->builders[$class] ??= new $class;
    }

    /**
     * Overlay the given builder onto any existing builder of the same type.
     */
    public function overlayBuilder(TagBuilder $builder): static
    {
        $class = $builder::class;
        $this->builders[$class] = $builder->overlayOn($this->builders[$class] ?? null);

        return $this;
    }

    /**
     * Merge the given data over this data into a new instance.
     */
    public function merge(HeadData $data): self
    {
        $merged = clone $this;

        foreach ($data->builders as $builder) {
            $merged->overlayBuilder($builder);
        }

        return $merged;
    }

    /**
     * Determine whether the head data has no tags.
     */
    public function isEmpty(): bool
    {
        foreach ($this->builders as $builder) {
            if (! $builder->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark all builders as belonging to the defaults layer.
     */
    public function asDefaults(): static
    {
        $defaults = clone $this;

        foreach ($defaults->builders as $class => $builder) {
            $defaults->builders[$class] = $builder->asDefaults();
        }

        return $defaults;
    }

    /**
     * Copy the data and its builders for independent coroutine state.
     */
    public function replicate(): static
    {
        $replica = clone $this;

        foreach ($this->builders as $class => $builder) {
            $replica->builders[$class] = clone $builder;
        }

        return $replica;
    }
}
