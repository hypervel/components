<?php

declare(strict_types=1);

namespace Hypervel\Ai\Enums;

enum MessageStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case Paused = 'paused';
}
