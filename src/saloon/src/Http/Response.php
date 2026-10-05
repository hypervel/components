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
use Hypervel\Saloon\Exceptions\Request\Statuses\BadGatewayException;
use Hypervel\Saloon\Exceptions\Request\Statuses\BadRequestException;
use Hypervel\Saloon\Exceptions\Request\Statuses\ConflictException;
use Hypervel\Saloon\Exceptions\Request\Statuses\ForbiddenException;
use Hypervel\Saloon\Exceptions\Request\Statuses\GatewayTimeoutException;
use Hypervel\Saloon\Exceptions\Request\Statuses\InternalServerErrorException;
use Hypervel\Saloon\Exceptions\Request\Statuses\MethodNotAllowedException;
use Hypervel\Saloon\Exceptions\Request\Statuses\NotFoundException;
use Hypervel\Saloon\Exceptions\Request\Statuses\PaymentRequiredException;
use Hypervel\Saloon\Exceptions\Request\Statuses\RequestTimeOutException;
use Hypervel\Saloon\Exceptions\Request\Statuses\ServiceUnavailableException;
use Hypervel\Saloon\Exceptions\Request\Statuses\TooManyRequestsException;
use Hypervel\Saloon\Exceptions\Request\Statuses\UnauthorizedException;
use Hypervel\Saloon\Exceptions\Request\Statuses\UnprocessableEntityException;
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
        $saloonResponse->decodedJson = $response->decodedJson;
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
        $buffer = Utils::streamFor($body);
        $buffer->seek(0, SEEK_END);
        $this->response = $this->response->withBody($buffer);
        $this->decoded = null;
        $this->decodedJson = false;
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

        $status = $this->status();

        $exception = match (true) {
            $status === 400 => BadRequestException::class,
            $status === 401 => UnauthorizedException::class,
            $status === 402 => PaymentRequiredException::class,
            $status === 403 => ForbiddenException::class,
            $status === 404 => NotFoundException::class,
            $status === 405 => MethodNotAllowedException::class,
            $status === 408 => RequestTimeOutException::class,
            $status === 409 => ConflictException::class,
            $status === 422 => UnprocessableEntityException::class,
            $status === 429 => TooManyRequestsException::class,
            $status === 500 => InternalServerErrorException::class,
            $status === 502 => BadGatewayException::class,
            $status === 503 => ServiceUnavailableException::class,
            $status === 504 => GatewayTimeoutException::class,
            $this->serverError() => ServerException::class,
            $this->clientError() => ClientException::class,
            default => RequestException::class,
        };

        return new $exception($this, $this->truncateExceptionsAt);
    }

    /**
     * Get the JSON decoded body as an array. Provide a key to find a specific item in the JSON.
     *
     * Alias of json()
     */
    public function array(int|string|null $key = null, mixed $default = null): mixed
    {
        return $this->json(is_int($key) ? (string) $key : $key, $default);
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
                'Unable to create data transfer object as the response has failed.',
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

    // xmlReader() is not included; see the package README.

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
     * Determine if the response is in JSON format.
     */
    public function isJson(): bool
    {
        return str_contains(mb_strtolower($this->header('Content-Type')), 'json');
    }

    /**
     * Determine if the response is in XML format.
     */
    public function isXml(): bool
    {
        return str_contains(mb_strtolower($this->header('Content-Type')), 'xml');
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
