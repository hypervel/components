<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Closure;
use Override;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Traversable;

/**
 * Retain iterable chunks so the Swoole bridge can stop production after a failed write.
 */
class IterableStreamedResponse extends StreamedResponse
{
    /** @var null|iterable<string> */
    private array|Traversable|null $chunks = null;

    /**
     * Create a new iterable streamed response.
     *
     * @param iterable<string> $chunks
     */
    public function __construct(iterable $chunks, int $status = 200, array $headers = [])
    {
        parent::__construct(null, $status, $headers);

        $this->setChunks($chunks);
    }

    /**
     * Set the chunks associated with the response.
     *
     * @param iterable<string> $chunks
     */
    #[Override]
    public function setChunks(iterable $chunks): static
    {
        $this->chunks = $chunks;

        return parent::setChunks($chunks);
    }

    /**
     * Set the callback associated with the response.
     */
    #[Override]
    public function setCallback(callable $callback): static
    {
        $this->chunks = null;

        return parent::setCallback($callback);
    }

    /**
     * Send the response content.
     */
    #[Override]
    public function sendContent(): static
    {
        if ($this->chunks === null) {
            return parent::sendContent();
        }

        try {
            return parent::sendContent();
        } finally {
            $this->clearChunks();
        }
    }

    /**
     * Stream retained chunks through the given writer.
     *
     * @param Closure(string): bool $write
     *
     * @internal
     */
    public function streamTo(Closure $write): bool
    {
        if ($this->chunks === null) {
            return false;
        }

        try {
            foreach ($this->chunks as $chunk) {
                if (! $write($chunk)) {
                    break;
                }
            }
        } finally {
            $this->clearChunks();
        }

        return true;
    }

    /**
     * Release retained chunks and prevent callback replay.
     */
    private function clearChunks(): void
    {
        $this->chunks = null;

        parent::setCallback(static function (): void {
        });
    }
}
