<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Hypervel\Contracts\Http\HasTrailers;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carry a complete protocol-owned gRPC response to the HTTP/2 bridge.
 *
 * @internal
 */
class GrpcHttpResponse extends Response implements HasTrailers
{
    private readonly string $protocolContent;

    /** @var array<string, list<string>> */
    private array $protocolHeaders;

    /**
     * @param array<string, list<string>|string> $headers
     * @param list<string> $trailerNames
     * @param array<string, string> $trailers
     */
    public function __construct(
        string $content,
        array $headers,
        private readonly array $trailerNames,
        private readonly array $trailers,
    ) {
        parent::__construct($content, Response::HTTP_OK, $headers);

        // Symfony invents or recomputes Cache-Control even though it is not part of
        // the gRPC response, which would also make metadata accounting inaccurate.
        $this->headers->remove('cache-control');
        $this->protocolContent = $content;
        $this->protocolHeaders = $this->headers->all();
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
        return $this->trailers;
    }

    /**
     * Determine whether middleware preserved the protocol-owned response state.
     *
     * @internal
     */
    public function protocolStateIsIntact(): bool
    {
        return $this->getStatusCode() === Response::HTTP_OK
            && $this->getContent() === $this->protocolContent
            && $this->headers->all() === $this->protocolHeaders;
    }
}
