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

        $this->dataChanged = true;

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
