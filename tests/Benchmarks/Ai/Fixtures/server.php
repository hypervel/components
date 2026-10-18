<?php

declare(strict_types=1);

use Hypervel\Tests\Ai\Fixtures\OpenAiHelpers;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$payloads = new class {
    use OpenAiHelpers {
        ssePayload as public;
        responseCreated as public;
        outputTextDelta as public;
        outputTextDone as public;
        responseCompleted as public;
    }
};

$server = new Server('127.0.0.1', 0, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
$server->set([
    'worker_num' => 1,
    'enable_coroutine' => true,
    'ssl_cert_file' => $argv[1],
    'ssl_key_file' => $argv[2],
    'log_level' => SWOOLE_LOG_WARNING,
]);
$connections = [];
$accepted = 0;
$active = 0;
$server->on('workerStart', static function (Server $server): void {
    echo 'READY ' . $server->ports[0]->port . "\n";
});
$server->on('connect', static function (Server $server, int $connection) use (&$connections, &$accepted): void {
    $connections[$connection] = ++$accepted;
});
$server->on('close', static function (Server $server, int $connection) use (&$connections): void {
    unset($connections[$connection]);
});
$server->on('request', static function (Request $request, Response $response) use ($payloads, &$connections, &$active): void {
    ++$active;

    try {
        $chunks = (int) $request->get['chunks'];
        $bytes = (int) $request->get['bytes'];
        $pace = (int) $request->get['pace_us'];
        $body = json_decode($request->rawContent(), true, flags: JSON_THROW_ON_ERROR);
        $delta = str_repeat('x', $bytes);
        $text = str_repeat($delta, $chunks);
        $completed = $payloads->responseCompleted(2, $chunks, output: [[
            'type' => 'message',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]]);

        $response->header('X-Benchmark-Connection', (string) $connections[$request->fd]);
        $response->header('X-Benchmark-Active', (string) $active);
        $response->header('X-Benchmark-Account', hash('sha256', $request->header['authorization']));

        if (! ($body['stream'] ?? false)) {
            if ($pace > 0 && $chunks > 1) {
                Coroutine::sleep($pace * ($chunks - 1) / 1e6);
            }

            $response->header('Content-Type', 'application/json');
            $response->end(json_encode($completed['response'], JSON_THROW_ON_ERROR));

            return;
        }

        $response->header('Content-Type', 'text/event-stream');

        if (! $response->write($payloads->ssePayload([$payloads->responseCreated()]))) {
            return;
        }

        $chunk = $payloads->ssePayload([$payloads->outputTextDelta($delta)]);

        for ($index = 0; $index < $chunks; ++$index) {
            if (! $response->write($chunk)) {
                return;
            }

            if ($pace > 0 && $index + 1 < $chunks) {
                Coroutine::sleep($pace / 1e6);
            }
        }

        $response->end($payloads->ssePayload([$payloads->outputTextDone($text), $completed]));
    } finally {
        --$active;
    }
});
$server->start();
