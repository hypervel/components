<?php

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
if ($server === false) {
    throw new RuntimeException($message, $error);
}

echo stream_socket_get_name($server, false), "\n";
fflush(STDOUT);
$connection = null;
$release = null;

try {
    $connection = stream_socket_accept($server, 5);
    if ($connection === false) {
        throw new RuntimeException('The streaming client did not connect.');
    }

    stream_set_timeout($connection, 5);
    while (($line = fgets($connection)) !== "\r\n" && $line !== false);

    $first = $argv[1] === 'buffered' ? "{\"id\":1}\n" : '';
    $last = "{\"id\":2}\n";
    $length = strlen($first . $last);
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/x-ndjson\r\nContent-Length: {$length}\r\nConnection: close\r\n\r\n" . $first);
    fflush($connection);

    // A second connection releases the final record independently of the reader.
    $release = stream_socket_accept($server, 5);
    if ($release === false) {
        throw new RuntimeException('The streaming test did not release the server.');
    }

    fwrite($connection, $last);
} finally {
    if (is_resource($release)) {
        fclose($release);
    }
    if (is_resource($connection)) {
        fclose($connection);
    }
    fclose($server);
}
