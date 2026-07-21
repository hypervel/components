<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

enum Compression: string
{
    case Identity = 'identity';
    case Gzip = 'gzip';
}
