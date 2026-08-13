<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Faking;

use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Contracts\FakeResponse as FakeResponseContract;
use Hypervel\Saloon\Http\HeaderNormalizer;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Repositories\Body\JsonBodyRepository;
use Hypervel\Saloon\Repositories\Body\StringBodyRepository;
use Hypervel\Saloon\Traits\Makeable;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @method static static make(array|string $body = [], int $status = 200, array<string, mixed> $headers = [])
 */
class FakeResponse implements FakeResponseContract
{
    use Makeable;

    /**
     * The response body.
     */
    protected BodyRepository $body;

    /**
     * The configured exception resolver.
     *
     * @var null|Closure(PendingRequest): ?Throwable
     */
    protected ?Closure $responseException = null;

    /**
     * Create a fake response.
     *
     * @param array<string, mixed>|string $body
     * @param array<string, mixed> $headers
     */
    public function __construct(
        array|string $body = [],
        protected int $status = 200,
        protected array $headers = [],
    ) {
        $this->body = is_array($body)
            ? new JsonBodyRepository($body)
            : new StringBodyRepository($body);
    }

    /**
     * Get the response body repository.
     */
    public function body(): BodyRepository
    {
        return $this->body;
    }

    /**
     * Get the response status.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Get the response headers.
     *
     * @return array<string, mixed>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Configure an exception for the request.
     *
     * @return $this
     */
    public function throw(Closure|Throwable $value): static
    {
        $this->responseException = $value instanceof Throwable
            ? static fn (): Throwable => $value
            : $value;

        return $this;
    }

    /**
     * Resolve the configured exception.
     */
    public function getException(PendingRequest $pendingRequest): ?Throwable
    {
        return $this->responseException !== null
            ? ($this->responseException)($pendingRequest)
            : null;
    }

    /**
     * Create a fake response from a fixture.
     */
    public static function fixture(string $name): Fixture
    {
        return new Fixture($name);
    }

    /**
     * Create the PSR response.
     */
    public function createPsrResponse(): ResponseInterface
    {
        return new PsrResponse(
            $this->status,
            HeaderNormalizer::normalize($this->headers),
            $this->body->toStream(),
        );
    }
}
