<?php

declare(strict_types=1);

use Hypervel\Database\PdoConnection;
use Swoole\Runtime;

use function Swoole\Coroutine\run;

require $argv[1];

/**
 * Write a MySQL packet to the loopback client.
 * @param mixed $stream
 */
function packet($stream, string $payload, int $sequence): void
{
    fwrite($stream, substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload);
}

/**
 * Read a complete MySQL packet within the stream timeout.
 * @param mixed $stream
 */
function receivePacket($stream): string
{
    $header = fread($stream, 4);
    if (strlen($header) !== 4) {
        throw new RuntimeException('Missing packet header');
    }
    $length = unpack('V', substr($header, 0, 3) . "\0")[1];
    $payload = '';
    while (strlen($payload) < $length) {
        $part = fread($stream, $length - strlen($payload));
        if ($part === '' || $part === false) {
            throw new RuntimeException('Incomplete packet');
        }
        $payload .= $part;
    }
    return $payload;
}

/**
 * Accept a PDO connection with the minimum MySQL authentication exchange.
 *
 * @param mixed $listener
 * @return resource
 */
function acceptConnection($listener)
{
    $socket = stream_socket_accept($listener, 3);

    if ($socket === false) {
        throw new RuntimeException('PDO did not connect to the loopback server.');
    }

    stream_set_timeout($socket, 3);
    $capabilities = 0x00088201;
    packet($socket, "\x0a8.0.0-test\0" . pack('V', 1) . "12345678\0"
        . pack('v', $capabilities & 0xFFFF) . "\x21" . pack('v', 2)
        . pack('v', $capabilities >> 16) . "\x15" . str_repeat("\0", 10)
        . "abcdefghijkl\0mysql_native_password\0", 0);
    receivePacket($socket);
    packet($socket, "\0\0\0\x02\0\0\0", 2);

    return $socket;
}

$listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
$address = stream_socket_get_name($listener, false);
$port = (int) substr(strrchr($address, ':'), 1);
[$parentControl, $childControl] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$child = pcntl_fork();
if ($child === 0) {
    fclose($parentControl);
    $socket = null;

    try {
        $socket = acceptConnection($listener);
        stream_set_timeout($childControl, 3);
        fread($childControl, 1);
        socket_set_option(socket_import_stream($socket), SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        fclose($socket);
        fwrite($childControl, 'x');

        $socket = acceptConnection($listener);

        if (receivePacket($socket) !== "\x03SET @test = 1") {
            throw new RuntimeException('The reconnect did not retry the expected statement.');
        }

        packet($socket, "\0\0\0\x02\0\0\0", 1);
        receivePacket($socket); // COM_QUIT after the successful retry.
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }

        fclose($childControl);
        fclose($listener);
    }

    exit(0);
}

fclose($childControl);
fclose($listener);
$hooked = $argv[2] === 'yes';
Runtime::enableCoroutine($hooked ? SWOOLE_HOOK_ALL : 0);

$execute = static function () use ($port, $parentControl): void {
    $connect = static fn (): PDO => new PDO("mysql:host=127.0.0.1;port={$port}", 'user', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::ATTR_TIMEOUT => 3,
    ]);
    $connection = new PdoConnection($connect());
    $reconnects = 0;
    $connection->setReconnector(static function (PdoConnection $connection) use ($connect, &$reconnects): void {
        ++$reconnects;
        $connection->disconnect();
        $connection->setPdo($connect());
    });

    set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
        if (error_reporting() & $severity) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
    });

    try {
        fwrite($parentControl, 'x');
        stream_set_timeout($parentControl, 3);

        if (fread($parentControl, 1) !== 'x') {
            throw new RuntimeException('The server did not reset the connection.');
        }

        $result = $connection->statement('SET @test = 1');
        $connection->disconnect();

        echo json_encode(['result' => $result, 'reconnects' => $reconnects], JSON_THROW_ON_ERROR);
    } finally {
        restore_error_handler();
        @$connection->disconnect();
    }
};

try {
    $failure = null;
    run(static function () use ($execute, &$failure): void {
        try {
            $execute();
        } catch (Throwable $exception) {
            $failure = $exception;
        }
    });

    if ($failure !== null) {
        throw $failure;
    }

    pcntl_waitpid($child, $status);

    if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        throw new RuntimeException('The loopback server failed.');
    }
} finally {
    fclose($parentControl);

    if (pcntl_waitpid($child, $status, WNOHANG) === 0) {
        posix_kill($child, SIGKILL);
        pcntl_waitpid($child, $status);
    }
}
