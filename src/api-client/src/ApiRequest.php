<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Hypervel\ApiClient\Concerns\HasContext;
use Hypervel\Http\Client\Request as HttpClientRequest;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

class ApiRequest extends HttpClientRequest
{
    use HasContext;

    /**
     * Create an API request from an HTTP client request.
     */
    public static function createFrom(HttpClientRequest $request): static
    {
        $apiRequest = new static($request->toPsrRequest());
        $apiRequest->data = $request->data;
        $apiRequest->hasDecodedData = $request->hasDecodedData;
        $apiRequest->attributes = $request->attributes;

        return $apiRequest;
    }

    /**
     * Set the request method.
     */
    public function withMethod(string $method): static
    {
        $this->request = $this->request->withMethod($method);

        return $this;
    }

    /**
     * Set the request URL.
     */
    public function withUrl(callable|string $url, bool $preserveHost = false): static
    {
        if (! is_string($url)) {
            $url = $url((string) $this->request->getUri());
        }

        $this->request = $this->request->withUri(new Uri($url), $preserveHost);

        return $this;
    }

    /**
     * Add the request header.
     */
    public function withHeader(string $key, array|string $value): static
    {
        return $this->withHeaders([$key => $value]);
    }

    /**
     * Add the request headers.
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $key => $value) {
            $this->request = $this->request->withHeader($key, $value);
        }

        return $this;
    }

    /**
     * Specify the request's content type.
     */
    public function contentType(string $contentType): static
    {
        $this->withHeaders(['Content-Type' => $contentType]);

        return $this;
    }

    /**
     * Indicate the request contains form parameters.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function asForm(): static
    {
        $this->ensureStructuredMutationAllowed();
        $this->ensureStructuredBody();

        if (! $this->isForm()) {
            $this->data = $this->data();
            $this->hasDecodedData = true;
            $this->contentType('application/x-www-form-urlencoded');
            $this->applyChangedData();
        }

        return $this;
    }

    /**
     * Indicate the request contains JSON.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function asJson(): static
    {
        $this->ensureStructuredMutationAllowed();
        $this->ensureStructuredBody();

        if (! $this->isJson()) {
            $this->data = $this->data();
            $this->hasDecodedData = true;
            $this->contentType('application/json');
            $this->applyChangedData();
        }

        return $this;
    }

    /**
     * Indicate that JSON should be returned by the server.
     */
    public function acceptJson(): static
    {
        return $this->accept('application/json');
    }

    /**
     * Indicate the type of content that should be returned by the server.
     */
    public function accept(string $contentType): static
    {
        return $this->withHeaders(['Accept' => $contentType]);
    }

    /**
     * Specify an authorization token for the request.
     */
    public function withToken(#[SensitiveParameter] string $token, string $type = 'Bearer'): static
    {
        return $this->withHeaders(['Authorization' => trim($type . ' ' . $token)]);
    }

    /**
     * Specify the user agent for the request.
     */
    public function withUserAgent(bool|string $userAgent): static
    {
        return $this->withHeaders(['User-Agent' => trim((string) $userAgent)]);
    }

    /**
     * Add a request header.
     */
    public function withAddedHeader(string $key, array|string $value): static
    {
        return $this->withAddedHeaders([$key => $value]);
    }

    /**
     * Add request headers.
     */
    public function withAddedHeaders(array $headers): static
    {
        foreach ($headers as $key => $value) {
            $this->request = $this->request->withAddedHeader($key, $value);
        }

        return $this;
    }

    /**
     * Remove a request header.
     */
    public function withoutHeader(string $header): static
    {
        return $this->withoutHeaders([$header]);
    }

    /**
     * Remove request headers.
     */
    public function withoutHeaders(array $headers): static
    {
        foreach ($headers as $header) {
            $this->request = $this->request->withoutHeader($header);
        }

        return $this;
    }

    /**
     * Set the request body.
     */
    public function withBody(string $body): static
    {
        $this->replaceBody($body);
        $this->data = [];
        $this->hasDecodedData = false;

        return $this;
    }

    /**
     * Replace the request data.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function withData(array $data): static
    {
        $this->ensureStructuredMutationAllowed();

        $this->data = $data;
        $this->hasDecodedData = true;
        $this->applyChangedData();

        return $this;
    }

    /**
     * Merge the request data.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function mergeData(array $data): static
    {
        $this->ensureStructuredMutationAllowed();

        $this->data = array_merge($this->data(), $data);
        $this->hasDecodedData = true;
        $this->applyChangedData();

        return $this;
    }

    /**
     * Remove keys from the request data.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function withoutData(array|string $keys): static
    {
        $this->ensureStructuredMutationAllowed();

        $this->data = Arr::except($this->data(), $keys);
        $this->hasDecodedData = true;
        $this->applyChangedData();

        return $this;
    }

    /**
     * Get the underlying PSR compliant request instance.
     */
    public function toPsrRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * Apply changed structured data to the request body.
     *
     * @throws JsonException
     */
    protected function applyChangedData(): void
    {
        if ($this->isForm()) {
            $body = http_build_query($this->data, '', '&');
        } else {
            $body = json_encode($this->data, JSON_THROW_ON_ERROR);

            if (! $this->hasHeader('Content-Type')) {
                $this->contentType('application/json');
            }
        }

        $this->replaceBody($body);
    }

    /**
     * Ensure structured data may be changed for the request.
     *
     * @throws InvalidArgumentException
     */
    protected function ensureStructuredMutationAllowed(): void
    {
        if (in_array(strtoupper($this->method()), ['GET', 'HEAD'], true)) {
            throw new InvalidArgumentException(
                'Structured request data cannot be changed for GET or HEAD requests. Use withQuery() or withoutQuery() instead.'
            );
        }

        $this->ensureStructuredBody();
    }

    /**
     * Ensure the request body has a supported structured representation.
     *
     * @throws InvalidArgumentException
     */
    protected function ensureStructuredBody(): void
    {
        if ($this->isJson() || $this->isForm()) {
            return;
        }

        if ($this->hasHeader('Content-Type') || $this->body() !== '') {
            throw new InvalidArgumentException('The request body does not contain structured JSON or form data.');
        }
    }

    /**
     * Replace the request body and update its framing headers.
     */
    protected function replaceBody(string $body): void
    {
        $request = $this->request->withBody(Utils::streamFor($body));

        if ($request->hasHeader('Transfer-Encoding')) {
            $request = $request->withoutHeader('Content-Length');
        } else {
            $request = $request->withHeader('Content-Length', (string) strlen($body));
        }

        $this->request = $request;
    }
}
