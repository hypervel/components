<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hyperf\Collection\LazyCollection as BaseLazyCollection;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends \Hyperf\Collection\LazyCollection<TKey, TValue>
 */
class LazyCollection extends BaseLazyCollection
{
    /**
     * Chunk the collection into chunks with a callback.
     *
     * @phpstan-ignore-next-line
     */
    public function chunkWhile(callable $callback): static
    {
        return new static(function () use ($callback) {
            $iterator = $this->getIterator();

            $chunk = new Collection();

            if ($iterator->valid()) {
                $chunk[$iterator->key()] = $iterator->current();

                $iterator->next();
            }

            while ($iterator->valid()) {
                if (! $callback($iterator->current(), $iterator->key(), $chunk)) {
                    yield new static($chunk);

                    $chunk = new Collection();
                }

                $chunk[$iterator->key()] = $iterator->current();

                $iterator->next();
            }

            if ($chunk->isNotEmpty()) {
                yield new static($chunk);
            }
        });
    }
}
