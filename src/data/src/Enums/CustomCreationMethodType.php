<?php

declare(strict_types=1);

namespace Hypervel\Data\Enums;

enum CustomCreationMethodType: string
{
    case None = 'None';
    case Object = 'Object';
    case Collection = 'Collection';
}
