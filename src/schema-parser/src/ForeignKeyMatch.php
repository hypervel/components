<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

enum ForeignKeyMatch: string
{
    case Simple = 'simple';
    case Full = 'full';
    case Partial = 'partial';
}
