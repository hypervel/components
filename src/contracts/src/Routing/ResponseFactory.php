<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Routing;

use BackedEnum;
use Closure;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Response;
use Hypervel\Http\StreamedEvent;
use SplFileInfo;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface ResponseFactory
{
    /**
     * Create a new response instance.
     */
    public function make(mixed $content = '', int $status = 200, array $headers = []): Response;

    /**
     * Create a new "no content" response.
     */
    public function noContent(int $status = 204, array $headers = []): Response;

    /**
     * Create a new response for a given view.
     */
    public function view(array|string $view, array $data = [], int $status = 200, array $headers = []): Response;

    /**
     * Create a new JSON response instance.
     */
    public function json(mixed $data = [], int $status = 200, array $headers = [], int $options = 0): JsonResponse;

    /**
     * Create a new JSONP response instance.
     */
    public function jsonp(string $callback, mixed $data = [], int $status = 200, array $headers = [], int $options = 0): JsonResponse;

    /**
     * Create a new event stream response.
     */
    public function eventStream(Closure $callback, array $headers = [], StreamedEvent|string|null $endStreamWith = '</stream>'): StreamedResponse;

    /**
     * Create a new streamed response instance.
     */
    public function stream(callable $callback, int $status = 200, array $headers = []): StreamedResponse;

    /**
     * Create a new streamed JSON response instance.
     */
    public function streamJson(array $data, int $status = 200, array $headers = [], int $encodingOptions = 15): StreamedJsonResponse;

    /**
     * Create a new streamed response instance as a file download.
     */
    public function streamDownload(callable $callback, ?string $name = null, array $headers = [], string $disposition = 'attachment'): StreamedResponse;

    /**
     * Create a new file download response.
     */
    public function download(SplFileInfo|string $file, ?string $name = null, array $headers = [], string $disposition = 'attachment'): BinaryFileResponse;

    /**
     * Return the raw contents of a binary file.
     */
    public function file(SplFileInfo|string $file, array $headers = []): BinaryFileResponse;

    /**
     * Create a new redirect response to the given path.
     */
    public function redirectTo(string $path, int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse;

    /**
     * Create a new redirect response to a named route.
     */
    public function redirectToRoute(BackedEnum|string $route, mixed $parameters = [], int $status = 302, array $headers = []): RedirectResponse;

    /**
     * Create a new redirect response to a controller action.
     */
    public function redirectToAction(array|string $action, mixed $parameters = [], int $status = 302, array $headers = []): RedirectResponse;

    /**
     * Create a new redirect response, while putting the current URL in the session.
     */
    public function redirectGuest(string $path, int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse;

    /**
     * Create a new redirect response to the previously intended location.
     */
    public function redirectToIntended(string $default = '/', int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse;
}
