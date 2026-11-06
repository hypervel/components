<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

enum Identity: string
{
    case Always = 'always';
    case ByDefault = 'by default';
}
