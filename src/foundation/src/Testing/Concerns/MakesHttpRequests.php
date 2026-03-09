<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcherContract;
use Hypervel\Contracts\Http\Kernel as HttpKernel;
use Hypervel\Foundation\Testing\Coroutine\Waiter;
use Hypervel\Foundation\Testing\Stubs\FakeMiddleware;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\Support\Collection;
use Hypervel\Testing\TestResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

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
     * Additional server variables for the request.
     */
    protected array $serverVariables = [];

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

    /**
     * The coroutine waiter for test requests.
     */
    protected static ?Waiter $waiter = null;

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
     * Flush all the configured headers.
     */
    public function flushHeaders(): static
    {
        $this->defaultHeaders = [];

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
            $this->app->instance('middleware.disable', true);

            return $this;
        }

        foreach ((array) $middleware as $abstract) {
            $this->app->instance($abstract, new FakeMiddleware());
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
            unset($this->app['middleware.disable']);

            return $this;
        }

        foreach ((array) $middleware as $abstract) {
            unset($this->app[$abstract]);
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

    /**
     * Set the referer header and previous URL session value in order to simulate a previous request.
     */
    public function from(string $url): static
    {
        $this->app['session']->setPreviousUrl($url);

        return $this->withHeader('referer', $url);
    }

    /**
     * Set the Precognition header to "true".
     */
    public function withPrecognition(): static
    {
        return $this->withHeader('Precognition', 'true');
    }

    /**
     * Visit the given URI with a GET request.
     */
    public function get(string $uri, array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('GET', $uri, [], $cookies, [], $server);
    }

    /**
     * Visit the given URI with a GET request, expecting a JSON response.
     */
    public function getJson(string $uri, array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('GET', $uri, [], $headers, $options);
    }

    /**
     * Visit the given URI with a POST request.
     */
    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('POST', $uri, $data, $cookies, [], $server);
    }

    /**
     * Visit the given URI with a POST request, expecting a JSON response.
     */
    public function postJson(string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('POST', $uri, $data, $headers, $options);
    }

    /**
     * Visit the given URI with a PUT request.
     */
    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('PUT', $uri, $data, $cookies, [], $server);
    }

    /**
     * Visit the given URI with a PUT request, expecting a JSON response.
     */
    public function putJson(string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('PUT', $uri, $data, $headers, $options);
    }

    /**
     * Visit the given URI with a PATCH request.
     */
    public function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('PATCH', $uri, $data, $cookies, [], $server);
    }

    /**
     * Visit the given URI with a PATCH request, expecting a JSON response.
     */
    public function patchJson(string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('PATCH', $uri, $data, $headers, $options);
    }

    /**
     * Visit the given URI with a DELETE request.
     */
    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('DELETE', $uri, $data, $cookies, [], $server);
    }

    /**
     * Visit the given URI with a DELETE request, expecting a JSON response.
     */
    public function deleteJson(string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('DELETE', $uri, $data, $headers, $options);
    }

    /**
     * Visit the given URI with an OPTIONS request.
     */
    public function options(string $uri, array $data = [], array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('OPTIONS', $uri, $data, $cookies, [], $server);
    }

    /**
     * Visit the given URI with an OPTIONS request, expecting a JSON response.
     */
    public function optionsJson(string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('OPTIONS', $uri, $data, $headers, $options);
    }

    /**
     * Visit the given URI with a HEAD request.
     */
    public function head(string $uri, array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('HEAD', $uri, [], $cookies, [], $server);
    }

    /**
     * Call the given URI with a JSON request.
     */
    public function json(string $method, string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
    {
        $files = $this->extractFilesFromDataArray($data);

        $content = json_encode($data, $options);

        $headers = array_merge([
            'CONTENT_LENGTH' => mb_strlen($content, '8bit'),
            'CONTENT_TYPE' => 'application/json',
            'Accept' => 'application/json',
        ], $headers);

        return $this->call(
            $method,
            $uri,
            [],
            $this->prepareCookiesForJsonRequest(),
            $files,
            $this->transformHeadersToServerVars($headers),
            $content
        );
    }

    /**
     * Call the given URI and return the Response.
     *
     * Each test request runs in a fresh coroutine via the Waiter to ensure
     * coroutine-local Context isolation. RequestContext and ResponseContext
     * are seeded because tests bypass the HttpServer\Server adapter which
     * normally does this in onRequest().
     */
    public function call(
        string $method,
        string $uri,
        array $parameters = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $content = null
    ): TestResponse {
        return $this->getWaiter()->wait(function () use ($method, $uri, $parameters, $cookies, $files, $server, $content) {
            $kernel = $this->app->make(HttpKernel::class);

            $files = array_merge($files, $this->extractFilesFromDataArray($parameters));

            $symfonyRequest = SymfonyRequest::create(
                $this->prepareUrlForRequest($uri),
                $method,
                $parameters,
                $cookies,
                $files,
                array_replace($this->serverVariables, $server),
                $content
            );

            $request = $this->createTestRequest($symfonyRequest);

            // Seed coroutine Context — tests bypass the HttpServer\Server adapter
            // which normally does this in onRequest().
            RequestContext::set($request);
            ResponseContext::set(new Response());

            $this->dispatchRequestLifecycleEvent(
                RequestReceived::class,
                $request
            );

            $response = $kernel->handle($request);

            $this->dispatchRequestLifecycleEvent(
                RequestHandled::class,
                $request,
                $response
            );

            $kernel->terminate($request, $response);

            // Snapshot route parameters onto the request before the coroutine
            // ends. Route parameters are stored in coroutine Context which is
            // destroyed when this waiter coroutine finishes. The snapshot
            // allows test assertions like $response->baseRequest->route('param')
            // to access parameters after the coroutine is gone.
            $route = $request->route();
            if ($route && $route->hasParameters()) {
                $request->attributes->set('_route_params', $route->parameters());
            }

            $response = $this->createTestResponse($response, $request);

            if ($this->followRedirects) {
                $response = $this->followRedirects($response);
            }

            return $response;
        }, 10.0);
    }

    /**
     * Turn the given URI into a fully-qualified URL.
     */
    protected function prepareUrlForRequest(string $uri): string
    {
        if (str_starts_with($uri, '/')) {
            $uri = substr($uri, 1);
        }

        return trim(url($uri), '/');
    }

    /**
     * Transform headers array to array of $_SERVER vars with HTTP_* format.
     */
    protected function transformHeadersToServerVars(array $headers): array
    {
        return (new Collection(array_merge($this->defaultHeaders, $headers)))->mapWithKeys(function ($value, $name) {
            $name = strtr(strtoupper($name), '-', '_');

            return [$this->formatServerHeaderKey($name) => $value];
        })->all();
    }

    /**
     * Format the header name for the server array.
     */
    protected function formatServerHeaderKey(string $name): string
    {
        if (! str_starts_with($name, 'HTTP_') && $name !== 'CONTENT_TYPE' && $name !== 'REMOTE_ADDR') {
            return 'HTTP_' . $name;
        }

        return $name;
    }

    /**
     * Extract the file uploads from the given data array.
     */
    protected function extractFilesFromDataArray(array &$data): array
    {
        $files = [];

        foreach ($data as $key => $value) {
            if ($value instanceof SymfonyUploadedFile) {
                $files[$key] = $value;

                unset($data[$key]);
            }

            if (is_array($value)) {
                $files[$key] = $this->extractFilesFromDataArray($value);

                $data[$key] = $value;
            }
        }

        return $files;
    }

    /**
     * Prepare cookies for the request.
     */
    protected function prepareCookiesForRequest(): array
    {
        return $this->defaultCookies;
    }

    /**
     * Prepare cookies for JSON requests.
     */
    protected function prepareCookiesForJsonRequest(): array
    {
        return $this->withCredentials ? $this->prepareCookiesForRequest() : [];
    }

    /**
     * Follow a redirect chain until a non-redirect is received.
     * @param mixed $response
     */
    protected function followRedirects($response): TestResponse
    {
        $this->followRedirects = false;

        while ($response->isRedirect()) {
            $response = $this->get($response->headers->get('Location'));
        }

        return $response;
    }

    /**
     * Create the request instance used for testing from the given Symfony request.
     */
    protected function createTestRequest(SymfonyRequest $symfonyRequest): Request
    {
        return Request::createFromBase($symfonyRequest);
    }

    /**
     * Create the test response instance from the given response.
     * @param mixed $response
     */
    protected function createTestResponse($response, ?Request $request = null): TestResponse
    {
        return TestResponse::fromBaseResponse($response, $request);
    }

    /**
     * Dispatch a request lifecycle event if enabled.
     *
     * Tests bypass the HttpServer\Server adapter, so lifecycle events must be
     * dispatched here for Telescope and other watchers to work in test contexts.
     */
    protected function dispatchRequestLifecycleEvent(
        string $eventClass,
        Request $request,
        ?\Symfony\Component\HttpFoundation\Response $response = null
    ): void {
        if (! $this->app->has(EventDispatcherContract::class)) {
            return;
        }

        $config = $this->app->make('config');
        $servers = $config->get('server.servers', []);
        $enabled = false;

        foreach ($servers as $server) {
            if (($server['name'] ?? '') === 'http') {
                $enabled = $server['options']['enable_request_lifecycle'] ?? false;
                break;
            }
        }

        if (! $enabled) {
            return;
        }

        $this->app->make(EventDispatcherContract::class)->dispatch(new $eventClass(
            request: $request,
            response: $response,
            server: 'http'
        ));
    }

    /**
     * Get the coroutine waiter for test requests.
     */
    protected function getWaiter(): Waiter
    {
        return static::$waiter ??= new Waiter();
    }
}
