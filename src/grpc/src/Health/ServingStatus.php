<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Health;

enum ServingStatus: int
{
    case Unknown = 0;
    case Serving = 1;
    case NotServing = 2;
}
