<?php

declare(strict_types=1);

namespace Hypervel\Inertia\DevTools\Data;

enum RequestType: string
{
    case Navigate = 'navigate';
    case Partial = 'partial';
    case Deferred = 'deferred';
    case Poll = 'poll';
    case Prefetch = 'prefetch';
    case Initial = 'initial';
    case Http = 'http';
    case Precognition = 'precognition';
}
