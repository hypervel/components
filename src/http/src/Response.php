<?php

declare(strict_types=1);

namespace Hypervel\Http;

use ArrayObject;
use Hypervel\Contracts\Engine\Http\Writable;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Contracts\Support\Renderable;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use JsonSerializable;
use Override;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class Response extends SymfonyResponse
{
    use Macroable {
        Macroable::__call as macroCall;
    }
    use ResponseTrait;

    /**
     * Whether the response was already streamed directly to the client.
     */
    private bool $streamed = false;

    /**
     * The writable connection for direct Swoole streaming.
     */
    protected ?Writable $connection = null;

    /**
     * Create a new HTTP response.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(mixed $content = '', int $status = 200, array $headers = [])
    {
        $this->headers = new ResponseHeaderBag($headers);

        $this->setContent($content);
        $this->setStatusCode($status);
        $this->setProtocolVersion('1.0');
    }

    /**
     * Get the response content.
     */
    #[Override]
    public function getContent(): string|false
    {
        return transform(parent::getContent(), fn ($content) => $content, '');
    }

    /**
     * Set the content on the response.
     *
     * @throws InvalidArgumentException
     */
    #[Override]
    public function setContent(mixed $content): static
    {
        $this->original = $content;

        // If the content is "JSONable" we will set the appropriate header and convert
        // the content to JSON. This is useful when returning something like models
        // from routes that will be automatically transformed to their JSON form.
        if ($this->shouldBeJson($content)) {
            $this->header('Content-Type', 'application/json');

            $content = $this->morphToJson($content);

            if ($content === false) {
                throw new InvalidArgumentException(json_last_error_msg());
            }
        }

        // If this content implements the "Renderable" interface then we will call the
        // render method on the object so we will avoid any "__toString" exceptions
        // that might be thrown and have their errors obscured by PHP's handling.
        elseif ($content instanceof Renderable) {
            $content = $content->render();
        }

        parent::setContent($content);

        return $this;
    }

    /**
     * Determine if the given content should be turned into JSON.
     */
    protected function shouldBeJson(mixed $content): bool
    {
        return $content instanceof Arrayable
               || $content instanceof Jsonable
               || $content instanceof ArrayObject
               || $content instanceof JsonSerializable
               || is_array($content);
    }

    /**
     * Morph the given content into JSON.
     */
    protected function morphToJson(mixed $content): string|false
    {
        if ($content instanceof Jsonable) {
            return $content->toJson();
        }
        if ($content instanceof Arrayable) {
            return json_encode($content->toArray());
        }

        return json_encode($content);
    }

    /**
     * Mark the response as already streamed to the client.
     *
     * Called by stream() and streamDownload() immediately after sending
     * headers/status to Swoole, before invoking the user's callback.
     * This ensures the flag is set even if the callback throws after
     * partial writes.
     */
    public function markStreamed(): void
    {
        $this->streamed = true;
    }

    /**
     * Determine if the response was already streamed to the client.
     *
     * Used by ResponseBridge to detect responses that were sent via
     * the direct Swoole write path (stream()/streamDownload()).
     */
    public function isStreamed(): bool
    {
        return $this->streamed;
    }

    /**
     * Set the writable connection for direct Swoole streaming.
     */
    public function setConnection(Writable $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Get the writable connection for direct Swoole streaming.
     */
    public function getConnection(): ?Writable
    {
        return $this->connection;
    }

    /**
     * Create a streamed response.
     *
     * Streams content directly to the client via the Swoole socket,
     * bypassing the normal response emission path. Each chunk written
     * via the StreamOutput reaches the client immediately.
     *
     * @param callable $callback Callback that receives a StreamOutput for writing chunks
     * @param array $headers Additional headers for the response
     */
    public function stream(callable $callback, array $headers = []): static
    {
        foreach ($headers as $key => $value) {
            $this->headers->set($key, $value);
        }

        if (! $this->headers->has('Content-Type')) {
            $this->headers->set('Content-Type', 'text/event-stream');
        }

        // Remove headers that conflict with chunked transfer encoding
        $this->headers->remove('Transfer-Encoding');
        $this->headers->remove('Accept-Encoding');
        $this->headers->remove('Content-Length');

        $connection = $this->getConnection();

        if ($connection === null) {
            throw new RuntimeException('Cannot stream response without a writable connection.');
        }

        // Send headers and status directly via Swoole before streaming
        $swooleResponse = $connection->getSocket();
        foreach ($this->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                $swooleResponse->header($name, $value);
            }
        }
        foreach ($this->headers->getCookies() as $cookie) {
            $swooleResponse->cookie(
                $cookie->getName(),
                $cookie->getValue() ?? '',
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain() ?? '',
                $cookie->isSecure(),
                $cookie->isHttpOnly(),
                $cookie->getSameSite() ?? ''
            );
        }
        $swooleResponse->status($this->getStatusCode());

        // Mark as streamed NOW — headers are committed to Swoole, so the bridge
        // must not re-send even if the callback throws after partial writes.
        $this->markStreamed();

        $output = new StreamOutput($connection);
        if (! is_null($result = $callback($output))) {
            $output->write($result);
        }

        return $this;
    }

    /**
     * Create a streamed download response.
     *
     * @param callable $callback Callback that receives a StreamOutput for writing chunks
     * @param null|string $filename Filename for the Content-Disposition header
     * @param array $headers Additional headers for the response
     * @param string $disposition Content-Disposition type (attachment or inline)
     */
    public function streamDownload(callable $callback, ?string $filename = null, array $headers = [], string $disposition = 'attachment'): static
    {
        $downloadHeaders = [
            'Content-Type' => 'application/octet-stream',
            'Content-Description' => 'File Transfer',
            'Pragma' => 'no-cache',
        ];

        if ($filename) {
            $downloadHeaders['Content-Disposition'] = $this->headers->makeDisposition($disposition, $filename);
        }

        foreach ($headers as $key => $value) {
            $downloadHeaders[$key] = $value;
        }

        return $this->stream($callback, $downloadHeaders);
    }

    /**
     * Send HTTP headers.
     *
     * @throws RuntimeException always — Swoole manages headers via its own API
     */
    #[Override]
    public function sendHeaders(?int $statusCode = null): static
    {
        throw new RuntimeException('Response::sendHeaders() is not supported in Hypervel. Responses are emitted through Swoole\'s response API.');
    }

    /**
     * Send response content.
     *
     * @throws RuntimeException always — Swoole has no SAPI output stream
     */
    #[Override]
    public function sendContent(): static
    {
        throw new RuntimeException('Response::sendContent() is not supported in Hypervel. Responses are emitted through Swoole\'s response API.');
    }

    /**
     * Send HTTP headers and content.
     *
     * @throws RuntimeException always — Swoole manages response emission
     */
    #[Override]
    public function send(bool $flush = true): static
    {
        throw new RuntimeException('Response::send() is not supported in Hypervel. Responses are emitted through Swoole\'s response API.');
    }
}
