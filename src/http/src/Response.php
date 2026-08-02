<?php

declare(strict_types=1);

namespace Hypervel\Http;

use ArrayObject;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Contracts\Support\Renderable;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use JsonSerializable;
use Override;
use RuntimeException;
use Stringable;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class Response extends SymfonyResponse
{
    use Macroable {
        Macroable::__call as macroCall;
    }
    use ResponseTrait;

    /**
     * Create a new HTTP response.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(mixed $content = '', int $status = 200, array $headers = [])
    {
        // The parent constructor accepts the headers since Symfony 8.1; assigning
        // the property directly would hit the deprecated property setter.
        parent::__construct('', $status, $headers);

        $this->setContent($content);
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

        // Laravel gets this coercion from weak mode; reproduce it explicitly before Symfony's string setter.
        if (! is_string($content) && $content !== null
            && (is_scalar($content) || $content instanceof Stringable)) {
            $content = (string) $content;
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

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
