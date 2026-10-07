<?php

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
if ($server === false) {
    throw new RuntimeException($message, $error);
}

echo stream_socket_get_name($server, false), "\n";
fflush(STDOUT);
$connection = null;
$start = null;
$release = null;

try {
    $connection = stream_socket_accept($server, 5);
    if ($connection === false) {
        throw new RuntimeException('The streaming client did not connect.');
    }

    stream_set_timeout($connection, 5);
    while (($line = fgets($connection)) !== "\r\n" && $line !== false);
    fwrite(STDOUT, "REQUEST\n");

    $scripted = match ($argv[1]) {
        'informational' => "HTTP/1.1 103 Early Hints\r\nLink: </style.css>; rel=preload\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nfinal",
        'trailers' => "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nTrailer: X-Checksum\r\n\r\n5\r\nhello\r\n0\r\nX-Checksum: abc\r\n\r\n",
        default => null,
    };

    if ($scripted !== null) {
        fwrite($connection, $scripted);

        return;
    }

    if ($argv[1] === 'silent-headers') {
        $start = stream_socket_accept($server, 5);
        if ($start === false) {
            throw new RuntimeException('The streaming test did not release the headers.');
        }

        stream_set_timeout($start, 5);
        @fwrite($start, "ready\n");
        fgets($start);

        // Timeout and cancellation cases close the client before releasing us.
        @fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

        return;
    }

    if ($argv[1] === 'trickle-headers') {
        fwrite($connection, "HTTP/1.1 200 OK\r\nX-Partial: ");

        for ($index = 0; $index < 8; ++$index) {
            usleep(100000);

            // A header deadline may close the client before the line is complete.
            if (@fwrite($connection, 'a') === false) {
                return;
            }
        }

        @fwrite($connection, "\r\nContent-Length: 0\r\n\r\n");

        return;
    }

    $first = match ($argv[1]) {
        'buffered' => "{\"id\":1}\n",
        'truncated' => 'partial',
        default => '',
    };
    $last = "{\"id\":2}\n";
    $length = $argv[1] === 'truncated' ? 100 : strlen($first . $last);
    $chunked = $argv[1] === 'chunked';
    $framing = $chunked ? "Transfer-Encoding: chunked\r\n" : "Content-Length: {$length}\r\n";
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/x-ndjson\r\n{$framing}Connection: close\r\n\r\n" . $first);
    fflush($connection);

    if ($chunked) {
        // Send body data only after the caller has received the response headers.
        $start = stream_socket_accept($server, 5);
        if ($start === false) {
            throw new RuntimeException('The streaming test did not start the body.');
        }

        fwrite($connection, "9\r\n{\"id\":1}\n\r\n");
        fflush($connection);
    }

    // A second connection releases the final record independently of the reader.
    $release = stream_socket_accept($server, 5);
    if ($release === false) {
        throw new RuntimeException('The streaming test did not release the server.');
    }

    stream_set_timeout($release, 5);
    @fwrite($release, "ready\n");
    fgets($release);

    if ($argv[1] === 'truncated') {
        return;
    }

    fwrite($connection, $chunked ? "9\r\n{$last}\r\n0\r\n\r\n" : $last);
} finally {
    if (is_resource($start)) {
        fclose($start);
    }
    if (is_resource($release)) {
        fclose($release);
    }
    if (is_resource($connection)) {
        fclose($connection);
    }
    fclose($server);
}
