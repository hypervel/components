<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Signal;

interface SignalHandlerInterface
{
    public const WORKER = 1;

    public const PROCESS = 2;

    /**
     * Get the signals this handler listens for.
     *
     * @return array<array{int, int}> Array of [process type, signal] pairs
     */
    public function listen(): array;

    /**
     * Handle the received signal.
     */
    public function handle(int $signal): void;
}
