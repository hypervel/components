<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Translation\Contracts\Loader;

class ArrayLoader implements Loader
{
    /**
     * All of the translation messages.
     */
    protected array $messages = [];

    /**
     * Load the messages for the given locale.
     */
    public function load(string $locale, string $group, ?string $namespace = null): array
    {
        $namespace = $namespace ?: '*';

        return $this->messages[$namespace][$locale][$group] ?? [];
    }

    /**
     * Add a new namespace to the loader.
     */
    public function addNamespace(string $namespace, string $hint): void
    {
    }

    /**
     * Add a new JSON path to the loader.
     */
    public function addJsonPath(string $path): void
    {
    }

    /**
     * Add a new path to the loader.
     */
    public function addPath(string $path): void
    {
    }

    /**
     * Add messages to the loader.
     */
    public function addMessages(string $locale, string $group, array $messages, ?string $namespace = null): static
    {
        $namespace = $namespace ?: '*';

        $this->messages[$namespace][$locale][$group] = $messages;

        return $this;
    }

    /**
     * Get an array of all the registered namespaces.
     */
    public function namespaces(): array
    {
        return [];
    }
}
