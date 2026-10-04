<?php

declare(strict_types=1);

namespace Hypervel\Data\Enums;

enum DataPropertyOperation: string
{
    case Copy = 'Copy';
    case Builtin = 'Builtin';
    case Enum = 'Enum';
    case Date = 'Date';
    case Data = 'Data';
}
