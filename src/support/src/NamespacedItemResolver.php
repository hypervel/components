<?php

declare(strict_types=1);

namespace Hypervel\Support;

class NamespacedItemResolver
{
    /**
     * The explicitly seeded parsed items.
     */
    protected array $parsed = [];

    /**
     * Parse a key into namespace, group, and item.
     */
    public function parseKey(string $key): array
    {
        if (isset($this->parsed[$key])) {
            return $this->parsed[$key];
        }

        return ! str_contains($key, '::')
            ? $this->parseBasicSegments(explode('.', $key))
            : $this->parseNamespacedSegments($key);
    }

    /**
     * Parse an array of basic segments.
     */
    protected function parseBasicSegments(array $segments): array
    {
        // The first segment in a basic array will always be the group, so we can go
        // ahead and grab that segment. If there is only one total segment we are
        // just pulling an entire group out of the array and not a single item.
        $group = $segments[0];

        // If there is more than one segment in this group, it means we are pulling
        // a specific item out of a group and will need to return this item name
        // as well as the group so we know which item to pull from the arrays.
        $item = count($segments) === 1
            ? null
            : implode('.', array_slice($segments, 1));

        return [null, $group, $item];
    }

    /**
     * Parse an array of namespaced segments.
     */
    protected function parseNamespacedSegments(string $key): array
    {
        [$namespace, $item] = explode('::', $key);

        // First we'll just explode the first segment to get the namespace and group
        // since the item should be in the remaining segments. Once we have these
        // two pieces of data we can proceed with parsing out the item's value.
        $itemSegments = explode('.', $item);

        $groupAndItem = array_slice(
            $this->parseBasicSegments($itemSegments),
            1
        );

        return array_merge([$namespace], $groupAndItem);
    }

    /**
     * Set the parsed value of a key.
     *
     * Entries remain cached for this resolver instance's lifetime, which is the
     * worker lifetime when called on the shared translator.
     */
    public function setParsedKey(string $key, array $parsed): void
    {
        $this->parsed[$key] = $parsed;
    }

    /**
     * Flush the cache of parsed keys.
     */
    public function flushParsedKeys(): void
    {
        $this->parsed = [];
    }
}
