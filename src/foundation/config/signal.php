<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Signal Handlers
    |--------------------------------------------------------------------------
    |
    | Register signal handler classes that will be resolved when the server
    | starts. Each handler implements SignalHandlerInterface and defines
    | which signals it listens for and how to handle them.
    |
    | Handlers can be registered with a priority (numeric value). Higher
    | priority handlers are initialized first. Use class name as the key
    | and priority as the value, or just list the class name for default
    | priority (0).
    |
    | By default, no worker signal handlers are registered. Swoole's native
    | shutdown path handles worker exit via the onWorkerExit callback, which
    | resumes the WORKER_EXIT coordinator to unwind long-running coroutines.
    | Custom handlers should only be added when application-specific shutdown
    | logic is needed beyond the framework's built-in lifecycle.
    |
    */

    'handlers' => [],

    /*
    |--------------------------------------------------------------------------
    | Signal Wait Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds for each signal wait iteration. The signal
    | listener coroutine will wait for this duration before checking if
    | the manager has been stopped. Lower values mean faster shutdown
    | response but slightly more CPU usage.
    |
    */

    'timeout' => 5.0,
];
