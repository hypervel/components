<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Contracts\Server\BootstrapsForServer;
use Hypervel\Contracts\Server\OnRequestInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Protocol\MediaType;
use Hypervel\Grpc\Protocol\MetadataCodec;
use Hypervel\Grpc\Protocol\ServiceMethod;
use Hypervel\Grpc\StatusCode;
use Hypervel\HttpServer\RequestBridge;
use Hypervel\HttpServer\ResponseBridge;
use Hypervel\Server\TlsOptions;
use InvalidArgumentException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Serve the isolated gRPC router on its dedicated HTTP/2 listener.
 *
 * @internal
 */
class Server implements OnRequestInterface, BootstrapsForServer
{
    private GrpcRouter $router;

    private ResponseFactory $responses;

    private ExceptionMapper $exceptions;

    private CallContextStore $contexts;

    private int $maxMetadataSize;

    private string $scheme;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Bootstrap the application and compile the isolated route collection.
     */
    public function bootstrapForServer(string $serverName): void
    {
        $this->container->make(KernelContract::class)->bootstrap();
        $this->router = $this->container->make(GrpcRouter::class);
        $this->router->syncMiddlewareFrom($this->container->make('router'));
        $config = $this->container->make('config');
        require $config->string('grpc.server.routes');
        $this->router->compileAndWarm();
        $this->responses = $this->container->make(ResponseFactory::class);
        $this->exceptions = $this->container->make(ExceptionMapper::class);
        $this->contexts = $this->container->make(CallContextStore::class);

        $this->maxMetadataSize = $config->integer('grpc.server.max_metadata_size');
        /** @var array<string, mixed> $tlsConfiguration */
        $tlsConfiguration = $config->array('grpc.server.tls');
        $this->scheme = TlsOptions::fromArray($tlsConfiguration)->enabled() ? 'https' : 'http';
    }

    /**
     * Handle one request on the dedicated gRPC listener.
     */
    public function onRequest(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
    {
        $response = null;
        $emissionStarted = false;

        try {
            CoordinatorManager::until(Constants::WORKER_START)->yield();

            $rawMethod = $this->rawMethod($swooleRequest);
            $rawPath = $this->rawPath($swooleRequest);
            $response = $this->preflight($swooleRequest, $rawMethod, $rawPath);

            if ($response === null) {
                $request = RequestBridge::createFromSwoole($swooleRequest);
                RequestContext::set($request);

                try {
                    $response = $this->router->dispatch($request);
                } catch (NotFoundHttpException) {
                    $response = $this->responses->error($this->unimplemented());
                }
            }

            if ($response instanceof GrpcHttpResponse || $response instanceof GrpcStreamedResponse) {
                $response = $this->responses->finalizeForEmission($response);
            }

            $emissionStarted = true;
            ResponseBridge::send($response, $swooleResponse, protocol: 'HTTP/2', request: $request ?? null);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            if ($response instanceof GrpcStreamedResponse) {
                $response->complete();
            }

            if ($emissionStarted) {
                $this->exceptions->report($throwable);
                $this->completeWritableResponse($swooleResponse);

                return;
            }

            try {
                $response = $this->responses->error($this->exceptions->map($throwable));
                $emissionStarted = true;
                ResponseBridge::send($response, $swooleResponse, protocol: 'HTTP/2', request: $request ?? null);
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $emissionFailure) {
                $this->exceptions->report($emissionFailure);
                $this->completeWritableResponse($swooleResponse);
            }
        } finally {
            if ($response instanceof GrpcStreamedResponse) {
                $response->complete();
            }

            $this->contexts->forget();
        }
    }

    /**
     * Reject requests that cannot enter gRPC route dispatch.
     */
    private function preflight(
        SwooleRequest $request,
        string $method,
        string $path,
    ): ?Response {
        if (($request->server['server_protocol'] ?? null) !== 'HTTP/2') {
            return new Response('', Response::HTTP_VERSION_NOT_SUPPORTED);
        }

        if ($method !== 'POST') {
            return new Response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
        }

        $contentType = $request->header['content-type'] ?? null;
        $mediaType = is_string($contentType) ? MediaType::parse($contentType) : null;

        if ($mediaType === null || ! $mediaType->isProtobuf()) {
            return new Response('', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        if ($this->requestMetadataSize($request, $method, $path) > $this->maxMetadataSize) {
            return $this->responses->error(new RpcException(
                StatusCode::ResourceExhausted,
                'The inbound gRPC metadata exceeds the configured limit.',
            ));
        }

        $te = $request->header['te'] ?? null;

        if (! is_string($te) || strtolower(trim($te, " \t")) !== 'trailers') {
            return $this->responses->error(new RpcException(
                StatusCode::Internal,
                'The gRPC request must include te: trailers.',
            ));
        }

        if (! $this->isCanonicalPath($path)) {
            return $this->responses->error($this->unimplemented());
        }

        return null;
    }

    /**
     * Calculate the transport-observable inbound HTTP/2 header-list size.
     */
    private function requestMetadataSize(SwooleRequest $request, string $method, string $path): int
    {
        $authority = $request->header['host'] ?? '';

        if (! is_string($authority)) {
            throw new ProtocolException('The gRPC request authority is malformed.');
        }

        $headers = [
            ':method' => $method,
            ':scheme' => $this->scheme,
            ':authority' => $authority,
            ':path' => $path,
        ];

        foreach ($request->header as $name => $value) {
            if (! is_string($name)) {
                throw new ProtocolException('A gRPC request header name is malformed.');
            }

            $name = strtolower($name);

            if ($name !== 'host' && ! str_starts_with($name, ':')) {
                $headers[$name] = $value;
            }
        }

        try {
            return MetadataCodec::wireSize($headers);
        } catch (InvalidArgumentException $exception) {
            throw new ProtocolException('The gRPC request metadata is malformed.', previous: $exception);
        }
    }

    /**
     * Determine whether a request path is one exact canonical service method.
     */
    private function isCanonicalPath(string $path): bool
    {
        if (! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '?')) {
            return false;
        }

        try {
            return ServiceMethod::parse($path)->path() === $path;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Return the untouched transport method.
     */
    private function rawMethod(SwooleRequest $request): string
    {
        $method = $request->server['request_method'] ?? '';

        return is_string($method) ? $method : '';
    }

    /**
     * Return the untouched path, including the exposed query string.
     */
    private function rawPath(SwooleRequest $request): string
    {
        $path = $request->server['request_uri'] ?? '';

        if (! is_string($path)) {
            return '';
        }

        $query = $request->server['query_string'] ?? null;

        if (is_string($query) && $query !== '' && ! str_contains($path, '?')) {
            return "{$path}?{$query}";
        }

        return $path;
    }

    /**
     * Build the standard unknown-method failure.
     */
    private function unimplemented(): RpcException
    {
        return new RpcException(
            StatusCode::Unimplemented,
            'The requested gRPC method is not implemented.',
        );
    }

    /**
     * Best-effort close a response after emission has started.
     */
    private function completeWritableResponse(SwooleResponse $response): void
    {
        try {
            if ($response->isWritable()) {
                $response->end();
            }
        } catch (Throwable) {
            // A partially emitted HTTP/2 stream cannot be replaced or repaired.
        }
    }
}
