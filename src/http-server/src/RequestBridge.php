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
        $server = static::normalizeTrailingSlash(
            static::transformServerParams($swooleRequest->server ?? [], $swooleRequest->header ?? [])
        );

        $content = $swooleRequest->rawContent();

        return new Request(
            query: $swooleRequest->get ?? [],
            request: $swooleRequest->post ?? [],
            attributes: [],
            cookies: $swooleRequest->cookie ?? [],
            files: static::transformFiles($swooleRequest->files ?? []),
            server: $server,
            content: $content === false ? null : $content
        );
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
            $httpKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
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
     * and move_uploaded_file() don't recognise Swoole-received uploads. Symfony's
     * $test flag makes isValid() skip is_uploaded_file() and move() use rename()
     * instead of move_uploaded_file().
     *
     * Pre-constructed UploadedFile instances pass through FileBag unchanged
     * (FileBag::convertFileInformation returns them as-is).
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
            $server['REQUEST_URI'] = rtrim($parts[0], '/') . (isset($parts[1]) ? '?' . $parts[1] : '');
        }

        return $server;
    }
}
