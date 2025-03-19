<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Exception;
use Iterator;
use RuntimeException;

/**
 * @implments Iterator<string, string[]>
 */
class RedisTaggedCacheChunkedEntries implements Iterator
{
    protected int $tagIndex = 0;

    protected string $scanCursor = '0';

    protected string $nextScanCursor = '0';

    protected bool $errorOccurredWhenSanning = false;

    public function __construct(
        protected RedisStore $store,
        protected array $tagIds,
        protected int $chunkSize = 1000
    ) {
    }

    /**
     * @return string[]
     */
    public function current(): array
    {
        try {
            [$nextCursor, $entries] = $this->scan($this->tagId(), $this->scanCursor);

            $this->nextScanCursor = $nextCursor;

            return array_keys($entries);
        } catch (Exception) {
            $this->errorOccurredWhenSanning = true;

            return [];
        }
    }

    public function next(): void
    {
        if ($this->errorOccurredWhenSanning) {
            $this->errorOccurredWhenSanning = false;
            $this->nextScanCursor = '0';
        }

        if ($this->nextScanCursor === $this->scanCursor) {
            $this->nextScanCursor = '0';
        }

        $this->scanCursor = $this->nextScanCursor;

        if ($this->scanCursor === '0') {
            ++$this->tagIndex;
        }
    }

    public function key(): string
    {
        return "{$this->tagId()}:{$this->scanCursor}";
    }

    public function valid(): bool
    {
        return $this->tagId() !== null;
    }

    public function rewind(): void
    {
        $this->scanCursor = '0';
        $this->tagIndex = 0;
        $this->errorOccurredWhenSanning = false;
    }

    protected function tagId(): ?string
    {
        return $this->tagIds[$this->tagIndex] ?? null;
    }

    protected function scan(string $tagId, string $cursor): array
    {
        $entries = $this->store->connection()->zscan(
            $this->store->getPrefix() . $tagId,
            $cursor,
            '*',
            $this->chunkSize
        );

        if (! is_array($entries)) {
            throw new RuntimeException('Entries is not an array.');
        }

        return ["{$cursor}", $entries];
    }
}
