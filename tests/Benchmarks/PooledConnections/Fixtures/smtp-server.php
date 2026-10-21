<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Server;

$delay = (int) ($argv[1] ?? 5000);
$server = new Server('127.0.0.1', 0, SWOOLE_BASE);
$server->set(['worker_num' => 1, 'enable_coroutine' => true, 'open_tcp_nodelay' => true, 'log_level' => SWOOLE_LOG_WARNING]);
$buffers = $data = $smtp = [];
$connections = $messages = $active = $peak = 0;
$server->on('workerStart', static function (Server $server): void {
    echo 'READY ' . $server->ports[0]->port . "\n";
});
$server->on('connect', static function (Server $server, int $connection) use (&$buffers, &$data): void {
    $buffers[$connection] = '';
    $data[$connection] = false;
    $server->send($connection, "220 localhost benchmark\r\n");
});
$server->on('close', static function (Server $server, int $connection) use (&$buffers, &$data, &$smtp): void {
    unset($buffers[$connection], $data[$connection], $smtp[$connection]);
});
$server->on('receive', static function (Server $server, int $connection, int $reactor, string $bytes) use (&$buffers, &$data, &$smtp, &$connections, &$messages, &$active, &$peak, $delay): void {
    $buffers[$connection] .= $bytes;
    while (isset($buffers[$connection]) && ($end = strpos($buffers[$connection], "\r\n")) !== false) {
        $line = substr($buffers[$connection], 0, $end);
        $buffers[$connection] = substr($buffers[$connection], $end + 2);
        if ($data[$connection]) {
            if ($line !== '.') {
                continue;
            }
            $data[$connection] = false;
            ++$active;
            $peak = max($peak, $active);
            if ($delay) {
                Coroutine::sleep($delay / 1e6);
            }
            ++$messages;
            --$active;
            $server->send($connection, "250 accepted\r\n");
            continue;
        }
        $command = strtoupper(strtok($line, ' '));
        if ($command === 'XRESET') {
            $connections = $messages = $peak = 0;
        }
        if ($command === 'XSTATS' || $command === 'XRESET') {
            $server->send($connection, '250 ' . json_encode(['connections' => $connections, 'messages' => $messages, 'peak_active' => $peak]) . "\r\n");
        } elseif ($command === 'EHLO' || $command === 'HELO') {
            if (! isset($smtp[$connection])) {
                $smtp[$connection] = true;
                ++$connections;
            }
            $server->send($connection, "250 localhost\r\n");
        } elseif ($command === 'DATA') {
            $data[$connection] = true;
            $server->send($connection, "354 send message\r\n");
        } elseif ($command === 'QUIT') {
            $server->send($connection, "221 bye\r\n");
            $server->close($connection);
        } elseif (in_array($command, ['MAIL', 'RCPT', 'RSET', 'NOOP'], true)) {
            $server->send($connection, "250 OK\r\n");
        } else {
            $server->send($connection, "500 unsupported command\r\n");
        }
    }
});
$server->start();
