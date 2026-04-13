<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Routing\ResponseFactory as ResponseFactoryContract;

/**
 * @method static \Hypervel\Http\Response make(array|string $content = '', int $status = 200, array $headers = [])
 * @method static \Hypervel\Http\Response noContent(int $status = 204, array $headers = [])
 * @method static \Hypervel\Http\Response view(string|array $view, array $data = [], int $status = 200, array $headers = [])
 * @method static \Hypervel\Http\JsonResponse json(mixed $data = [], int $status = 200, array $headers = [], int $options = 0)
 * @method static \Hypervel\Http\JsonResponse jsonp(string $callback, mixed $data = [], int $status = 200, array $headers = [], int $options = 0)
 * @method static \Symfony\Component\HttpFoundation\StreamedResponse eventStream(\Closure $callback, array $headers = [], \Hypervel\Http\StreamedEvent|string|null $endStreamWith = '</stream>')
 * @method static \Symfony\Component\HttpFoundation\StreamedResponse stream(callable|null $callback = null, int $status = 200, array $headers = [])
 * @method static \Symfony\Component\HttpFoundation\StreamedJsonResponse streamJson(array $data, int $status = 200, array $headers = [], int $encodingOptions = 15)
 * @method static \Symfony\Component\HttpFoundation\StreamedResponse streamDownload(callable $callback, string|null $name = null, array $headers = [], string $disposition = 'attachment')
 * @method static \Symfony\Component\HttpFoundation\BinaryFileResponse download(\SplFileInfo|string $file, string|null $name = null, array $headers = [], string $disposition = 'attachment')
 * @method static \Symfony\Component\HttpFoundation\BinaryFileResponse file(\SplFileInfo|string $file, array $headers = [])
 * @method static \Hypervel\Http\RedirectResponse redirectTo(string $path, int $status = 302, array $headers = [], bool|null $secure = null)
 * @method static \Hypervel\Http\RedirectResponse redirectToRoute(\BackedEnum|string $route, mixed $parameters = [], int $status = 302, array $headers = [])
 * @method static \Hypervel\Http\RedirectResponse redirectToAction(array|string $action, mixed $parameters = [], int $status = 302, array $headers = [])
 * @method static \Hypervel\Http\RedirectResponse redirectGuest(string $path, int $status = 302, array $headers = [], bool|null $secure = null)
 * @method static \Hypervel\Http\RedirectResponse redirectToIntended(string $default = '/', int $status = 302, array $headers = [], bool|null $secure = null)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see \Hypervel\Routing\ResponseFactory
 */
class Response extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ResponseFactoryContract::class;
    }
}
