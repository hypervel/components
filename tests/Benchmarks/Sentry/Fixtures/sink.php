<?php

declare(strict_types=1);

/*
 * A local Sentry envelope endpoint for benchmarks.
 *
 * It decodes each envelope, counts items by type, transactions by name and errors by message, and answers
 * 200 after an optional delay. Counts are taken when an envelope arrives.
 *
 *   php sink.php PORT
 *   GET /stats, GET /reset, GET /delay?ms=N
 */

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

$port = (int) ($argv[1] ?? 18080);

class SinkState
{
    public static int $delayMs = 0;

    public static int $inFlight = 0;

    public static array $counts = [];

    /**
     * Reset the counters between independent measurements.
     */
    public static function reset(): void
    {
        self::$counts = [
            'envelopes' => 0,
            'bytes' => 0,
            'raw_bytes' => 0,
            'items' => [],
            'transactions' => [],
            'errors' => [],
            'unparsed' => 0,
        ];
    }
}

SinkState::reset();

/**
 * Count the items in one decoded envelope.
 */
function countEnvelope(string $body): void
{
    $offset = strpos($body, "\n");

    if ($offset === false) {
        ++SinkState::$counts['unparsed'];

        return;
    }

    ++$offset;
    $length = strlen($body);

    while ($offset < $length) {
        $end = strpos($body, "\n", $offset);
        $header = json_decode(substr($body, $offset, ($end === false ? $length : $end) - $offset), true);

        if (! is_array($header) || ! isset($header['type'])) {
            ++SinkState::$counts['unparsed'];

            return;
        }

        $offset = $end === false ? $length : $end + 1;

        if (isset($header['length'])) {
            $payload = substr($body, $offset, $header['length']);
            $offset += $header['length'] + 1;
        } else {
            $end = strpos($body, "\n", $offset);
            $payload = substr($body, $offset, ($end === false ? $length : $end) - $offset);
            $offset = $end === false ? $length : $end + 1;
        }

        $type = $header['type'];
        SinkState::$counts['items'][$type] = (SinkState::$counts['items'][$type] ?? 0) + 1;

        if ($type === 'transaction') {
            $name = json_decode($payload, true)['transaction'] ?? '(none)';
            SinkState::$counts['transactions'][$name] = (SinkState::$counts['transactions'][$name] ?? 0) + 1;
        } elseif ($type === 'event') {
            $event = json_decode($payload, true);
            $message = $event['exception']['values'][0]['value'] ?? $event['message']['formatted'] ?? $event['message'] ?? '(none)';
            $message = is_string($message) ? $message : json_encode($message);
            SinkState::$counts['errors'][$message] = (SinkState::$counts['errors'][$message] ?? 0) + 1;
        }
    }
}

$server = new Server('127.0.0.1', $port, SWOOLE_BASE);
$server->set([
    'worker_num' => 1,
    'enable_coroutine' => true,
    'http_compression' => false,
    'log_level' => SWOOLE_LOG_WARNING,
]);

$server->on('request', static function (Request $request, Response $response): void {
    $path = $request->server['request_uri'];

    if ($path === '/stats') {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(SinkState::$counts + [
            'in_flight' => SinkState::$inFlight,
            'delay_ms' => SinkState::$delayMs,
        ]));

        return;
    }

    if ($path === '/reset') {
        SinkState::reset();
        $response->end('{}');

        return;
    }

    if ($path === '/delay') {
        SinkState::$delayMs = (int) ($request->get['ms'] ?? 0);
        $response->end('{}');

        return;
    }

    if (! preg_match('#^/api/\d+/envelope/$#', $path) || $request->getMethod() !== 'POST') {
        $response->status(404);
        $response->end();

        return;
    }

    $body = $request->rawContent();
    SinkState::$counts['bytes'] += strlen($body);

    if (($request->header['content-encoding'] ?? null) === 'gzip') {
        $body = gzdecode($body);
    }

    ++SinkState::$counts['envelopes'];
    SinkState::$counts['raw_bytes'] += strlen($body);
    countEnvelope($body);

    ++SinkState::$inFlight;

    try {
        if (SinkState::$delayMs > 0) {
            Coroutine::sleep(SinkState::$delayMs / 1000);
        }

        $response->header('Content-Type', 'application/json');
        $response->end('{}');
    } finally {
        --SinkState::$inFlight;
    }
});

$server->on('workerStart', static function () use ($port): void {
    fwrite(STDOUT, "READY {$port}\n");
});

$server->start();
