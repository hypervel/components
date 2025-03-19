<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Http;

use Hyperf\Collection\Arr;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Dispatcher\HttpDispatcher;
use Hyperf\ExceptionHandler\ExceptionHandlerDispatcher;
use Hyperf\HttpMessage\Server\Request as Psr7Request;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpMessage\Uri\Uri;
use Hyperf\HttpServer\Event\RequestHandled;
use Hyperf\HttpServer\Event\RequestReceived;
use Hyperf\HttpServer\ResponseEmitter;
use Hyperf\Support\Filesystem\Filesystem;
use Hyperf\Testing\HttpMessage\Upload\UploadedFile;
use Hypervel\Foundation\Http\Kernel as HttpKernel;
use Hypervel\Foundation\Testing\Coroutine\Waiter;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

use function Hyperf\Collection\data_get;

class TestClient extends HttpKernel
{
    protected bool $enableEvents = false;

    protected ?EventDispatcherInterface $event = null;

    protected float $waitTimeout = 10.0;

    protected string $baseUri = 'http://127.0.0.1/';

    protected static ?Waiter $waiter = null;

    public function __construct(ContainerInterface $container, $server = 'http')
    {
        $this->enableEvents = $container->get(ConfigInterface::class)
            ->get("server.servers.{$server}.options.enable_request_lifecycle", false);
        if ($this->enableEvents) {
            $this->event = $container->get(EventDispatcherInterface::class);
        }

        parent::__construct(
            $container,
            $container->get(HttpDispatcher::class),
            $container->get(ExceptionHandlerDispatcher::class),
            $container->get(ResponseEmitter::class)
        );

        $this->initCoreMiddleware($server);
        $this->initBaseUri($server);
        $this->loadKernelMiddleware($server);
    }

    public function get(string $uri, array $data = [], array $headers = [], array $cookies = [])
    {
        return $this->request('GET', $uri, [
            'headers' => $headers,
            'query' => $data,
            'cookies' => $cookies,
        ]);
    }

    public function post(string $uri, array $data = [], array $headers = [], array $cookies = [])
    {
        return $this->request('POST', $uri, [
            'headers' => $headers,
            'form_params' => $data,
            'cookies' => $cookies,
        ]);
    }

    public function put(string $uri, array $data = [], array $headers = [], array $cookies = [])
    {
        return $this->request('PUT', $uri, [
            'headers' => $headers,
            'form_params' => $data,
            'cookies' => $cookies,
        ]);
    }

    public function delete(string $uri, array $data = [], array $headers = [], array $cookies = [])
    {
        return $this->request('DELETE', $uri, [
            'headers' => $headers,
            'query' => $data,
            'cookies' => $cookies,
        ]);
    }

    public function json(string $method, string $uri, array $data = [], array $headers = [], array $cookies = [])
    {
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';

        return $this->request($method, $uri, [
            'headers' => $headers,
            'json' => $data,
            'cookies' => $cookies,
        ]);
    }

    public function file(string $uri, array $data = [], array $headers = [], array $cookies = [])
    {
        $multipart = [];

        if (Arr::isAssoc($data)) {
            $data = [$data];
        }

        foreach ($data as $item) {
            $name = $item['name'];
            $file = $item['file'];

            $multipart[] = [
                'name' => $name,
                'contents' => fopen($file, 'r'),
                'filename' => basename($file),
            ];
        }

        return $this->request('POST', $uri, [
            'headers' => $headers,
            'multipart' => $multipart,
            'cookies' => $cookies,
        ]);
    }

    public function request(string $method, string $path, array $options = [])
    {
        if (isset($options['form_params'])) {
            [$multipart, $formParams] = collect($options['form_params'])->partition(function ($value) {
                return $value instanceof UploadedFileInterface;
            })->map(fn ($collection) => $collection->all())->all();

            if (! empty($multipart)) {
                $options['form_params'] = $formParams;
                $options['multipart'] = $multipart;
            }
        }

        return $this->getWaiter()->wait(function () use ($method, $path, $options) {
            return $this->execute(
                $this->initRequest($method, $path, $options)
            );
        }, $this->waitTimeout);
    }

    public function sendRequest(ServerRequestInterface $psr7Request): ResponseInterface
    {
        return $this->getWaiter()->wait(function () use ($psr7Request) {
            return $this->execute($psr7Request);
        }, $this->waitTimeout);
    }

    public function initRequest(string $method, string $path, array $options = []): ServerRequestInterface
    {
        $query = $options['query'] ?? [];
        $params = $options['form_params'] ?? [];
        $json = $options['json'] ?? [];
        $headers = $options['headers'] ?? [];
        $multipart = $options['multipart'] ?? [];
        $cookies = $options['cookies'] ?? [];

        $parsePath = parse_url($path);
        $path = $parsePath['path'];
        $uriPathQuery = $parsePath['query'] ?? [];

        if (! empty($uriPathQuery)) {
            parse_str($uriPathQuery, $pathQuery);
            $query = array_merge($pathQuery, $query);
        }

        $data = $params;

        // Initialize PSR-7 Request and Response objects.
        $uri = (new Uri($this->baseUri . ltrim($path, '/')))->withQuery(http_build_query($query));

        $serverParams = [
            'request_method' => $method,
            'request_uri' => (new Uri(ltrim($path, '/')))->getPath(),
            'query_string' => http_build_query($query),
            'remote_addr' => '127.0.0.1',
            'request_time_float' => microtime(true),
        ];

        $content = http_build_query($params);
        if (data_get($headers, 'Content-Type') == 'application/json') {
            $content = json_encode($json, JSON_UNESCAPED_UNICODE);
            $data = $json;
        }

        $body = new SwooleStream($content);

        $request = new Psr7Request($method, $uri, $headers, $body);

        return $request->withServerParams($serverParams)
            ->withQueryParams($query)
            ->withCookieParams($cookies)
            ->withParsedBody($data)
            ->withUploadedFiles($this->normalizeFiles($multipart));
    }

    protected function execute(ServerRequestInterface $psr7Request): ResponseInterface
    {
        $this->persistToContext($psr7Request, $psr7Response = new ServerResponse());

        $this->event?->dispatch(new RequestReceived(
            request: $psr7Request,
            response: $psr7Response,
            server: $this->serverName
        ));

        try {
            $request = $this->coreMiddleware->dispatch($psr7Request);
            $psr7Response = $this->dispatcher->dispatch(
                $psr7Request,
                $this->getMiddlewareForRequest($psr7Request),
                $this->coreMiddleware
            );
        } catch (Throwable $throwable) {
            $psr7Response = $this->getResponseForException($throwable);
        } finally {
            if (isset($request)) {
                $this->event?->dispatch(new RequestHandled(
                    request: $request,
                    response: $psr7Response,
                    exception: $throwable ?? null,
                    server: $this->serverName
                ));
            }
        }

        return $psr7Response;
    }

    protected function loadKernelMiddleware(string $server): void
    {
        $kernelClass = $this->container->get(ConfigInterface::class)
            ->get("server.kernels.{$server}");
        if (! $kernelClass || ! class_exists($kernelClass)) {
            return;
        }

        $kernel = $this->container->get($kernelClass);

        $this->setGlobalMiddleware($kernel->getGlobalMiddleware());
        $this->setMiddlewareGroups($kernel->getMiddlewareGroups());
        $this->setMiddlewareAliases($kernel->getMiddlewareAliases());
        $this->setMiddlewarePriority($kernel->getMiddlewarePriority());
    }

    protected function persistToContext(ServerRequestInterface $request, ResponseInterface $response)
    {
        Context::set(ServerRequestInterface::class, $request);
        Context::set(ResponseInterface::class, $response);
    }

    protected function initBaseUri(string $server): void
    {
        if ($this->container->has(ConfigInterface::class)) {
            $config = $this->container->get(ConfigInterface::class);
            $servers = $config->get('server.servers', []);
            foreach ($servers as $item) {
                if ($item['name'] == $server) {
                    $this->baseUri = sprintf('http://127.0.0.1:%d/', (int) $item['port']);
                    break;
                }
            }
        }
    }

    protected function normalizeFiles(array $multipart): array
    {
        $files = [];
        $fileSystem = $this->container->get(Filesystem::class);

        foreach ($multipart as $name => $item) {
            if ($item instanceof UploadedFileInterface) {
                $files[$name] = $item;

                continue;
            }

            if (isset($item['name'], $item['contents'], $item['filename'])) {
                $name = $item['name'];
                $contents = $item['contents'];
                $filename = $item['filename'];

                $dir = BASE_PATH . '/runtime/uploads';
                $tmpName = $dir . '/' . $filename;
                if (! is_dir($dir)) {
                    $fileSystem->makeDirectory($dir);
                }
                $fileSystem->put($tmpName, $contents);

                $stats = fstat($contents);

                $files[$name] = new UploadedFile(
                    $tmpName,
                    $stats['size'],
                    0,
                    $name
                );
            }
        }

        return $files;
    }

    protected function getStream(string $resource)
    {
        $stream = fopen('php://temp', 'r+');
        if ($resource !== '') {
            fwrite($stream, $resource);
            fseek($stream, 0);
        }

        return $stream;
    }

    protected function getWaiter(): Waiter
    {
        if (static::$waiter) {
            return static::$waiter;
        }

        return static::$waiter = new Waiter();
    }
}
