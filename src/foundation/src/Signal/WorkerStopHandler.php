<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Signal;

use Hyperf\Signal\Handler\WorkerStopHandler as HyperfWorkerStopHandler;
use Hypervel\Engine\Coroutine;

class WorkerStopHandler extends HyperfWorkerStopHandler
{
    public function handle(int $signal): void
    {
        Coroutine::set([
            'enable_deadlock_check' => false,
        ]);

        parent::handle($signal);
    }
}
