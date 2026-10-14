<?php

declare(strict_types=1);

namespace Hypervel\Passport\Enums;

enum KeyStore: string
{
    case Files = 'files';
    case Database = 'database';
}
