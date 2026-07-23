<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Closure;
use Hypervel\ObjectPool\PoolErrorReporter;
use Override;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
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

        $primaryException = null;

        try {
            parent::sendContent();
        } catch (Throwable $throwable) {
            $primaryException = $throwable;
        }

        $this->clearChunks($primaryException);

        return $this;
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

        $primaryException = null;

        try {
            foreach ($this->chunks as $chunk) {
                if (! $write($chunk)) {
                    break;
                }
            }
        } catch (Throwable $throwable) {
            $primaryException = $throwable;
        }

        $this->clearChunks($primaryException);

        return true;
    }

    /**
     * Release retained chunks while preserving an earlier failure.
     */
    private function clearChunks(?Throwable $primaryException = null): void
    {
        try {
            $this->chunks = null;

            parent::setCallback(static function (): void {
            });
        } catch (Throwable $throwable) {
            if ($primaryException === null) {
                throw $throwable;
            }

            PoolErrorReporter::report($throwable);
        }

        if ($primaryException !== null) {
            throw $primaryException;
        }
    }
}
