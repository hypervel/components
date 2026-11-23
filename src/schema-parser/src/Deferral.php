<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

enum Deferral: string
{
    case Immediate = 'immediate';
    case Deferred = 'deferred';
}
