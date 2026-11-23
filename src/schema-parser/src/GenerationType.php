<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

enum GenerationType: string
{
    case Stored = 'stored';
    case Virtual = 'virtual';
}
