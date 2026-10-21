<?php

declare(strict_types=1);

/**
 * HTTP/2 test server for engine integration tests.
 *
 * Listens on port 19505 and handles cookie and multipart test endpoints.
 * This is a simplified version that doesn't require HttpMessage classes.
 */

use Hypervel\Engine\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

use function Swoole\Coroutine\run;

require_once __DIR__ . '/../../../../vendor/autoload.php';

Coroutine::set([
    'hook_flags' => SWOOLE_HOOK_ALL,
]);

$callback = function () {
    $server = new Server('0.0.0.0', 19505);
    $server->handle('/', function (Request $request, Response $response) {
        $path = $request->server['request_uri'];

        match ($path) {
            '/set-cookies' => (function () use ($request, $response) {
                // Get cookies sent by client
                $cookies = $request->cookie ?? [];

                // Parse JSON body for cookies to set
                $body = $request->rawContent();
                $json = $body ? json_decode($body, true) : [];

                // Set cookies from JSON body
                if (! empty($json['id'])) {
                    $response->setCookie('id', $json['id']);
                }
                if (! empty($json['id2'])) {
                    $response->setCookie('id2', $json['id2']);
                }

                // Return received cookies as JSON
                $response->setHeader('Content-Type', 'application/json');
                $response->end(json_encode($cookies));
            })(),
            '/mixed-multipart' => (function () use ($request, $response): void {
                // Return the parsed multipart fields and file for Saloon's multipart tests
                $file = $request->files['file'] ?? null;

                $response->setHeader('Content-Type', 'application/json');
                $response->end(json_encode([
                    'name' => $request->post['name'] ?? null,
                    'fields' => $request->post ?? [],
                    'file_contents' => $file === null ? null : file_get_contents($file['tmp_name']),
                ]));
            })(),
            '/user' => (function () use ($response): void {
                // Return the user from Saloon's test API
                $response->setHeader('Content-Type', 'application/json');
                $response->end(json_encode(['name' => 'Sammyjo20', 'actual_name' => 'Sam', 'twitter' => '@carre_sam']));
            })(),
            '/error' => (function () use ($response): void {
                // Fail like the error endpoint of Saloon's test API
                $response->status(500);
                $response->setHeader('Content-Type', 'application/json');
                $response->end(json_encode(['message' => 'Server Error']));
            })(),
            '/header-error' => (function () use ($request, $response): void {
                // Fail until Saloon's retry tests send the X-Yee-Haw header with the value 3
                if (($request->header['x-yee-haw'] ?? null) === '3') {
                    $response->end('Success!');

                    return;
                }

                $response->status(500);
                $response->setHeader('Content-Type', 'application/json');
                $response->end(json_encode(['message' => 'Server Error']));
            })(),
            default => (function () use ($request, $response) {
                $body = $request->rawContent();
                $ret = 'Hello World.';
                if ($body) {
                    $ret = 'Received: ' . $body;
                }
                $response->end($ret);
            })()
        };
    });
    $server->start();
};

run($callback);
