<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use BackedEnum;
use Hypervel\Context\ParentContext;
use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Http\Kernel as HttpKernel;
use Hypervel\Cookie\CookieValuePrefix;
use Hypervel\Foundation\Testing\Coroutine\Waiter;
use Hypervel\Foundation\Testing\Stubs\FakeMiddleware;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\Session\Store as SessionStore;
use Hypervel\Support\Collection;
use Hypervel\Testing\FakeWritableConnection;
use Hypervel\Testing\LoggedExceptionCollection;
use Hypervel\Testing\TestResponse;
use Stringable;
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
     * Additional cookies that will not be encrypted for the request.
     */
    protected array $unencryptedCookies = [];

    /**
     * Additional server variables for the request.
     */
    protected array $serverVariables = [];

    /**
     * Indicates whether redirects should be followed.
     */
    protected bool $followRedirects = false;

    /**
     * Indicates whether cookies should be encrypted.
     */
    protected bool $encryptCookies = true;

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
     * Define additional cookies that will not be encrypted before sending with the request.
     */
    public function withUnencryptedCookies(array $cookies): static
    {
        $this->unencryptedCookies = array_merge($this->unencryptedCookies, $cookies);

        return $this;
    }

    /**
     * Add a cookie that will not be encrypted before sending with the request.
     */
    public function withUnencryptedCookie(string $name, string $value): static
    {
        $this->unencryptedCookies[$name] = $value;

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
     * Disable automatic encryption of cookie values.
     */
    public function disableCookieEncryption(): static
    {
        $this->encryptCookies = false;

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
     * Set the referer header and previous URL session value from a given route in order to simulate a previous request.
     */
    public function fromRoute(BackedEnum|string $name, mixed $parameters = []): static
    {
        return $this->from($this->app['url']->route($name, $parameters));
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
    public function get(Stringable|string $uri, array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('GET', $uri, [], $cookies, [], $server);
    }

    /**
     * Visit the given URI with a GET request, expecting a JSON response.
     */
    public function getJson(Stringable|string $uri, array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('GET', $uri, [], $headers, $options);
    }

    /**
     * Visit the given URI with a POST request.
     */
    public function post(Stringable|string $uri, array $data = [], array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('POST', $uri, $data, $cookies, [], $server);
    }

    /**
     * Visit the given URI with a POST request, expecting a JSON response.
     */
    public function postJson(Stringable|string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('POST', $uri, $data, $headers, $options);
    }

    /**
     * Visit the given URI with a PUT request.
     */
    public function put(Stringable|string $uri, array $data = [], array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('PUT', $uri, $data, $cookies, [], $server);
    }

    /**
     * Visit the given URI with a PUT request, expecting a JSON response.
     */
    public function putJson(Stringable|string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('PUT', $uri, $data, $headers, $options);
    }

    /**
     * Visit the given URI with a PATCH request.
     */
    public function patch(Stringable|string $uri, array $data = [], array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('PATCH', $uri, $data, $cookies, [], $server);
    }

    /**
     * Visit the given URI with a PATCH request, expecting a JSON response.
     */
    public function patchJson(Stringable|string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('PATCH', $uri, $data, $headers, $options);
    }

    /**
     * Visit the given URI with a DELETE request.
     */
    public function delete(Stringable|string $uri, array $data = [], array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('DELETE', $uri, $data, $cookies, [], $server);
    }

    /**
     * Visit the given URI with a DELETE request, expecting a JSON response.
     */
    public function deleteJson(Stringable|string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('DELETE', $uri, $data, $headers, $options);
    }

    /**
     * Visit the given URI with an OPTIONS request.
     */
    public function options(Stringable|string $uri, array $data = [], array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('OPTIONS', $uri, $data, $cookies, [], $server);
    }

    /**
     * Visit the given URI with an OPTIONS request, expecting a JSON response.
     */
    public function optionsJson(Stringable|string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
    {
        return $this->json('OPTIONS', $uri, $data, $headers, $options);
    }

    /**
     * Visit the given URI with a HEAD request.
     */
    public function head(Stringable|string $uri, array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('HEAD', $uri, [], $cookies, [], $server);
    }

    /**
     * Call the given URI with a JSON request.
     */
    public function json(string $method, Stringable|string $uri, array $data = [], array $headers = [], int $options = 0): TestResponse
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
        Stringable|string $uri,
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
            // which normally does this in onRequest(). The response gets a fake
            // writable connection so Response::stream() works in test mode.
            RequestContext::set($request);
            $response = new Response();
            $response->setConnection(new FakeWritableConnection());
            if ($method === 'HEAD') {
                $response->withoutBody();
            }
            ResponseContext::set($response);

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

            RequestContext::set($request, \Hypervel\Coroutine\Coroutine::parentId());

            if ($request->hasSession()) {
                /** @var SessionStore $session */
                $session = $request->session();

                ParentContext::set(SessionStore::CONTEXT_KEY, $session);
                ParentContext::set(SessionStore::STARTED_CONTEXT_KEY, $session->isStarted());
                ParentContext::set(SessionStore::ID_CONTEXT_KEY, $session->getId());
                ParentContext::set(SessionStore::ATTRIBUTES_CONTEXT_KEY, $session->all());
            } else {
                ParentContext::forget(SessionStore::CONTEXT_KEY);
                ParentContext::forget(SessionStore::STARTED_CONTEXT_KEY);
                ParentContext::forget(SessionStore::ID_CONTEXT_KEY);
                ParentContext::forget(SessionStore::ATTRIBUTES_CONTEXT_KEY);
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
    protected function prepareUrlForRequest(Stringable|string $uri): string
    {
        $uri = (string) $uri;

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
     * If enabled, encrypt cookie values for request.
     */
    protected function prepareCookiesForRequest(): array
    {
        if (! $this->encryptCookies) {
            return array_merge($this->defaultCookies, $this->unencryptedCookies);
        }

        return (new Collection($this->defaultCookies))
            ->map(fn ($value, $key) => encrypt(CookieValuePrefix::create($key, app('encrypter')->getKey()) . $value, false))
            ->merge($this->unencryptedCookies)
            ->all();
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
        return tap(TestResponse::fromBaseResponse($response, $request), function ($response) {
            $response->withExceptions(
                $this->app->bound(LoggedExceptionCollection::class)
                    ? $this->app->make(LoggedExceptionCollection::class)
                    : new LoggedExceptionCollection()
            );
        });
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
        if (! $this->app->bound('events')) {
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

        $this->app['events']->dispatch(new $eventClass(
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
