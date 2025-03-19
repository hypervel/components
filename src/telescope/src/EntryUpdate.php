<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

class EntryUpdate
{
    /**
     * The changes to be applied on the tags.
     */
    public array $tagsChanges = ['removed' => [], 'added' => []];

    /**
     * Create a new incoming entry instance.
     *
     * @param string $uuid the entry's UUID
     * @param string $type the entry's type
     * @param array $changes the properties that should be updated on the entry
     */
    public function __construct(
        public string $uuid,
        public string $type,
        public array $changes
    ) {
    }

    /**
     * Create a new entry update instance.
     */
    public static function make(mixed ...$arguments): static
    {
        return new static(...$arguments);
    }

    /**
     * Set the properties that should be updated.
     */
    public function change(array $changes): static
    {
        $this->changes = array_merge($this->changes, $changes);

        return $this;
    }

    /**
     * Add tags to the entry.
     */
    public function addTags(array $tags): static
    {
        $this->tagsChanges['added'] = array_unique(
            array_merge($this->tagsChanges['added'], $tags)
        );

        return $this;
    }

    /**
     * Remove tags from the entry.
     */
    public function removeTags(array $tags): static
    {
        $this->tagsChanges['removed'] = array_unique(
            array_merge($this->tagsChanges['removed'], $tags)
        );

        return $this;
    }
}
