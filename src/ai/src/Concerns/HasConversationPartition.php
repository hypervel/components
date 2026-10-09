<?php

declare(strict_types=1);

namespace Hypervel\Ai\Concerns;

use Hypervel\Ai\Scopes\ConversationPartitionScope;
use Hypervel\Ai\Support\ConversationPartition;
use Hypervel\Database\Eloquent\Builder;
use LogicException;

trait HasConversationPartition
{
    /**
     * Boot the conversation partition global scope.
     */
    public static function bootHasConversationPartition(): void
    {
        static::addGlobalScope(new ConversationPartitionScope);
    }

    /**
     * Populate the conversation partition before model creation events run.
     */
    protected function performInsert(Builder $query): bool
    {
        $this->setConversationPartitionForInsert();

        return parent::performInsert($query);
    }

    /**
     * Populate the conversation partition before an insert that ignores unique conflicts.
     */
    protected function performInsertOrIgnore(Builder $query, array|string|null $uniqueBy): bool
    {
        $this->setConversationPartitionForInsert();

        return parent::performInsertOrIgnore($query, $uniqueBy);
    }

    /**
     * Stamp the current partition before either model insertion path runs its listeners.
     */
    protected function setConversationPartitionForInsert(): void
    {
        $partition = ConversationPartition::current();

        if ($partition) {
            if (array_key_exists($partition->column, $this->attributes)) {
                $this->ensureModelMatchesConversationPartition($partition);
            }

            $this->setAttribute($partition->column, $partition->value);
        }
    }

    /**
     * Get the authoritative attributes for a partitioned insert.
     */
    protected function getAttributesForInsert(): array
    {
        $partition = ConversationPartition::current();

        if ($partition) {
            $this->ensureModelMatchesConversationPartition($partition);

            // Creating listeners and mutators run after the initial assignment.
            $this->attributes[$partition->column] = $partition->value;
        }

        return parent::getAttributesForInsert();
    }

    /**
     * Add the conversation partition to an existing model write query.
     */
    protected function setKeysForSaveQuery(Builder $query): Builder
    {
        $query = parent::setKeysForSaveQuery($query);
        $partition = ConversationPartition::current();

        if (! $partition) {
            return $query;
        }

        $this->ensureModelMatchesConversationPartition($partition);

        if (! $partition->matches($this->getAttributes()[$partition->column] ?? null)) {
            throw new LogicException('The conversation partition [' . $partition->column . '] cannot be changed on an existing model.');
        }

        return $query->where(
            $this->qualifyColumn($partition->column),
            $partition->value,
        );
    }

    /**
     * Add the conversation partition to an existing model select query.
     */
    protected function setKeysForSelectQuery(Builder $query): Builder
    {
        $query = parent::setKeysForSelectQuery($query);
        $partition = ConversationPartition::current();

        if (! $partition) {
            return $query;
        }

        $this->ensureModelMatchesConversationPartition($partition);

        return $query->where(
            $this->qualifyColumn($partition->column),
            $partition->value,
        );
    }

    /**
     * Create a partitioned query for queued model restoration.
     */
    public function newQueryForRestoration(array|int|string $ids): Builder
    {
        $query = parent::newQueryForRestoration($ids);
        $partition = ConversationPartition::current();

        return $partition
            ? $query->where($this->qualifyColumn($partition->column), $partition->value)
            : $query;
    }

    /**
     * Check a stored relationship owner without resolving a partition for eager-load prototypes.
     */
    protected function ensureConversationRelationshipPartition(): void
    {
        if ($this->exists && ($partition = ConversationPartition::current()) !== null) {
            $this->ensureModelMatchesConversationPartition($partition);
        }
    }

    /**
     * Reject a model that does not belong to the current conversation partition.
     */
    protected function ensureModelMatchesConversationPartition(ConversationPartition $partition): void
    {
        $attributes = $this->exists ? $this->getRawOriginal() : $this->getAttributes();

        if (! array_key_exists($partition->column, $attributes) && $this->wasRecentlyCreated) {
            // Post-insert listeners run before Eloquent synchronizes original attributes.
            $attributes = $this->getAttributes();
        }

        if (! $partition->matches($attributes[$partition->column] ?? null)) {
            throw new LogicException('Model [' . static::class . '] does not belong to the current conversation partition [' . $partition->column . '].');
        }
    }
}
