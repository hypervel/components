<?php

declare(strict_types=1);

namespace Hypervel\Http;

use DateTimeImmutable;
use Hyperf\Codec\Json;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Context\RequestContext;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Jsonable;
use Hyperf\HttpMessage\Server\Chunk\Chunkable;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Response as HyperfResponse;
use Hyperf\Support\Filesystem\Filesystem;
use Hyperf\View\RenderInterface;
use Hypervel\Http\Contracts\ResponseContract;
use Hypervel\Http\Exceptions\FileNotFoundException;
use Hypervel\Support\Collection;
use Hypervel\Support\MimeTypeExtensionGuesser;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class Response extends HyperfResponse implements ResponseContract
{
    public const HTTP_CONTINUE = 100;

    public const HTTP_SWITCHING_PROTOCOLS = 101;

    public const HTTP_PROCESSING = 102; // RFC2518

    public const HTTP_EARLY_HINTS = 103; // RFC8297

    public const HTTP_OK = 200;

    public const HTTP_CREATED = 201;

    public const HTTP_ACCEPTED = 202;

    public const HTTP_NON_AUTHORITATIVE_INFORMATION = 203;

    public const HTTP_NO_CONTENT = 204;

    public const HTTP_RESET_CONTENT = 205;

    public const HTTP_PARTIAL_CONTENT = 206;

    public const HTTP_MULTI_STATUS = 207; // RFC4918

    public const HTTP_ALREADY_REPORTED = 208; // RFC5842

    public const HTTP_IM_USED = 226; // RFC3229

    public const HTTP_MULTIPLE_CHOICES = 300;

    public const HTTP_MOVED_PERMANENTLY = 301;

    public const HTTP_FOUND = 302;

    public const HTTP_SEE_OTHER = 303;

    public const HTTP_NOT_MODIFIED = 304;

    public const HTTP_USE_PROXY = 305;

    public const HTTP_RESERVED = 306;

    public const HTTP_TEMPORARY_REDIRECT = 307;

    public const HTTP_PERMANENTLY_REDIRECT = 308; // RFC7238

    public const HTTP_BAD_REQUEST = 400;

    public const HTTP_UNAUTHORIZED = 401;

    public const HTTP_PAYMENT_REQUIRED = 402;

    public const HTTP_FORBIDDEN = 403;

    public const HTTP_NOT_FOUND = 404;

    public const HTTP_METHOD_NOT_ALLOWED = 405;

    public const HTTP_NOT_ACCEPTABLE = 406;

    public const HTTP_PROXY_AUTHENTICATION_REQUIRED = 407;

    public const HTTP_REQUEST_TIMEOUT = 408;

    public const HTTP_CONFLICT = 409;

    public const HTTP_GONE = 410;

    public const HTTP_LENGTH_REQUIRED = 411;

    public const HTTP_PRECONDITION_FAILED = 412;

    public const HTTP_REQUEST_ENTITY_TOO_LARGE = 413;

    public const HTTP_REQUEST_URI_TOO_LONG = 414;

    public const HTTP_UNSUPPORTED_MEDIA_TYPE = 415;

    public const HTTP_REQUESTED_RANGE_NOT_SATISFIABLE = 416;

    public const HTTP_EXPECTATION_FAILED = 417;

    public const HTTP_I_AM_A_TEAPOT = 418; // RFC2324

    public const HTTP_MISDIRECTED_REQUEST = 421; // RFC7540

    public const HTTP_UNPROCESSABLE_ENTITY = 422; // RFC4918

    public const HTTP_LOCKED = 423; // RFC4918

    public const HTTP_FAILED_DEPENDENCY = 424; // RFC4918

    public const HTTP_TOO_EARLY = 425; // RFC-ietf-httpbis-replay-04

    public const HTTP_UPGRADE_REQUIRED = 426; // RFC2817

    public const HTTP_PRECONDITION_REQUIRED = 428; // RFC6585

    public const HTTP_TOO_MANY_REQUESTS = 429; // RFC6585

    public const HTTP_REQUEST_HEADER_FIELDS_TOO_LARGE = 431; // RFC6585

    public const HTTP_UNAVAILABLE_FOR_LEGAL_REASONS = 451; // RFC7725

    public const HTTP_INTERNAL_SERVER_ERROR = 500;

    public const HTTP_NOT_IMPLEMENTED = 501;

    public const HTTP_BAD_GATEWAY = 502;

    public const HTTP_SERVICE_UNAVAILABLE = 503;

    public const HTTP_GATEWAY_TIMEOUT = 504;

    public const HTTP_VERSION_NOT_SUPPORTED = 505;

    public const HTTP_VARIANT_ALSO_NEGOTIATES_EXPERIMENTAL = 506; // RFC2295

    public const HTTP_INSUFFICIENT_STORAGE = 507; // RFC4918

    public const HTTP_LOOP_DETECTED = 508; // RFC5842

    public const HTTP_NOT_EXTENDED = 510; // RFC2774

    public const HTTP_NETWORK_AUTHENTICATION_REQUIRED = 511; // RFC6585

    /**
     * The context key for range headers.
     */
    public const RANGE_HEADERS_CONTEXT = '_response.withRangeHeaders';

    /**
     * Create a new response instance.
     */
    public function make(mixed $content = '', int $status = 200, array $headers = []): ResponseInterface
    {
        $response = $this->getResponse()->withStatus($status);
        foreach ($headers as $name => $value) {
            $response->addHeader($name, $value);
        }
        if (is_array($content) || $content instanceof Arrayable) {
            return $response->addHeader('Content-Type', 'application/json')
                ->setBody(new SwooleStream(Json::encode($content)));
        }

        if ($content instanceof Jsonable) {
            return $response->addHeader('Content-Type', 'application/json')
                ->setBody(new SwooleStream((string) $content));
        }

        if ($response->hasHeader('Content-Type')) {
            return $response->setBody(new SwooleStream((string) $content));
        }

        return $response->addHeader('Content-Type', 'text/plain')
            ->setBody(new SwooleStream((string) $content));
    }

    /**
     * Create a new "no content" response.
     */
    public function noContent(int $status = 204, array $headers = []): ResponseInterface
    {
        $response = $this->getResponse()->withStatus($status);
        foreach ($headers as $name => $value) {
            $response->addHeader($name, $value);
        }

        return $response;
    }

    /**
     * Create a new response for a given view.
     */
    public function view(string $view, array $data = [], int $status = 200, array $headers = []): ResponseInterface
    {
        $response = ApplicationContext::getContainer()
            ->get(RenderInterface::class)
            ->render($view, $data);

        foreach ($headers as $name => $value) {
            $response = $response->withAddedHeader($name, $value);
        }

        return $response->withStatus($status);
    }

    /**
     * Format data to JSON and return data with Content-Type:application/json header.
     *
     * @param array|Arrayable|Jsonable $data
     */
    public function json($data, int $status = 200, array $headers = []): ResponseInterface
    {
        $response = parent::json($data);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withStatus($status);
    }

    /**
     * Create a file response by file path.
     */
    public function file(string $path, array $headers = []): ResponseInterface
    {
        $filesystem = ApplicationContext::getContainer()
            ->get(Filesystem::class);
        if (! $filesystem->isFile($path)) {
            throw new FileNotFoundException($path);
        }

        $mime = Collection::make($headers)
            ->where(function ($value, $key) {
                return strtolower($key) === 'content-type';
            })->first();
        if (! $mime) {
            $mime = ApplicationContext::getContainer()
                ->get(MimeTypeExtensionGuesser::class)
                ->guessMimeType(
                    pathinfo($path, PATHINFO_EXTENSION)
                ) ?: 'application/octet-stream';
            $headers['Content-Type'] = $mime;
        }

        return $this->make($filesystem->get($path), 200, $headers);
    }

    /**
     * Get original psr7 response instance.
     */
    public function getPsr7Response(): ResponseInterface
    {
        return $this->getResponse();
    }

    /**
     * Create a streamed response.
     *
     * @param callable $callback Callback that will be handled for streaming
     * @param array $headers Additional headers for the response
     */
    public function stream(callable $callback, array $headers = []): ResponseInterface
    {
        $response = $this->getResponse();
        if (! $response instanceof Chunkable) {
            throw new RuntimeException('The response is not a chunkable response.');
        }

        foreach ($headers as $key => $value) {
            $response->addHeader($key, $value);
        }

        if (! $response->hasHeader('Content-Type')) {
            $response->setHeader('Content-Type', 'text/event-stream');
        }

        if ($this->shouldAppendRangeHeaders()) {
            $this->appendRangeHeaders();
        }

        $ignoreHeaders = ['Transfer-Encoding', 'Accept-Encoding', 'Content-Length'];
        foreach ($ignoreHeaders as $header) {
            if ($response->hasHeader($header)) {
                $response->unsetHeader($header);
            }
        }

        // Because response emitter sent response in the end of request lifecycle,
        // we need to send headers and status manually before writing content.
        /* @phpstan-ignore-next-line */
        if ($responseConnection = $this->getConnection()) {
            $swooleResponse = $responseConnection->getSocket();
            foreach ($response->getHeaders() as $key => $value) {
                $swooleResponse->header($key, $value);
            }
            $swooleResponse->status($response->getStatusCode(), $response->getReasonPhrase());
        }

        $output = new StreamOutput($response);
        if (! is_null($result = $callback($output))) {
            $output->write($result);
        }

        return $response;
    }

    /**
     * Create a streamed download response.
     *
     * @param callable $callback Callback that will be handled for streaming download
     * @param array $headers Additional headers for the response
     * @param string $disposition Content-Disposition type (attachment or inline)
     */
    public function streamDownload(callable $callback, ?string $filename = null, array $headers = [], string $disposition = 'attachment'): ResponseInterface
    {
        $downloadHeaders = [
            'Content-Type' => 'application/octet-stream',
            'Content-Description' => 'File Transfer',
            'Pragma' => 'no-cache',
        ];
        if ($filename) {
            $downloadHeaders['Content-Disposition'] = HeaderUtils::makeDisposition($disposition, $filename);
        }

        foreach ($headers as $key => $value) {
            $downloadHeaders[$key] = $value;
        }

        return $this->stream($callback, $downloadHeaders);
    }

    /**
     * Enable range headers for the response.
     */
    public function withRangeHeaders(?int $fileSize = null): static
    {
        Context::set(static::RANGE_HEADERS_CONTEXT, [
            'fileSize' => $fileSize,
        ]);

        return $this;
    }

    /**
     * Disable range headers for the response.
     */
    public function withoutRangeHeaders(): static
    {
        Context::destroy(static::RANGE_HEADERS_CONTEXT);

        return $this;
    }

    /**
     * Determine if the response should append range headers.
     */
    public function shouldAppendRangeHeaders(): bool
    {
        return Context::has(static::RANGE_HEADERS_CONTEXT);
    }

    /**
     * Pull range headers from the context.
     */
    protected function pullRangeHeaders(): ?array
    {
        $context = Context::get(static::RANGE_HEADERS_CONTEXT, null);

        $this->withoutRangeHeaders();

        return $context;
    }

    /**
     * Append range headers to the response.
     */
    protected function appendRangeHeaders(): ResponseInterface
    {
        $rangeHeaders = $this->pullRangeHeaders();
        $request = RequestContext::get();
        $response = $this->getResponse();

        if (! $response->hasHeader('Accept-Ranges')) {
            // Only accept ranges on safe HTTP methods
            $isMethodSafe = in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE']);
            $response->addHeader('Accept-Ranges', $isMethodSafe ? 'bytes' : 'none');
        }

        if (! $request->hasHeader('Range') || $request->getMethod() !== 'GET') {
            return $response;
        }

        if ($request->hasHeader('If-Range') && ! $this->hasValidIfRangeHeader($request->getHeader('If-Range')[0] ?? null)) {
            return $response;
        }

        $range = $request->getHeader('Range')[0] ?? null;
        if (! str_starts_with($range, 'bytes=')) {
            return $response;
        }

        // Process the range headers.
        $fileSize = $rangeHeaders['fileSize'] ?? null;
        [$start, $end] = HeaderUtils::validateRangeHeaders($range, $fileSize);

        $response->setStatus(static::HTTP_PARTIAL_CONTENT);

        // Set Content-Range
        $rangeEnd = $end !== null ? $end : '*';
        $totalSize = $fileSize !== null ? $fileSize : '*';
        $response->setHeader('Content-Range', sprintf('bytes %d-%s/%s', $start, $rangeEnd, $totalSize));

        return $response;
    }

    protected function hasValidIfRangeHeader(?string $header): bool
    {
        $response = $this->getResponse();

        $etag = $response->getHeader('ETag')[0] ?? null;
        if ($etag === $header) {
            return true;
        }

        $lastModified = $response->getHeader('Last-Modified')[0] ?? null;
        if (is_null($lastModified)) {
            return false;
        }

        $lastModified = DateTimeImmutable::createFromFormat(DATE_RFC2822, $lastModified);

        return $lastModified->format('D, d M Y H:i:s') . ' GMT' === $header;
    }
}
