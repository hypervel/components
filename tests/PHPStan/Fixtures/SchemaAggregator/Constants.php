<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

class Constants
{
    public const USERS = 'users';

    public const AMOUNT = 'amount';

    private const PREFIX = 'us';

    /** @var string */
    public const DOCUMENTED_USERS = self::PREFIX . 'ers';
}
