<?php

declare(strict_types=1);

namespace Hypervel\Queue;

enum WorkerStopReason: string
{
    case Interrupted = 'interrupted';
    case MaxJobsExceeded = 'max_jobs';
    case MaxMemoryExceeded = 'memory';
    case MaxTimeExceeded = 'max_time';
    case QueueEmpty = 'empty';
    case ReceivedRestartSignal = 'restart_signal';
}
