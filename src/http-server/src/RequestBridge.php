<?php

declare(strict_types=1);

namespace Hypervel\HttpServer;

use Hypervel\Http\Request;
use Hypervel\Http\UploadedFile;
use Swoole\Http\Request as SwooleRequest;

class RequestBridge
{
    /**
     * Create an HttpFoundation request from a Swoole request.
     */
    public static function createFromSwoole(SwooleRequest $swooleRequest): Request
    {
        $server = static::transformServerParams(
            $swooleRequest->server ?? [],
            $swooleRequest->header ?? [],
        );

        // Swoole exposes the URI and query as separate server values while
        // HttpFoundation expects REQUEST_URI to retain both components.
        if (isset($server['REQUEST_URI'], $server['QUERY_STRING'])
            && is_string($server['REQUEST_URI'])
            && is_string($server['QUERY_STRING'])
            && $server['QUERY_STRING'] !== ''
            && ! str_contains($server['REQUEST_URI'], '?')
        ) {
            $server['REQUEST_URI'] .= '?' . $server['QUERY_STRING'];
        }

        $server = static::normalizeTrailingSlash($server);
        $content = $swooleRequest->rawContent();
        $headers = static::transformHeaders($swooleRequest->header ?? [], $server);

        return new Request(
            query: $swooleRequest->get ?? [],
            request: $swooleRequest->post ?? [],
            attributes: [],
            cookies: $swooleRequest->cookie ?? [],
            files: static::transformFiles($swooleRequest->files ?? []),
            server: $server,
            content: $content === false ? null : $content,
            headers: $headers,
            pathInfo: static::extractPathInfo($server),
        );
    }

    /**
     * Build request headers directly while retaining Symfony's authorization aliases.
     */
    protected static function transformHeaders(array $headers, array &$server): RequestHeaderBag
    {
        $headerBag = new RequestHeaderBag($headers);

        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'] as $name) {
            if (! $headerBag->has($name) && isset($server[$name]) && $server[$name] !== '') {
                $headerBag->set($name, $server[$name]);
            }
        }

        if (isset($server['PHP_AUTH_USER'])) {
            $headerBag->set('PHP_AUTH_USER', $server['PHP_AUTH_USER']);
            $headerBag->set('PHP_AUTH_PW', $server['PHP_AUTH_PW'] ?? '');
        } else {
            $authorization = $server['HTTP_AUTHORIZATION']
                ?? $server['REDIRECT_HTTP_AUTHORIZATION']
                ?? null;

            if ($authorization !== null && stripos($authorization, 'basic ') === 0) {
                $credentials = explode(':', (string) base64_decode(substr($authorization, 6)), 2);

                if (count($credentials) === 2) {
                    [$user, $password] = $credentials;
                    $headerBag->set('PHP_AUTH_USER', $user);
                    $headerBag->set('PHP_AUTH_PW', $password);
                }
            } elseif ($authorization !== null
                && empty($server['PHP_AUTH_DIGEST'])
                && stripos($authorization, 'digest ') === 0
            ) {
                $headerBag->set('PHP_AUTH_DIGEST', $authorization);
                $server['PHP_AUTH_DIGEST'] = $authorization;
            } elseif ($authorization !== null && stripos($authorization, 'bearer ') === 0) {
                $headerBag->set('AUTHORIZATION', $authorization);
            }
        }

        if ($headerBag->has('AUTHORIZATION')) {
            return $headerBag;
        }

        if ($headerBag->has('PHP_AUTH_USER')) {
            $headerBag->set('AUTHORIZATION', 'Basic ' . base64_encode(
                $headerBag->get('PHP_AUTH_USER') . ':' . $headerBag->get('PHP_AUTH_PW')
            ));
        } elseif ($headerBag->has('PHP_AUTH_DIGEST')) {
            $headerBag->set('AUTHORIZATION', $headerBag->get('PHP_AUTH_DIGEST'));
        }

        return $headerBag;
    }

    /**
     * Transform Swoole's server params to $_SERVER style.
     *
     * Swoole uses lowercase keys and splits headers from server vars.
     * HttpFoundation expects $_SERVER-style uppercase keys with HTTP_ prefix for headers.
     */
    protected static function transformServerParams(array $server, array $headers): array
    {
        $result = [];

        // Swoole server params → uppercase
        foreach ($server as $key => $value) {
            $result[strtoupper($key)] = $value;
        }

        // Swoole headers → HTTP_* format
        foreach ($headers as $key => $value) {
            // Swoole normally supplies lowercase names. Map common headers
            // without replace/uppercase allocations; retain the generic path.
            $httpKey = match ($key) {
                'accept' => 'HTTP_ACCEPT',
                'accept-encoding' => 'HTTP_ACCEPT_ENCODING',
                'authorization' => 'HTTP_AUTHORIZATION',
                'connection' => 'HTTP_CONNECTION',
                'content-length' => 'HTTP_CONTENT_LENGTH',
                'content-type' => 'HTTP_CONTENT_TYPE',
                'host' => 'HTTP_HOST',
                'user-agent' => 'HTTP_USER_AGENT',
                'x-forwarded-for' => 'HTTP_X_FORWARDED_FOR',
                'x-forwarded-host' => 'HTTP_X_FORWARDED_HOST',
                'x-forwarded-port' => 'HTTP_X_FORWARDED_PORT',
                'x-forwarded-proto' => 'HTTP_X_FORWARDED_PROTO',
                'x-request-id' => 'HTTP_X_REQUEST_ID',
                default => 'HTTP_' . strtoupper(str_replace('-', '_', $key)),
            };
            $result[$httpKey] = $value;
        }

        // Special headers that don't get HTTP_ prefix
        if (isset($headers['content-type'])) {
            $result['CONTENT_TYPE'] = $headers['content-type'];
        }
        if (isset($headers['content-length'])) {
            $result['CONTENT_LENGTH'] = $headers['content-length'];
        }

        return $result;
    }

    /**
     * Transform Swoole's file uploads to UploadedFile instances.
     *
     * Swoole provides files in $_FILES format. We construct UploadedFile objects
     * with $test=true because Swoole's CLI SAPI means PHP's is_uploaded_file()
     * and move_uploaded_file() don't recognize Swoole-received uploads. Symfony's
     * $test flag makes isValid() skip is_uploaded_file() and move() use rename()
     * instead of move_uploaded_file().
     *
     * UploadedFile instances constructed here pass through FileBag unchanged
     * because FileBag::convertFileInformation returns them as-is.
     */
    protected static function transformFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if (is_array($value) && isset($value['tmp_name'])) {
                $normalized[$key] = is_array($value['tmp_name'])
                    ? static::transformNestedFiles($value)
                    : new UploadedFile(
                        $value['tmp_name'],
                        $value['full_path'] ?? $value['name'],
                        $value['type'],
                        $value['error'],
                        true // Swoole CLI — bypass is_uploaded_file() / move_uploaded_file()
                    );
            } elseif (is_array($value)) {
                $normalized[$key] = static::transformFiles($value);
            }
        }

        return $normalized;
    }

    /**
     * Transform nested file upload arrays (multi-file fields).
     */
    protected static function transformNestedFiles(array $files): array
    {
        $normalized = [];

        foreach (array_keys($files['tmp_name']) as $key) {
            $spec = [
                'tmp_name' => $files['tmp_name'][$key],
                'name' => $files['name'][$key],
                'type' => $files['type'][$key],
                'error' => $files['error'][$key],
            ];

            if (isset($files['full_path'][$key])) {
                $spec['full_path'] = $files['full_path'][$key];
            }

            $normalized[$key] = is_array($spec['tmp_name'])
                ? static::transformNestedFiles($spec)
                : new UploadedFile(
                    $spec['tmp_name'],
                    $spec['full_path'] ?? $spec['name'],
                    $spec['type'],
                    $spec['error'],
                    true
                );
        }

        return $normalized;
    }

    /**
     * Normalize the trailing slash in the REQUEST_URI.
     *
     * Done once during bridge creation, not per-request during matching.
     * This eliminates the $request->duplicate() clone that Laravel does.
     */
    protected static function normalizeTrailingSlash(array $server): array
    {
        if (isset($server['REQUEST_URI']) && $server['REQUEST_URI'] !== '/') {
            $parts = explode('?', $server['REQUEST_URI'], 2);
            $path = rtrim($parts[0], '/');
            $server['REQUEST_URI'] = ($path === '' ? '/' : $path)
                . (isset($parts[1]) ? '?' . $parts[1] : '');
        }

        return $server;
    }

    /**
     * Extract the raw path from the normalized Swoole request URI.
     */
    protected static function extractPathInfo(array $server): ?string
    {
        // Front-controller and IIS metadata affect Symfony's base-path rules;
        // defer to its full path derivation whenever those inputs are present.
        foreach (['SCRIPT_FILENAME', 'SCRIPT_NAME', 'PHP_SELF', 'ORIG_SCRIPT_NAME', 'UNENCODED_URL', 'ORIG_PATH_INFO'] as $name) {
            if (! empty($server[$name])) {
                return null;
            }
        }

        $requestUri = $server['REQUEST_URI'] ?? null;

        if (! is_string($requestUri)) {
            return null;
        }

        if ($requestUri === '') {
            return '/';
        }

        // Swoole passes the request target through verbatim. Symfony strips
        // fragments and reduces proxy-style absolute-form targets (RFC 7230
        // §5.3.2) to their path, so defer to its full derivation for both.
        if ($requestUri[0] !== '/' || str_contains($requestUri, '#')) {
            return null;
        }

        if (($queryPosition = strpos($requestUri, '?')) !== false) {
            $requestUri = substr($requestUri, 0, $queryPosition);
        }

        return $requestUri;
    }
}
