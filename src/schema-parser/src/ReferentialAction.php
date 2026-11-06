<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

enum ReferentialAction: string
{
    case Cascade = 'cascade';
    case Restrict = 'restrict';
    case SetNull = 'set null';
    case SetDefault = 'set default';
    case NoAction = 'no action';
}
