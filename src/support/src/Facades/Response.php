<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Http\Contracts\ResponseContract;

/**
 * @method static \Psr\Http\Message\ResponseInterface make(mixed $content = '', int $status = 200, array $headers = [])
 * @method static \Psr\Http\Message\ResponseInterface noContent(int $status = 204, array $headers = [])
 * @method static \Psr\Http\Message\ResponseInterface view(string $view, array $data = [], int $status = 200, array $headers = [])
 * @method static \Psr\Http\Message\ResponseInterface json(array|\Hypervel\Contracts\Support\Arrayable|\Hypervel\Contracts\Support\Jsonable $data, int $status = 200, array $headers = [])
 * @method static \Psr\Http\Message\ResponseInterface file(string $path, array $headers = [])
 * @method static \Psr\Http\Message\ResponseInterface getPsr7Response()
 * @method static \Psr\Http\Message\ResponseInterface stream(callable $callback, array $headers = [])
 * @method static \Psr\Http\Message\ResponseInterface streamDownload(callable $callback, string|null $filename = null, array $headers = [], string $disposition = 'attachment')
 * @method static \Hypervel\Http\Response withRangeHeaders(int|null $fileSize = null)
 * @method static \Hypervel\Http\Response withoutRangeHeaders()
 * @method static bool shouldAppendRangeHeaders()
 * @method static \Psr\Http\Message\ResponseInterface xml(array|\Hypervel\Contracts\Support\Arrayable|\Hyperf\Contract\Xmlable $data, string $root = 'root', string $charset = 'utf-8')
 * @method static \Psr\Http\Message\ResponseInterface html(string $html, string $charset = 'utf-8')
 * @method static \Psr\Http\Message\ResponseInterface raw(mixed|\Stringable $data, string $charset = 'utf-8')
 * @method static \Psr\Http\Message\ResponseInterface redirect(string $toUrl, int $status = 302, string $schema = 'http')
 * @method static \Psr\Http\Message\ResponseInterface download(string $file, string $name = '')
 * @method static \Hyperf\HttpServer\Contract\ResponseInterface withCookie(\Hyperf\HttpMessage\Cookie\Cookie $cookie)
 * @method static string getProtocolVersion()
 * @method static \Psr\Http\Message\ResponseInterface withProtocolVersion(string $version)
 * @method static string[][] getHeaders()
 * @method static bool hasHeader(string $name)
 * @method static string[] getHeader(string $name)
 * @method static string getHeaderLine(string $name)
 * @method static \Psr\Http\Message\ResponseInterface withHeader(string $name, string|string[] $value)
 * @method static \Psr\Http\Message\ResponseInterface withAddedHeader(string $name, string|string[] $value)
 * @method static \Psr\Http\Message\ResponseInterface withoutHeader(string $name)
 * @method static \Psr\Http\Message\StreamInterface getBody()
 * @method static \Psr\Http\Message\ResponseInterface withBody(\Psr\Http\Message\StreamInterface $body)
 * @method static int getStatusCode()
 * @method static \Psr\Http\Message\ResponseInterface withStatus(int $code, string $reasonPhrase = '')
 * @method static string getReasonPhrase()
 * @method static bool write(string $data)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 *
 * @see \Hypervel\Http\Response
 */
class Response extends Facade
{
    protected static function getFacadeAccessor()
    {
        return ResponseContract::class;
    }
}
