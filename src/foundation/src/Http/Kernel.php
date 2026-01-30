<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hypervel\Context\RequestContext;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;
use Hyperf\HttpMessage\Upload\UploadedFile as HyperfUploadedFile;
use Hyperf\HttpServer\Event\RequestHandled;
use Hyperf\HttpServer\Event\RequestReceived;
use Hyperf\HttpServer\Event\RequestTerminated;
use Hyperf\HttpServer\Server as HyperfServer;
use Hyperf\Support\SafeCaller;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Foundation\Exceptions\Handler as ExceptionHandler;
use Hypervel\Foundation\Http\Contracts\MiddlewareContract;
use Hypervel\Foundation\Http\Traits\HasMiddleware;
use Hypervel\Http\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Hyperf\Coroutine\defer;

class Kernel extends HyperfServer implements MiddlewareContract
{
    use HasMiddleware;

    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName = $serverName;
        $this->coreMiddleware = $this->createCoreMiddleware();

        $this->initExceptionHandlers();
        $this->initOption();
    }

    protected function initExceptionHandlers(): void
    {
        /* @phpstan-ignore-next-line */
        $this->exceptionHandlers = $this->container->bound(ExceptionHandlerContract::class)
            ? [ExceptionHandlerContract::class]
            : [ExceptionHandler::class];
    }

    public function onRequest($swooleRequest, $swooleResponse): void
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
        return $this->container->get(SafeCaller::class)->call(function () use ($throwable) {
            return $this->exceptionHandlerDispatcher->dispatch($throwable, $this->exceptionHandlers);
        }, static function () {
            return (new Response())->withStatus(400);
        });
    }
}
