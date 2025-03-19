<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Storage;

use Hypervel\Http\Request;

class EntryQueryOptions
{
    /**
     * The batch ID that entries should belong to.
     */
    public ?string $batchId = null;

    /**
     * The tag that must belong to retrieved entries.
     */
    public ?string $tag = null;

    /**
     * The family hash that must belong to retrieved entries.
     */
    public ?string $familyHash = null;

    /**
     * The ID that all retrieved entries should be less than.
     */
    public mixed $beforeSequence = null;

    /**
     * The list of UUIDs of entries tor retrieve.
     */
    public mixed $uuids = null;

    /**
     * The number of entries to retrieve.
     */
    public int $limit = 50;

    /**
     * Create new entry query options from the incoming request.
     */
    public static function fromRequest(Request $request): static
    {
        return (new static())
            ->batchId($request->batch_id) // @phpstan-ignore-line
            ->uuids($request->uuids) // @phpstan-ignore-line
            ->beforeSequence($request->before) // @phpstan-ignore-line
            ->tag($request->tag) // @phpstan-ignore-line
            ->familyHash($request->family_hash) // @phpstan-ignore-line
            ->limit($request->take ?? 50);
    }

    /**
     * Create new entry query options for the given batch ID.
     */
    public static function forBatchId(?string $batchId): static
    {
        return (new static())->batchId($batchId);
    }

    /**
     * Set the batch ID for the query.
     */
    public function batchId(?string $batchId): static
    {
        $this->batchId = $batchId;

        return $this;
    }

    /**
     * Set the list of UUIDs of entries tor retrieve.
     */
    public function uuids(?array $uuids): static
    {
        $this->uuids = $uuids;

        return $this;
    }

    /**
     * Set the ID that all retrieved entries should be less than.
     */
    public function beforeSequence(mixed $id): static
    {
        $this->beforeSequence = $id;

        return $this;
    }

    /**
     * Set the tag that must belong to retrieved entries.
     */
    public function tag(?string $tag): static
    {
        $this->tag = $tag;

        return $this;
    }

    /**
     * Set the family hash that must belong to retrieved entries.
     */
    public function familyHash(?string $familyHash): static
    {
        $this->familyHash = $familyHash;

        return $this;
    }

    /**
     * Set the number of entries that should be retrieved.
     */
    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }
}
