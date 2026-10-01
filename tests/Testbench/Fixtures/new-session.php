<?php

declare(strict_types=1);

// Lead a new process group so tests can deliver signals the way a terminal does.
if (posix_setsid() === -1) {
    fwrite(STDERR, 'Unable to start a new session.' . PHP_EOL);
    exit(1);
}

pcntl_exec(PHP_BINARY, array_slice($argv, 1));

fwrite(STDERR, 'Unable to execute the command.' . PHP_EOL);
exit(1);
