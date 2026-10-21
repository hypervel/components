<?php

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);

if ($server === false) {
    throw new RuntimeException($message, $error);
}

echo stream_socket_get_name($server, false), "\n";
fflush(STDOUT);
$connections = [];

/**
 * Accept and read one complete JSON request.
 *
 * @return array{resource, array}
 */
$receive = static function () use ($server, &$connections): array {
    $connection = stream_socket_accept($server, 3);

    if ($connection === false) {
        throw new RuntimeException('The MCP client did not send the next request.');
    }

    $connections[] = $connection;
    stream_set_timeout($connection, 3);
    $length = 0;

    while (($line = fgets($connection)) !== "\r\n" && $line !== false) {
        if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $matches)) {
            $length = (int) $matches[1];
        }
    }

    $body = '';

    while (strlen($body) < $length) {
        $chunk = fread($connection, $length - strlen($body));

        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('The MCP request body ended early.');
        }

        $body .= $chunk;
    }

    return [$connection, json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
};

/**
 * Send a complete JSON response or progress followed by an SSE reply.
 *
 * @param resource $connection
 */
$reply = static function (mixed $connection, int $id, array $result, bool $streaming = false): void {
    $body = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_THROW_ON_ERROR);
    $contentType = 'application/json';

    if ($streaming) {
        $contentType = 'text/event-stream';
        $body = "data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\",\"params\":{\"progressToken\":\"test\",\"progress\":50}}\n\ndata: {$body}\n\n";
    }

    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: {$contentType}\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    fclose($connection);
};

try {
    [$connection, $request] = $receive();
    if ($request['method'] !== 'server/discover') {
        throw new RuntimeException('The first request must negotiate discovery.');
    }

    $reply($connection, $request['id'], [
        'resultType' => 'complete',
        'supportedVersions' => [$argv[2]],
        'capabilities' => ['tools' => new stdClass],
        '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'Local MCP fixture', 'version' => '1.0']],
    ]);

    [$first, $firstRequest] = $receive();

    if (in_array($argv[1], ['concurrent', 'failure', 'cancellation'], true)) {
        // Neither response is available until both calls reach the server.
        [$second, $secondRequest] = $receive();

        if ($argv[1] === 'cancellation') {
            foreach ([$first, $second] as $connection) {
                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\n: waiting\n\n");
                fflush($connection);
            }

            echo "waiting\n";
            fflush(STDOUT);

            foreach ([$first, $second] as $connection) {
                fread($connection, 1);
                if (! feof($connection)) {
                    throw new RuntimeException('Canceling the AI operation did not close its MCP exchange.');
                }
            }

            return;
        }

        if ($argv[1] === 'failure') {
            // Handshake waiters can send before the coroutine that negotiated.
            if ($firstRequest['params']['name'] !== 'first') {
                [$first, $second] = [$second, $first];
                [$firstRequest, $secondRequest] = [$secondRequest, $firstRequest];
            }

            fwrite($first, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 1\r\nConnection: close\r\n\r\n{");
            fflush($first);
            fread($first, 1);

            if (! feof($first)) {
                throw new RuntimeException('The MCP client did not close the failed exchange.');
            }
        }

        $reply($second, $secondRequest['id'], [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => $secondRequest['params']['name']]],
        ], $argv[1] === 'concurrent');
        if ($argv[1] === 'concurrent') {
            $reply($first, $firstRequest['id'], [
                'resultType' => 'complete',
                'content' => [['type' => 'text', 'text' => $firstRequest['params']['name']]],
            ], true);
        }
    } else {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => $firstRequest['id'], 'result' => [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'finished']],
        ]], JSON_THROW_ON_ERROR);
        fwrite($first, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\ndata: {$body}\n\n");
        fflush($first);

        // Leave the response open: consuming the terminal reply must close it.
        fread($first, 1);
        if (! feof($first)) {
            throw new RuntimeException('The MCP client waited for EOF instead of closing its completed exchange.');
        }
    }
} finally {
    foreach ($connections as $connection) {
        if (is_resource($connection)) {
            fclose($connection);
        }
    }

    fclose($server);
}
