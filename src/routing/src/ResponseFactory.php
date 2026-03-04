<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use BackedEnum;
use Closure;
use Hypervel\Contracts\Routing\ResponseFactory as FactoryContract;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Response;
use Hypervel\Http\StreamedEvent;
use Hypervel\Routing\Exceptions\StreamedResponseException;
use Hypervel\Support\Js;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use ReflectionFunction;
use SplFileInfo;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ResponseFactory implements FactoryContract
{
    use Macroable;

    /**
     * The view factory instance.
     */
    protected ViewFactory $view;

    /**
     * The redirector instance.
     */
    protected Redirector $redirector;

    /**
     * Create a new response factory instance.
     */
    public function __construct(ViewFactory $view, Redirector $redirector)
    {
        $this->view = $view;
        $this->redirector = $redirector;
    }

    /**
     * Create a new response instance.
     */
    public function make(mixed $content = '', int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }

    /**
     * Create a new "no content" response.
     */
    public function noContent(int $status = 204, array $headers = []): Response
    {
        return $this->make('', $status, $headers);
    }

    /**
     * Create a new response for a given view.
     */
    public function view(array|string $view, array $data = [], int $status = 200, array $headers = []): Response
    {
        if (is_array($view)) {
            return $this->make($this->view->first($view, $data), $status, $headers);
        }

        return $this->make($this->view->make($view, $data), $status, $headers);
    }

    /**
     * Create a new JSON response instance.
     */
    public function json(mixed $data = [], int $status = 200, array $headers = [], int $options = 0): JsonResponse
    {
        return new JsonResponse($data, $status, $headers, $options);
    }

    /**
     * Create a new JSONP response instance.
     */
    public function jsonp(string $callback, mixed $data = [], int $status = 200, array $headers = [], int $options = 0): JsonResponse
    {
        return $this->json($data, $status, $headers, $options)->setCallback($callback);
    }

    /**
     * Create a new event stream response.
     */
    public function eventStream(Closure $callback, array $headers = [], StreamedEvent|string|null $endStreamWith = '</stream>'): StreamedResponse
    {
        return $this->stream(function () use ($callback, $endStreamWith) {
            foreach ($callback() as $message) {
                if (connection_aborted()) {
                    break;
                }

                $event = 'update';

                if ($message instanceof StreamedEvent) {
                    $event = $message->event;
                    $message = $message->data;
                }

                if (! is_string($message) && ! is_numeric($message)) {
                    $message = Js::encode($message);
                }

                echo "event: {$event}\n";
                echo 'data: ' . $message;
                echo "\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }

            if (filled($endStreamWith)) {
                $endEvent = 'update';

                if ($endStreamWith instanceof StreamedEvent) {
                    $endEvent = $endStreamWith->event;
                    $endStreamWith = $endStreamWith->data;
                }

                echo "event: {$endEvent}\n";
                echo 'data: ' . $endStreamWith;
                echo "\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        }, 200, array_merge($headers, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]));
    }

    /**
     * Create a new streamed response instance.
     *
     * For generator callbacks, the callback is set directly on the StreamedResponse
     * without wrapping in a foreach+echo loop. In Swoole's long-lived workers,
     * the ResponseBridge handles streaming via ob_start + $swooleResponse->write().
     */
    public function stream(?callable $callback = null, int $status = 200, array $headers = []): StreamedResponse
    {
        if (! is_null($callback) && (new ReflectionFunction($callback))->isGenerator()) {
            return (new StreamedResponse(
                null,
                $status,
                array_merge($headers, ['X-Accel-Buffering' => 'no'])
            ))->setCallback($callback);
        }

        return new StreamedResponse($callback, $status, $headers);
    }

    /**
     * Create a new streamed JSON response instance.
     */
    public function streamJson(array $data, int $status = 200, array $headers = [], int $encodingOptions = JsonResponse::DEFAULT_ENCODING_OPTIONS): StreamedJsonResponse
    {
        return new StreamedJsonResponse($data, $status, $headers, $encodingOptions);
    }

    /**
     * Create a new streamed response instance as a file download.
     *
     * @throws \Hypervel\Routing\Exceptions\StreamedResponseException
     */
    public function streamDownload(callable $callback, ?string $name = null, array $headers = [], string $disposition = 'attachment'): StreamedResponse
    {
        $withWrappedException = function () use ($callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                throw new StreamedResponseException($e);
            }
        };

        $response = new StreamedResponse($withWrappedException, 200, $headers);

        if (! is_null($name)) {
            $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
                $disposition,
                $name,
                $this->fallbackName($name)
            ));
        }

        return $response;
    }

    /**
     * Create a new file download response.
     */
    public function download(SplFileInfo|string $file, ?string $name = null, array $headers = [], string $disposition = 'attachment'): BinaryFileResponse
    {
        $response = new BinaryFileResponse($file, 200, $headers, true, $disposition);

        if (! is_null($name)) {
            return $response->setContentDisposition($disposition, $name, $this->fallbackName($name));
        }

        return $response;
    }

    /**
     * Convert the string to ASCII characters that are equivalent to the given name.
     */
    protected function fallbackName(string $name): string
    {
        return str_replace('%', '', Str::ascii($name));
    }

    /**
     * Return the raw contents of a binary file.
     */
    public function file(SplFileInfo|string $file, array $headers = []): BinaryFileResponse
    {
        return new BinaryFileResponse($file, 200, $headers);
    }

    /**
     * Create a new redirect response to the given path.
     */
    public function redirectTo(string $path, int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse
    {
        return $this->redirector->to($path, $status, $headers, $secure);
    }

    /**
     * Create a new redirect response to a named route.
     */
    public function redirectToRoute(BackedEnum|string $route, mixed $parameters = [], int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->redirector->route($route, $parameters, $status, $headers);
    }

    /**
     * Create a new redirect response to a controller action.
     */
    public function redirectToAction(array|string $action, mixed $parameters = [], int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->redirector->action($action, $parameters, $status, $headers);
    }

    /**
     * Create a new redirect response, while putting the current URL in the session.
     */
    public function redirectGuest(string $path, int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse
    {
        return $this->redirector->guest($path, $status, $headers, $secure);
    }

    /**
     * Create a new redirect response to the previously intended location.
     */
    public function redirectToIntended(string $default = '/', int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse
    {
        return $this->redirector->intended($default, $status, $headers, $secure);
    }
}
