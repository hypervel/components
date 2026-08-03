<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Signal;

interface SignalHandler
{
    public const string WORKER = 'worker';

    public const string SERVER_PROCESS = 'server-process';

    /**
     * Get the signals this handler listens for.
     *
     * @return array<self::SERVER_PROCESS|self::WORKER, list<int>>
     */
    public function signals(): array;

    /**
     * Handle the received signal.
     */
    public function handle(int $signal): void;
}
