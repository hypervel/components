<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Foundation\Exceptions\Handler as ExceptionHandler;
use Hypervel\Foundation\Http\Contracts\MiddlewareContract;
use Hypervel\Foundation\Http\Traits\HasMiddleware;
use Hypervel\Http\UploadedFile;
use Hypervel\HttpMessage\Server\Request;
use Hypervel\HttpMessage\Server\Response;
use Hypervel\HttpMessage\Upload\UploadedFile as HyperfUploadedFile;
use Hypervel\HttpServer\Contracts\CoreMiddlewareInterface;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\RequestTerminated;
use Hypervel\HttpServer\Server as HttpServer;
use Hypervel\Support\SafeCaller;
use Psr\Http\Message\ResponseInterface;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Throwable;

use function Hypervel\Coroutine\defer;

class Kernel extends HttpServer implements MiddlewareContract
{
    use HasMiddleware;

    protected bool $enableHttpMethodParameterOverride;

    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName = $serverName;
        $this->coreMiddleware = $this->createCoreMiddleware();

        $this->initExceptionHandlers();
        $this->initOption();
    }

    /**
     * Create the core middleware instance.
     *
     * Overrides parent to use named parameters, since the Laravel container
     * does not support positional parameter arrays.
     */
    protected function createCoreMiddleware(): CoreMiddlewareInterface
    {
        return $this->container->make(\Hypervel\Http\CoreMiddleware::class, [
            'container' => $this->container,
            'serverName' => $this->serverName,
        ]);
    }

    protected function initExceptionHandlers(): void
    {
        /* @phpstan-ignore-next-line */
        $this->exceptionHandlers = $this->container->bound(ExceptionHandlerContract::class)
            ? [ExceptionHandlerContract::class]
            : [ExceptionHandler::class];
    }

    public function onRequest(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
    {
        try {
            CoordinatorManager::until(Constants::WORKER_START)->yield();

            [$request, $response] = $this->initRequestAndResponse($swooleRequest, $swooleResponse);

            // Trim the trailing slashes of the path.
            $uri = $request->getUri();
            if ($uri->getPath() !== '/') {
                $request->setUri(
                    $uri->setPath(rtrim($uri->getPath(), '/'))
                );
            }

            // Convert Hyperf's uploaded files to Laravel style UploadedFile
            if ($uploadedFiles = $request->getUploadedFiles()) {
                $request = $request->withUploadedFiles(
                    $this->convertUploadedFiles($uploadedFiles)
                );

                RequestContext::set($request);
            }

            $this->dispatchRequestReceivedEvent(
                $request = $this->coreMiddleware->dispatch($request), // @phpstan-ignore argument.type (dispatch returns Request impl)
                $response
            );

            $response = $this->dispatcher->dispatch(
                $request,
                $this->getMiddlewareForRequest($request),
                $this->coreMiddleware
            );
        } catch (Throwable $throwable) {
            $response = $this->getResponseForException($throwable);
        } finally {
            if (isset($request)) {
                /* @phpstan-ignore-next-line */
                $this->dispatchRequestHandledEvents($request, $response, $throwable ?? null);
            }

            if (! isset($response) || ! $response instanceof ResponseInterface) {
                return;
            }

            // Send the Response to client.
            if (isset($request) && $request->getMethod() === 'HEAD') {
                $this->responseEmitter->emit($response, $swooleResponse, false);
            } else {
                $this->responseEmitter->emit($response, $swooleResponse);
            }
        }
    }

    /**
     * Convert the given array of Hyperf UploadedFiles to custom Hypervel UploadedFiles.
     *
     * @param array<string, null|HyperfUploadedFile|HyperfUploadedFile[]> $files
     * @return array<string, null|UploadedFile|UploadedFile[]>
     */
    protected function convertUploadedFiles(array $files): array
    {
        return array_map(function ($file) {
            if (is_null($file) || (is_array($file) && empty(array_filter($file)))) { // @phpstan-ignore arrayFilter.same (nested arrays may contain nulls)
                return $file;
            }

            return is_array($file)
                ? $this->convertUploadedFiles($file)
                : UploadedFile::createFromBase($file);
        }, $files);
    }

    protected function dispatchRequestReceivedEvent(Request $request, ResponseInterface $response): void
    {
        if (! $this->option?->isEnableRequestLifecycle()) {
            return;
        }

        $this->event?->dispatch(new RequestReceived(
            request: $request,
            response: $response,
            server: $this->serverName
        ));
    }

    protected function dispatchRequestHandledEvents(Request $request, ResponseInterface $response, ?Throwable $throwable = null): void
    {
        if (! $this->option?->isEnableRequestLifecycle()) {
            return;
        }

        defer(fn () => $this->event?->dispatch(new RequestTerminated(
            request: $request,
            response: $response,
            exception: $throwable,
            server: $this->serverName
        )));

        $this->event?->dispatch(new RequestHandled(
            request: $request,
            response: $response,
            exception: $throwable,
            server: $this->serverName
        ));
    }

    protected function getResponseForException(Throwable $throwable): ResponseInterface
    {
        return $this->container->make(SafeCaller::class)->call(function () use ($throwable) {
            return $this->exceptionHandlerDispatcher->dispatch($throwable, $this->exceptionHandlers);
        }, static function () {
            return (new Response())->withStatus(400);
        });
    }

    /**
     * Initialize PSR-7 Request and Response objects.
     */
    protected function initRequestAndResponse(SwooleRequest $request, SwooleResponse $response): array
    {
        [$psr7Request, $psr7Response] = parent::initRequestAndResponse($request, $response);

        if ($this->enableHttpMethodParameterOverride()) {
            $this->overrideHttpMethod($psr7Request);
        }

        return [$psr7Request, $psr7Response];
    }

    /**
     * Determine if HTTP method parameter override is enabled.
     */
    protected function enableHttpMethodParameterOverride(): bool
    {
        if (isset($this->enableHttpMethodParameterOverride)) {
            return $this->enableHttpMethodParameterOverride;
        }

        return $this->enableHttpMethodParameterOverride = $this->container->make(Repository::class)
            ->get('view.enable_override_http_method', false);
    }

    /**
     * Override the HTTP method if the request contains a _method field.
     */
    protected function overrideHttpMethod(Request $psr7Request): void
    {
        if ($psr7Request->getMethod() === 'POST' && $method = $psr7Request->getParsedBody()['_method'] ?? null) {
            $psr7Request->setMethod(strtoupper($method));
        }
    }
}
