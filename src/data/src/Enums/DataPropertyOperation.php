<?php

declare(strict_types=1);

namespace Hypervel\Data\Enums;

enum DataPropertyOperation
{
    case Copy;
    case Builtin;
    case Enum;
    case Date;
    case Data;
}
