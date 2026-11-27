<?php

declare(strict_types=1);

namespace Hypervel\Ai\Enums;

enum StoreDocumentStatus: string
{
    case Completed = 'completed';
    case Pending = 'pending';
    case Failed = 'failed';
}
