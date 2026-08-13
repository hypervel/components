<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use GuzzleHttp\Psr7\Utils;
use Hypervel\Http\Client\Response as HttpResponse;
use Hypervel\Saloon\Contracts\DataObjects\WithResponse;
use Hypervel\Saloon\Contracts\FakeResponse;
use Hypervel\Saloon\Exceptions\Request\ClientException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Exceptions\Request\ServerException;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use SimpleXMLElement;
use Symfony\Component\DomCrawler\Crawler;

/** @template TDto */
class Response extends HttpResponse
{
    /**
     * Whether the response came from the cache.
     */
    protected bool $cached = false;

    /**
     * Whether the response came from a mock client.
     */
    protected bool $mocked = false;

    /**
     * The fake response that produced this response.
     */
    protected ?FakeResponse $fakeResponse = null;

    /**
     * The pending request that produced the response.
     *
     * @var PendingRequest<TDto>
     */
    protected PendingRequest $pendingRequest;

    /**
     * The final application-owned PSR request.
     */
    protected RequestInterface $psrRequest;

    /**
     * Create a Saloon response from a framework response.
     *
     * @param PendingRequest<TDto> $pendingRequest
     */
    public static function fromResponse(
        HttpResponse $response,
        PendingRequest $pendingRequest,
        RequestInterface $psrRequest,
    ): static {
        $saloonResponse = new static($response->toPsrResponse());
        $saloonResponse->cookies = $response->cookies;
        $saloonResponse->transferStats = $response->transferStats;
        $saloonResponse->decoded = $response->decoded;
        $saloonResponse->hasDecoded = $response->hasDecoded;
        $saloonResponse->decodingFlags = $response->decodingFlags;
        $saloonResponse->decodeUsing = $response->decodeUsing;
        $saloonResponse->truncateExceptionsAt = $response->truncateExceptionsAt;
        $saloonResponse->pendingRequest = $pendingRequest;
        $saloonResponse->psrRequest = $psrRequest;

        return $saloonResponse;
    }

    /**
     * Get the response body.
     */
    public function body(): string
    {
        $stream = $this->response->getBody();

        if ($stream->isSeekable()) {
            return parent::body();
        }

        $body = $stream->getContents();
        $this->response = $this->response->withBody(Utils::streamFor($body));
        $this->decoded = null;
        $this->hasDecoded = false;
        $this->decodingFlags = 0;

        return $body;
    }

    /**
     * Get the response body stream.
     */
    public function stream(): StreamInterface
    {
        return $this->response->getBody();
    }

    /**
     * Get the pending request.
     *
     * @return PendingRequest<TDto>
     */
    public function pendingRequest(): PendingRequest
    {
        return $this->pendingRequest;
    }

    /**
     * Get the connector.
     */
    public function connector(): Connector
    {
        return $this->pendingRequest->connector();
    }

    /**
     * Get the original request.
     *
     * @return Request<TDto>
     */
    public function request(): Request
    {
        return $this->pendingRequest->request();
    }

    /**
     * Get the final application-owned PSR request.
     */
    public function toPsrRequest(): RequestInterface
    {
        return $this->psrRequest;
    }

    /**
     * Determine if the integration considers the response failed.
     */
    public function failed(): bool
    {
        $requestFailed = $this->request()->hasRequestFailed($this);

        if ($requestFailed !== null) {
            return $requestFailed;
        }

        $connectorFailed = $this->connector()->hasRequestFailed($this);

        return $connectorFailed ?? parent::failed();
    }

    /**
     * Determine if the response should throw a request exception.
     */
    public function shouldThrowRequestException(): bool
    {
        return $this->request()->shouldThrowRequestException($this)
            || $this->connector()->shouldThrowRequestException($this);
    }

    /**
     * Create an exception when the integration considers the response throwable.
     */
    public function toException(): ?RequestException
    {
        return $this->shouldThrowRequestException() ? $this->newRequestException() : null;
    }

    /**
     * Throw an exception when the integration considers the response throwable.
     *
     * @param null|(callable(Response<TDto>, RequestException): mixed) $callback
     * @throws RequestException
     */
    public function throw(?callable $callback = null): static
    {
        $exception = $this->toException();

        if ($exception === null) {
            return $this;
        }

        if ($callback !== null) {
            $callback($this, $exception);
        }

        throw $exception;
    }

    /**
     * Create a new request exception for the response.
     */
    protected function newRequestException(): RequestException
    {
        $exception = $this->request()->getRequestException($this)
            ?? $this->connector()->getRequestException($this);

        if ($exception !== null) {
            return $exception;
        }

        return match (true) {
            $this->clientError() => new ClientException($this, $this->truncateExceptionsAt),
            $this->serverError() => new ServerException($this, $this->truncateExceptionsAt),
            default => new RequestException($this, $this->truncateExceptionsAt),
        };
    }

    /**
     * Convert the response into a data object.
     *
     * @return TDto
     */
    public function dto(): mixed
    {
        $dataObject = $this->request()->createDtoFromResponse($this)
            ?? $this->connector()->createDtoFromResponse($this);

        if ($dataObject instanceof WithResponse) {
            $dataObject->setResponse($this);
        }

        return $dataObject;
    }

    /**
     * Convert the response into a data object or fail.
     *
     * @return TDto
     */
    public function dtoOrFail(): mixed
    {
        if ($this->failed()) {
            throw new LogicException(
                'Unable to create a data transfer object because the response failed.',
                0,
                $this->toException(),
            );
        }

        return $this->dto();
    }

    /**
     * Convert the XML response into a SimpleXMLElement.
     *
     * @see https://www.php.net/manual/en/book.simplexml.php
     */
    public function xml(mixed ...$arguments): SimpleXMLElement|false
    {
        return simplexml_load_string($this->body(), ...$arguments);
    }

    /**
     * Parse the HTML or XML response into a DOM crawler.
     */
    public function dom(): Crawler
    {
        return new Crawler($this->body());
    }

    /**
     * Convert the response into a data URL.
     */
    public function dataUrl(): string
    {
        return 'data:' . $this->header('Content-Type') . ';base64,' . base64_encode($this->body());
    }

    /**
     * Create a temporary resource containing the response body.
     *
     * @return resource
     */
    public function getRawStream(): mixed
    {
        $resource = fopen('php://temp', 'wb+');

        if ($resource === false) {
            throw new LogicException('Unable to create a temporary response resource.');
        }

        $this->saveBodyToFile($resource, false);

        return $resource;
    }

    /**
     * Save the response body to a path or resource.
     *
     * @param resource|string $resourceOrPath
     */
    public function saveBodyToFile(mixed $resourceOrPath, bool $closeResource = true): void
    {
        if (! is_string($resourceOrPath) && ! is_resource($resourceOrPath)) {
            throw new InvalidArgumentException('The resource must be a file path or PHP resource.');
        }

        $ownsResource = is_string($resourceOrPath);
        $resource = $ownsResource ? fopen($resourceOrPath, 'wb+') : $resourceOrPath;

        if ($resource === false) {
            throw new LogicException('Unable to open the response destination.');
        }

        $source = $this->response->getBody();
        $sourcePosition = $source->isSeekable() ? $source->tell() : null;
        $destination = Utils::streamFor($resource);

        try {
            if ($sourcePosition !== null) {
                $source->rewind();
            }

            if ($destination->isSeekable()) {
                $destination->rewind();
                ftruncate($resource, 0);
            }

            Utils::copyToStream($source, $destination);

            if (! $ownsResource && $destination->isSeekable()) {
                $destination->rewind();
            }
        } finally {
            if ($sourcePosition !== null) {
                $source->seek($sourcePosition);
            }

            if ($ownsResource || $closeResource) {
                $destination->close();
            } else {
                $destination->detach();
            }
        }
    }

    /**
     * Determine if the response came from the cache.
     */
    public function isCached(): bool
    {
        return $this->cached;
    }

    /**
     * Set whether the response came from the cache.
     *
     * @return $this
     */
    public function setCached(bool $cached): static
    {
        $this->cached = $cached;

        return $this;
    }

    /**
     * Determine if the response came from a mock client.
     */
    public function isMocked(): bool
    {
        return $this->mocked;
    }

    /**
     * Set whether the response came from a mock client.
     *
     * @return $this
     */
    public function setMocked(bool $mocked): static
    {
        $this->mocked = $mocked;

        return $this;
    }

    /**
     * Determine if the response came from a short-circuit source.
     */
    public function isFaked(): bool
    {
        return $this->cached || $this->mocked;
    }

    /**
     * Set the fake response that produced this response.
     *
     * @return $this
     */
    public function setFakeResponse(FakeResponse $fakeResponse): static
    {
        $this->fakeResponse = $fakeResponse;

        return $this;
    }

    /**
     * Get the fake response that produced this response.
     */
    public function fakeResponse(): ?FakeResponse
    {
        return $this->fakeResponse;
    }
}
