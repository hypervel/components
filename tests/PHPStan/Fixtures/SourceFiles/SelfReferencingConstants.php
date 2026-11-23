<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SourceFiles;

class SelfReferencingConstants
{
    public const FIRST = self::SECOND;

    public const SECOND = self::FIRST;
}
