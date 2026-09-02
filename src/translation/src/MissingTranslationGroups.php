<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Context\ReplicableContext;

/**
 * Keep empty translation-group results isolated to one execution.
 *
 * Mutable context avoids replacing a growing context array for every miss,
 * while replication gives child coroutines an independent snapshot.
 *
 * @internal
 */
final class MissingTranslationGroups implements ReplicableContext
{
    /**
     * The translation groups missing from the current execution.
     *
     * @var array<string, array<string, array<string, true>>>
     */
    private array $groups = [];

    /**
     * Determine if a translation group is missing.
     */
    public function has(string $namespace, string $group, string $locale): bool
    {
        return isset($this->groups[$namespace][$group][$locale]);
    }

    /**
     * Mark a translation group as missing.
     */
    public function mark(string $namespace, string $group, string $locale): void
    {
        $this->groups[$namespace][$group][$locale] = true;
    }

    /**
     * Forget a missing translation group.
     */
    public function forget(string $namespace, string $group, string $locale): void
    {
        unset($this->groups[$namespace][$group][$locale]);
    }

    /**
     * Copy the missing translation groups into an independent snapshot.
     */
    public function replicate(): static
    {
        $copy = new self;
        $copy->groups = $this->groups;

        return $copy;
    }
}
