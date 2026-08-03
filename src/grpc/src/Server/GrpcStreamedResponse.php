<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Closure;
use Hypervel\Contracts\Http\HasTrailers;
use Hypervel\Http\IterableStreamedResponse;
use Override;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carry a lazily produced protocol-owned gRPC stream to the HTTP/2 bridge.
 *
 * @internal
 */
class GrpcStreamedResponse extends IterableStreamedResponse implements HasTrailers
{
    private ?Closure $completion = null;

    /** @var array<string, list<string>> */
    private array $protocolHeaders;

    private bool $protocolSealed = false;

    private bool $protocolProducerChanged = false;

    /**
     * @param iterable<string> $chunks
     * @param array<string, list<string>|string> $headers
     * @param list<string> $trailerNames
     * @param Closure(): array<string, string> $resolveTrailers
     */
    public function __construct(
        iterable $chunks,
        array $headers,
        private readonly array $trailerNames,
        private readonly Closure $resolveTrailers,
    ) {
        parent::__construct($this->completeAfter($chunks), Response::HTTP_OK, $headers);

        // Symfony invents or recomputes Cache-Control even though it is not part of
        // the gRPC response, which would also make metadata accounting inaccurate.
        $this->headers->remove('cache-control');
        $this->protocolHeaders = $this->headers->all();
        $this->protocolSealed = true;
    }

    /**
     * Set the chunks associated with the response.
     *
     * @param iterable<string> $chunks
     */
    #[Override]
    public function setChunks(iterable $chunks): static
    {
        if ($this->protocolSealed) {
            $this->protocolProducerChanged = true;
        }

        return parent::setChunks($chunks);
    }

    /**
     * Set the callback associated with the response.
     */
    #[Override]
    public function setCallback(callable $callback): static
    {
        if ($this->protocolSealed) {
            $this->protocolProducerChanged = true;
        }

        return parent::setCallback($callback);
    }

    /**
     * Set the response content.
     */
    #[Override]
    public function setContent(?string $content): static
    {
        if ($this->protocolSealed) {
            $this->protocolProducerChanged = true;
        }

        return parent::setContent($content);
    }

    /**
     * Get the trailer names known before response emission.
     */
    public function trailerNames(): array
    {
        return $this->trailerNames;
    }

    /**
     * Get the final response trailers.
     */
    public function trailers(): array
    {
        return ($this->resolveTrailers)();
    }

    /**
     * Register cleanup for the call that owns this response.
     *
     * @internal
     */
    public function completeUsing(Closure $completion): void
    {
        $this->completion = $completion;
    }

    /**
     * Complete the call that owns this response.
     *
     * @internal
     */
    public function complete(): void
    {
        $completion = $this->completion;
        $this->completion = null;

        $completion?->__invoke();
    }

    /**
     * Determine whether middleware preserved the protocol-owned response state.
     *
     * @internal
     */
    public function protocolStateIsIntact(): bool
    {
        return ! $this->protocolProducerChanged
            && $this->getStatusCode() === Response::HTTP_OK
            && $this->headers->all() === $this->protocolHeaders;
    }

    /**
     * Complete the owning call when stream production stops for any reason.
     *
     * @param iterable<string> $chunks
     * @return iterable<string>
     */
    private function completeAfter(iterable $chunks): iterable
    {
        try {
            yield from $chunks;
        } finally {
            $this->complete();
        }
    }
}
