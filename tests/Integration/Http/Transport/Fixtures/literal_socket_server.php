<?php

declare(strict_types=1);

use Hypervel\Engine\Coroutine;

use function Swoole\Coroutine\run;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

Coroutine::set([
    'hook_flags' => SWOOLE_HOOK_ALL,
    // Hooked accept otherwise expires at Swoole's 60-second socket default.
    'socket_timeout' => -1,
]);

$mode = getenv('HTTP_TRANSPORT_FIXTURE_MODE') ?: 'plain';
$port = (int) (getenv('HTTP_TRANSPORT_FIXTURE_PORT') ?: match ($mode) {
    'plain' => 19530,
    'tls' => 19531,
    'stall' => 19533,
    default => throw new InvalidArgumentException("Unknown literal socket fixture mode [{$mode}]."),
});
$contextOptions = [];
$scheme = 'tcp';

if ($mode === 'tls') {
    $tlsDirectory = __DIR__ . '/Tls';
    $scheme = 'tls';
    $contextOptions['ssl'] = [
        'local_cert' => $tlsDirectory . '/server.crt',
        'local_pk' => $tlsDirectory . '/server.key',
        'crypto_method' => STREAM_CRYPTO_METHOD_TLS_SERVER,
        'verify_peer' => false,
    ];
}

run(function () use ($contextOptions, $mode, $port, $scheme): void {
    $context = stream_context_create($contextOptions);
    $server = stream_socket_server(
        "{$scheme}://0.0.0.0:{$port}",
        $errorCode,
        $errorMessage,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context,
    );

    if ($server === false) {
        throw new RuntimeException(
            "Unable to start the literal socket fixture: [{$errorCode}] {$errorMessage}",
        );
    }

    $connectionId = 0;

    while (true) {
        error_clear_last();
        $connection = @stream_socket_accept($server, -1);

        if ($connection === false) {
            $swooleError = swoole_last_error();

            if ($scheme === 'tls' && $swooleError === SWOOLE_ERROR_SSL_BAD_CLIENT) {
                continue;
            }

            throw new RuntimeException(
                error_get_last()['message']
                    ?? 'The literal socket fixture stopped accepting connections.',
            );
        }

        ++$connectionId;

        Coroutine::create(function () use ($connection, $connectionId, $mode): void {
            if ($mode === 'stall') {
                // HTTPS clients wait for ServerHello here, producing a connect-phase timeout.
                usleep(5_000_000);
                fclose($connection);

                return;
            }

            stream_set_timeout($connection, 10);

            try {
                serveLiteralConnection($connection, $connectionId);
            } finally {
                if (is_resource($connection)) {
                    fclose($connection);
                }
            }
        });
    }
});

/**
 * Serve requests from one persistent HTTP/1.1 connection.
 *
 * @param resource $connection
 */
function serveLiteralConnection($connection, int $connectionId): void
{
    while (($request = readLiteralRequest($connection)) !== null) {
        $path = parse_url($request['target'], PHP_URL_PATH) ?: '/';
        $query = [];
        parse_str((string) parse_url($request['target'], PHP_URL_QUERY), $query);

        if ($path === '/reset') {
            return;
        }

        if ($path === '/truncated') {
            writeLiteralBytes(
                $connection,
                "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 20\r\nConnection: close\r\n\r\nshort",
            );

            return;
        }

        if ($path === '/delay') {
            $milliseconds = min(max((int) ($query['milliseconds'] ?? 100), 0), 2_000);
            usleep($milliseconds * 1000);
        }

        [$statusLine, $headers, $body] = literalResponseFor(
            $path,
            $request,
            $connectionId,
        );
        $close = strtolower($request['headers']['connection'] ?? '') === 'close';
        $headers[] = ['Connection', $close ? 'close' : 'keep-alive'];
        $headers[] = ['Content-Length', (string) strlen($body)];

        $response = "HTTP/1.1 {$statusLine}\r\n";

        foreach ($headers as [$name, $value]) {
            $response .= "{$name}: {$value}\r\n";
        }

        writeLiteralBytes($connection, $response . "\r\n" . $body);

        if ($close) {
            return;
        }
    }
}

/**
 * Read one complete HTTP/1.1 request.
 *
 * @param resource $connection
 * @return null|array{method: string, target: string, headers: array<string, string>, body: string}
 */
function readLiteralRequest($connection): ?array
{
    $buffer = '';

    while (! str_contains($buffer, "\r\n\r\n")) {
        $chunk = fread($connection, 8192);

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $buffer .= $chunk;
    }

    [$headerBlock, $body] = explode("\r\n\r\n", $buffer, 2);
    $lines = explode("\r\n", $headerBlock);
    $requestLine = array_shift($lines);

    if (! is_string($requestLine)
        || ! preg_match('/^(\\S+)\\s+(\\S+)\\s+HTTP\\/1\\.1$/', $requestLine, $matches)) {
        return null;
    }

    $headers = [];

    foreach ($lines as $line) {
        if (! str_contains($line, ':')) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    $contentLength = max((int) ($headers['content-length'] ?? 0), 0);

    while (strlen($body) < $contentLength) {
        $chunk = fread($connection, $contentLength - strlen($body));

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $body .= $chunk;
    }

    return [
        'method' => $matches[1],
        'target' => $matches[2],
        'headers' => $headers,
        'body' => substr($body, 0, $contentLength),
    ];
}

/**
 * Build the exact response evidence for a fixture route.
 *
 * @param array{method: string, target: string, headers: array<string, string>, body: string} $request
 * @return array{string, list<array{string, string}>, string}
 */
function literalResponseFor(string $path, array $request, int $connectionId): array
{
    if ($path === '/compressed') {
        $body = gzencode('compressed response', 9);

        if ($body === false) {
            throw new RuntimeException('Unable to encode the compressed fixture response.');
        }

        return [
            '200 OK',
            [
                ['Content-Type', 'text/plain'],
                ['Content-Encoding', 'gzip'],
            ],
            $body,
        ];
    }

    return match ($path) {
        '/mixed-headers' => [
            '200 OK',
            [
                ['Content-Type', 'text/plain'],
                ['X-MiXeD-CaSe', 'preserved'],
                ['X-Repeated', 'first'],
                ['X-Repeated', 'second'],
                ['Set-Cookie', 'first=one; Path=/'],
                ['Set-Cookie', 'second=two; Path=/'],
            ],
            'headers',
        ],
        '/binary' => [
            '200 OK',
            [['Content-Type', 'application/octet-stream']],
            "\x00\x01Hypervel\xff",
        ],
        '/connection-id' => [
            '200 OK',
            [['Content-Type', 'text/plain']],
            (string) $connectionId,
        ],
        '/echo' => [
            '200 OK',
            [['Content-Type', 'application/json']],
            json_encode([
                'method' => $request['method'],
                'body' => $request['body'],
                'cookie' => $request['headers']['cookie'] ?? null,
            ], JSON_THROW_ON_ERROR),
        ],
        '/custom-reason' => [
            '299 Custom Wire Phrase',
            [['Content-Type', 'text/plain']],
            'custom',
        ],
        '/delay', '/up' => [
            '200 OK',
            [['Content-Type', 'text/plain']],
            'ok',
        ],
        default => [
            '404 Not Found',
            [['Content-Type', 'text/plain']],
            'not found',
        ],
    };
}

/**
 * Write every response byte before returning.
 *
 * @param resource $connection
 */
function writeLiteralBytes($connection, string $bytes): void
{
    while ($bytes !== '') {
        $written = fwrite($connection, $bytes);

        if ($written === false || $written === 0) {
            return;
        }

        $bytes = substr($bytes, $written);
    }
}
