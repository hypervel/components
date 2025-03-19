<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Foundation\Testing\Http\TestClient;
use Hypervel\Foundation\Testing\Http\TestResponse;
use Hypervel\Foundation\Testing\Stubs\FakeMiddleware;

trait MakesHttpRequests
{
    /**
     * Additional headers for the request.
     */
    protected array $defaultHeaders = [];

    /**
     * Additional cookies for the request.
     */
    protected array $defaultCookies = [];

    /**
     * Indicates whether redirects should be followed.
     */
    protected bool $followRedirects = false;

    /**
     * Indicated whether JSON requests should be performed "with credentials" (cookies).
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest/withCredentials
     */
    protected bool $withCredentials = false;

    protected function get($uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->doRequest(__FUNCTION__, $uri, $data, $headers);
    }

    protected function post($uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->doRequest(__FUNCTION__, $uri, $data, $headers);
    }

    protected function put($uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->doRequest(__FUNCTION__, $uri, $data, $headers);
    }

    protected function delete($uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->doRequest(__FUNCTION__, $uri, $data, $headers);
    }

    public function json(string $method, $uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        return $this->doRequest($method, $uri, $data, $headers);
    }

    protected function getJson($uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('GET', $uri, $data, $headers);
    }

    protected function postJson($uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('POST', $uri, $data, $headers);
    }

    protected function putJson($uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('PUT', $uri, $data, $headers);
    }

    protected function patchJson($uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('PATCH', $uri, $data, $headers);
    }

    protected function deleteJson($uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('DELETE', $uri, $data, $headers);
    }

    protected function optionsJson($uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('OPTIONS', $uri, $data, $headers);
    }

    protected function file($uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->doRequest(__FUNCTION__, $uri, $data, $headers);
    }

    protected function doRequest(string $method, $uri, array $data = [], array $headers = []): TestResponse
    {
        $cookies = $method !== 'json' || ($method === 'json' && $this->withCredentials)
            ? $this->defaultCookies
            : [];

        $response = $this->createTestResponse(
            $this->app->make(TestClient::class)->{$method}(
                $this->prepareUrlForRequest($uri),
                $data,
                array_merge($this->defaultHeaders, $headers),
                $cookies
            )
        );

        if ($this->followRedirects) {
            $response = $this->followRedirects($response);
        }

        $this->flushRequestStates();

        return $response;
    }

    /**
     * Turn the given URI without trailing slash.
     */
    protected function prepareUrlForRequest(string $uri): string
    {
        if ($uri === '/') {
            return $uri;
        }

        return rtrim($uri, '/');
    }

    /**
     * Follow a redirect chain until a non-redirect is received.
     *
     * @param TestResponse $response
     */
    protected function followRedirects($response): TestResponse
    {
        $this->followRedirects = false;

        while ($response->isRedirect()) {
            $response = $this->get($response->getHeader('Location')[0]);
        }

        return $response;
    }

    protected function createTestResponse($response): TestResponse
    {
        return new TestResponse($response);
    }

    /**
     * Define additional headers to be sent with the request.
     */
    public function withHeaders(array $headers): static
    {
        $this->defaultHeaders = array_merge($this->defaultHeaders, $headers);

        return $this;
    }

    /**
     * Add a header to be sent with the request.
     */
    public function withHeader(string $name, string $value): static
    {
        $this->defaultHeaders[$name] = $value;

        return $this;
    }

    /**
     * Remove a header from the request.
     */
    public function withoutHeader(string $name): static
    {
        unset($this->defaultHeaders[$name]);

        return $this;
    }

    /**
     * Remove headers from the request.
     */
    public function withoutHeaders(array $headers): static
    {
        foreach ($headers as $name) {
            $this->withoutHeader($name);
        }

        return $this;
    }

    /**
     * Add an authorization token for the request.
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withHeader('Authorization', $type . ' ' . $token);
    }

    /**
     * Add a basic authentication header to the request with the given credentials.
     */
    public function withBasicAuth(string $username, string $password): static
    {
        return $this->withToken(base64_encode("{$username}:{$password}"), 'Basic');
    }

    /**
     * Remove the authorization token from the request.
     */
    public function withoutToken(): static
    {
        return $this->withoutHeader('Authorization');
    }

    /**
     * Flush all the configured states.
     */
    public function flushRequestStates(): static
    {
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->followRedirects = false;
        $this->withCredentials = false;

        return $this;
    }

    /**
     * Define a set of server variables to be sent with the requests.
     */
    public function withServerVariables(array $server): static
    {
        $this->serverVariables = $server;

        return $this;
    }

    /**
     * Disable middleware for the test.
     *
     * @param null|array|string $middleware
     */
    protected function withoutMiddleware($middleware = null): static
    {
        if (is_null($middleware)) {
            $this->app->set('middleware.disable', true);
            return $this;
        }

        foreach ((array) $middleware as $abstract) {
            $this->app->define($abstract, FakeMiddleware::class);
        }

        return $this;
    }

    /**
     * Enable the given middleware for the test.
     *
     * @param null|array|string $middleware
     */
    public function withMiddleware($middleware = null): static
    {
        if (is_null($middleware)) {
            $this->app->remove('middleware.disable');

            return $this;
        }

        // restore bindings since bound middleware can't be removed from container's definition map
        foreach ((array) $middleware as $abstract) {
            $this->app->remove($abstract, $abstract);
        }

        return $this;
    }

    /**
     * Define additional cookies to be sent with the request.
     */
    public function withCookies(array $cookies): static
    {
        $this->defaultCookies = array_merge($this->defaultCookies, $cookies);

        return $this;
    }

    /**
     * Add a cookie to be sent with the request.
     */
    public function withCookie(string $name, string $value): static
    {
        $this->defaultCookies[$name] = $value;

        return $this;
    }

    /**
     * Automatically follow any redirects returned from the response.
     */
    public function followingRedirects(): static
    {
        $this->followRedirects = true;

        return $this;
    }

    /**
     * Include cookies and authorization headers for JSON requests.
     */
    public function withCredentials(): static
    {
        $this->withCredentials = true;

        return $this;
    }
}
