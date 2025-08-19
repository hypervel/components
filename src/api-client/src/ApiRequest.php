<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use GuzzleHttp\Psr7\Uri;
use Hyperf\Engine\Http\Stream;
use Hypervel\HttpClient\Request as HttpClientRequest;
use Psr\Http\Message\RequestInterface;

class ApiRequest extends HttpClientRequest
{
    /**
     * Determine if the request data has changed.
     */
    protected bool $dataChanged = false;

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
        if (is_callable($url)) {
            $url = $url((string) $this->request->getUri());
        }

        $this->request = $this->request->withUri(new Uri($url), $preserveHost);

        return $this;
    }

    /**
     * Add the request header.
     */
    public function withHeader(string $key, string $value): static
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
     */
    public function asForm(): static
    {
        if ($this->isJson() || ! $this->hasHeader('Content-Type')) {
            if (! $this->data) {
                $this->json();
            }

            $this->dataChanged = true;
        }

        return $this->contentType('application/x-www-form-urlencoded');
    }

    /**
     * Indicate the request contains JSON.
     */
    public function asJson(): static
    {
        if ($this->isForm()) {
            if (! $this->data) {
                $this->parameters();
            }

            $this->dataChanged = true;
        }

        return $this->contentType('application/json');
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
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withHeaders(['Authorization' => trim($type . ' ' . $token)]);
    }

    /**
     * Specify the user agent for the request.
     */
    public function withUserAgent(bool|string $userAgent): static
    {
        return $this->withHeaders(['User-Agent' => trim($userAgent)]);
    }

    /**
     * Add a request header.
     */
    public function withAddedHeader(string $key, string $value): static
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
        $this->request = $this->request->withBody(new Stream($body));

        return $this;
    }

    /**
     * Add the request data.
     */
    public function withData(array $data): static
    {
        $this->data = array_merge($this->data(), $data);
        $this->dataChanged = true;

        return $this;
    }

    /**
     * Remove the request data.
     */
    public function withoutData(array $data): static
    {
        $this->data();
        foreach ($data as $key) {
            unset($this->data[$key]);
        }

        return $this;
    }

    /**
     * Get the underlying PSR compliant request instance.
     */
    public function toPsrRequest(): RequestInterface
    {
        if ($this->dataChanged) {
            $this->applyChangedData();
        }

        return $this->request;
    }

    protected function applyChangedData(): void
    {
        $data = $this->data;
        if ($this->isForm()) {
            $data = http_build_query($this->data, '', '&');
        } elseif ($this->isJson() || ! $this->hasHeader('Content-Type')) {
            $data = json_encode($this->data);
        }

        $this->request = $this->request->withBody(
            new Stream($data)
        );

        $this->dataChanged = false;
    }
}
