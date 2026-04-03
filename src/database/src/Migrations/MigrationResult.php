<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

enum MigrationResult: int
{
    case Success = 1;
    case Failure = 2;
    case Skipped = 3;
}
