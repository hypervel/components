<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Closure;
use DateInterval;
use DateTimeInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Http\Client\Response as HttpResponse;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Cache\Exceptions\CachingException;
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Contracts\Body\MergeableBody;
use Hypervel\Saloon\Contracts\FakeResponse;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\InvalidResponseClassException;
use Hypervel\Saloon\Exceptions\PendingRequestException;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;
use Hypervel\Saloon\Http\PendingRequest\BootPlugins;
use Hypervel\Saloon\Repositories\ArrayRepository;
use Hypervel\Saloon\Repositories\Body\MultipartBodyRepository;
use Hypervel\Saloon\Repositories\IntegerRepository;
use Hypervel\Saloon\Traits\Auth\AuthenticatesRequests;
use Hypervel\Saloon\Traits\Body\HasBody;
use Hypervel\Saloon\Traits\HasDebugging;
use Hypervel\Saloon\Traits\RequestProperties\HasRequestProperties;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use SensitiveParameter;
use UnitEnum;

/** @template TDto */
class PendingRequest
{
    use AuthenticatesRequests {
        authenticate as protected setAuthenticator;
    }
    use Conditionable;
    use HasBody;
    use HasDebugging;
    use HasRequestProperties {
        withQueryParameters as protected addQueryParameters;
    }
    use Macroable;

    /**
     * The finalized request URI.
     */
    protected ?UriInterface $uri = null;

    /**
     * The prepared request body.
     */
    protected ?StreamInterface $preparedBody = null;

    /**
     * Whether the operation body has been prepared.
     */
    protected bool $bodyPrepared = false;

    /**
     * The body offset at the start of the transport attempt.
     */
    protected ?int $attemptBodyOffset = null;

    /**
     * The final application-owned PSR request.
     */
    protected ?RequestInterface $psrRequest = null;

    /**
     * The final PSR request observers.
     *
     * @var list<Closure(RequestInterface, PendingRequest): void>
     */
    protected array $psrRequestObservers = [];

    /**
     * The dedicated transport authentication option.
     *
     * @var null|array{0: string, 1: string, 2?: string}
     */
    protected ?array $transportAuthentication = null;

    /**
     * The dedicated client certificate option.
     *
     * @var null|array{0: string, 1: string}|string
     */
    protected array|string|null $certificate = null;

    /**
     * The response supplied by request middleware or a mock client.
     */
    protected ?FakeResponse $fakeResponse = null;

    /**
     * Create a side-effect-free pending request.
     *
     * @param Request<TDto> $request
     */
    public function __construct(
        protected Connector $connector,
        protected Request $request,
        protected CacheFactory $cache,
        protected RateLimiter $rateLimiter,
    ) {
        $this->headerRepository = new ArrayRepository(array_merge(
            $connector->headers(),
            $request->headers(),
        ));
        $this->queryRepository = new ArrayRepository(array_merge(
            $connector->queryParameters(),
            $request->queryParameters(),
        ));
        $this->optionRepository = new ArrayRepository(array_replace_recursive(
            $connector->options(),
            $request->options(),
        ));
        $this->delayRepository = new IntegerRepository(
            $request->delayMilliseconds() ?? $connector->delayMilliseconds(),
        );
        $this->middlewarePipeline = clone $request->middleware();

        $connectorBody = $connector->copyDefaultBodyRepository();
        $requestBody = $request->copyBodyRepository();

        if ($connectorBody !== null && $requestBody !== null && ! $connectorBody instanceof $requestBody) {
            throw new PendingRequestException('Connector and request body types must be the same.');
        }

        $this->bodyRepository = $requestBody ?? $connectorBody;

        if ($connectorBody instanceof MergeableBody && $requestBody instanceof MergeableBody) {
            $this->bodyRepository = $connectorBody->merge($requestBody->all());
        }

        $this->cookieGroups = $request->cookies();
        $this->retryPolicy = $request->retryPolicy();
        $this->authenticator = $request->authenticator() ?? $connector->authenticator();
    }

    /**
     * Get the connector.
     */
    public function connector(): Connector
    {
        return $this->connector;
    }

    /**
     * Get the request.
     *
     * @return Request<TDto>
     */
    public function request(): Request
    {
        return $this->request;
    }

    /**
     * Get the HTTP method.
     */
    public function method(): Method
    {
        return $this->request->method();
    }

    /**
     * Get the final request URI.
     */
    public function uri(): UriInterface
    {
        return $this->uri ?? UrlResolver::withQuery(
            UrlResolver::resolve(
                $this->connector->resolveBaseUrl(),
                $this->request->resolveEndpoint(),
                $this->request->allowsBaseUrlOverride() ?? $this->connector->allowsBaseUrlOverride(),
            ),
            $this->queryParameters(),
        );
    }

    /**
     * Finalize the request URI after ordinary request middleware.
     */
    public function finalizeUri(): static
    {
        $this->uri = $this->uri();

        return $this;
    }

    /**
     * Add query parameters to the request.
     *
     * @param array<string, mixed> $parameters
     * @return $this
     */
    public function withQueryParameters(array $parameters): static
    {
        $this->uri = null;
        $this->addQueryParameters($parameters);

        return $this;
    }

    /**
     * Authenticate the pending request immediately.
     *
     * @return $this
     */
    public function authenticate(Authenticator $authenticator): static
    {
        $this->setAuthenticator($authenticator);
        $authenticator->set($this);

        return $this;
    }

    /**
     * Apply the configured authenticator.
     */
    public function applyAuthentication(): static
    {
        $this->authenticator?->set($this);

        return $this;
    }

    /**
     * Boot the connector and request plugins.
     */
    public function bootPlugins(): static
    {
        (new BootPlugins)($this);

        return $this;
    }

    /**
     * Merge worker-global middleware into this operation.
     */
    public function mergeMiddleware(MiddlewarePipeline $middleware): static
    {
        $this->middleware()->merge($middleware);

        return $this;
    }

    /**
     * Execute the request middleware pipeline.
     */
    public function executeRequestPipeline(): static
    {
        $this->middleware()->executeRequestPipeline($this);

        return $this;
    }

    /**
     * Execute the response middleware pipeline.
     */
    public function executeResponsePipeline(Response $response): Response
    {
        return $this->middleware()->executeResponsePipeline($response);
    }

    /**
     * Execute the fatal exception middleware pipeline.
     */
    public function executeFatalPipeline(FatalRequestException $exception): void
    {
        $this->middleware()->executeFatalPipeline($exception);
    }

    /**
     * Prepare the request body after ordinary request middleware.
     */
    public function prepareBody(): static
    {
        $repository = $this->bodyRepository();
        $this->preparedBody = $repository?->toStream();
        $this->bodyPrepared = true;

        // The boundary belongs to the materialized multipart repository, so its
        // content type cannot be supplied by a static body-trait boot hook.
        if (($contentType = $this->multipartContentType()) !== null) {
            $this->contentType($contentType);
        }

        $this->attemptBodyOffset = $this->streamOffset($this->preparedBody);

        return $this;
    }

    /**
     * Get the prepared request body.
     */
    public function preparedBody(): ?StreamInterface
    {
        return $this->preparedBody;
    }

    /**
     * Set the dedicated transport authentication option.
     *
     * @param array{0: string, 1: string, 2?: string} $authentication
     * @return $this
     */
    public function setTransportAuthentication(#[SensitiveParameter] array $authentication): static
    {
        $this->transportAuthentication = $authentication;

        return $this;
    }

    /**
     * Get the dedicated transport authentication option.
     *
     * @return null|array{0: string, 1: string, 2?: string}
     */
    public function transportAuthentication(): ?array
    {
        return $this->transportAuthentication;
    }

    /**
     * Set the client certificate option.
     *
     * @return $this
     */
    public function setCertificate(string $path, #[SensitiveParameter] ?string $password = null): static
    {
        $this->certificate = $password === null ? $path : [$path, $password];

        return $this;
    }

    /**
     * Get the client certificate option.
     *
     * @return null|array{0: string, 1: string}|string
     */
    public function certificate(): array|string|null
    {
        return $this->certificate;
    }

    /**
     * Observe the final application-owned PSR request.
     *
     * @param callable(RequestInterface, PendingRequest): void $observer
     * @return $this
     */
    public function observePsrRequest(callable $observer): static
    {
        $this->psrRequestObservers[] = $observer(...);

        return $this;
    }

    /**
     * Apply the connector and request PSR hooks.
     */
    public function handlePsrRequest(RequestInterface $request): RequestInterface
    {
        $request = $this->connector->handlePsrRequest($request, $this);

        return $this->request->handlePsrRequest($request, $this);
    }

    /**
     * Notify the final PSR request observers.
     */
    public function notifyPsrRequestObservers(RequestInterface $request): void
    {
        foreach ($this->psrRequestObservers as $observer) {
            $observer($request, $this);
        }
    }

    /**
     * Create the logical PSR request for a short-circuit response.
     *
     * @internal
     */
    public function createPsrRequest(): RequestInterface
    {
        $this->finalizeUri();

        if (! $this->bodyPrepared) {
            $this->prepareBody();
        }

        $request = new PsrRequest(
            $this->method()->value,
            $this->uri(),
            HeaderNormalizer::normalize($this->headers()),
            $this->preparedBody,
        );
        $request = $this->handlePsrRequest($request);
        $this->notifyPsrRequestObservers($request);
        $this->setPsrRequest($request);

        return $request;
    }

    /**
     * Set the final application-owned PSR request.
     *
     * @return $this
     */
    public function setPsrRequest(RequestInterface $request): static
    {
        $this->psrRequest = $request;
        $this->attemptBodyOffset = $this->streamOffset($request->getBody());

        return $this;
    }

    /**
     * Get the captured PSR request or create a logical snapshot.
     *
     * Before authoritative capture, the snapshot does not run PSR hooks or
     * observers. It materializes the body from its repository instead of
     * reusing a prepared stream, though caller-owned streams may be shared.
     */
    public function toPsrRequest(): RequestInterface
    {
        if ($this->psrRequest !== null) {
            return $this->psrRequest;
        }

        $repository = $this->bodyRepository();
        $headers = $this->headers();

        if (($contentType = $this->multipartContentType()) !== null) {
            $headers['Content-Type'] = $contentType;
        }

        return new PsrRequest(
            $this->method()->value,
            $this->uri(),
            HeaderNormalizer::normalize($headers),
            $repository?->toStream(),
        );
    }

    /**
     * Restore the attempt body before another attempt.
     */
    public function restoreAttemptBody(): bool
    {
        $stream = $this->psrRequest?->getBody() ?? $this->preparedBody;

        if ($stream === null) {
            return true;
        }

        if (! $stream->isSeekable() || $this->attemptBodyOffset === null) {
            return false;
        }

        $stream->seek($this->attemptBodyOffset);

        return true;
    }

    /**
     * Set the fake response.
     *
     * @return $this
     */
    public function setFakeResponse(?FakeResponse $fakeResponse): static
    {
        $this->fakeResponse = $fakeResponse;

        return $this;
    }

    /**
     * Get the fake response.
     */
    public function fakeResponse(): ?FakeResponse
    {
        return $this->fakeResponse;
    }

    /**
     * Validate the request's caching configuration.
     */
    public function validateCachingConfiguration(): static
    {
        if ($this->request->usesCachingControls() && $this->cacheableResource() === null) {
            throw new CachingException(sprintf(
                'The request or connector must implement [%s] when using request cache controls.',
                Cacheable::class,
            ));
        }

        return $this;
    }

    /**
     * Determine if this operation is cacheable.
     */
    public function isCacheable(): bool
    {
        $methods = $this->request->resolveCacheableMethods()
            ?? $this->connector->resolveCacheableMethods();

        return $this->cacheableResource() !== null
            && $this->request->cachingEnabled()
            && in_array($this->method(), $methods, true);
    }

    /**
     * Determine if the matching cache entry should be invalidated.
     */
    public function shouldInvalidateCache(): bool
    {
        return $this->request->shouldInvalidateCache();
    }

    /**
     * Get the cache duration.
     */
    public function cacheFor(): DateInterval|DateTimeInterface|int
    {
        return $this->cacheableResource()?->cacheFor()
            ?? throw new CachingException('The pending request is not cacheable.');
    }

    /**
     * Get the selected cache store.
     */
    public function cacheStore(): UnitEnum|string|null
    {
        $requestStore = $this->request instanceof Cacheable ? $this->request->cacheStore() : null;

        if ($requestStore !== null) {
            return $requestStore;
        }

        return $this->connector instanceof Cacheable ? $this->connector->cacheStore() : null;
    }

    /**
     * Resolve the custom cache key.
     */
    public function resolveCacheKey(): ?string
    {
        return $this->request->resolveCacheKey($this)
            ?? $this->connector->resolveCacheKey($this);
    }

    /**
     * Get the cache factory.
     */
    public function cache(): CacheFactory
    {
        return $this->cache;
    }

    /**
     * Get the rate limiter manager.
     */
    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    /**
     * Resolve the request or connector that owns cache duration and storage.
     */
    protected function cacheableResource(): ?Cacheable
    {
        if ($this->request instanceof Cacheable) {
            return $this->request;
        }

        return $this->connector instanceof Cacheable ? $this->connector : null;
    }

    /**
     * Create the response selected by this operation.
     *
     * @return Response<TDto>
     */
    public function createResponse(HttpResponse $response, RequestInterface $request): Response
    {
        $saloonResponse = Response::fromResponse($response, $this, $request);
        $responseClass = $this->resolveResponseClass($saloonResponse);

        return $responseClass === null
            ? $saloonResponse
            : $responseClass::fromResponse($saloonResponse, $this, $request);
    }

    /**
     * Resolve the custom response class.
     *
     * @return null|class-string<Response>
     */
    public function resolveResponseClass(Response $response): ?string
    {
        $class = $this->request->resolveResponseClass($response)
            ?? $this->connector->resolveResponseClass($response);

        if ($class !== null && (! class_exists($class) || ! is_a($class, Response::class, true))) {
            throw new InvalidResponseClassException;
        }

        return $class;
    }

    /**
     * Get the current offset of a seekable stream.
     */
    protected function streamOffset(?StreamInterface $stream): ?int
    {
        return $stream !== null && $stream->isSeekable() ? $stream->tell() : null;
    }

    /**
     * Resolve the multipart content type when the caller did not provide one.
     */
    protected function multipartContentType(): ?string
    {
        $repository = $this->bodyRepository();

        return $repository instanceof MultipartBodyRepository && ! $this->hasHeader('Content-Type')
            ? $repository->contentType()
            : null;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
