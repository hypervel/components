<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Support\Composer;

final class Version
{
    public const SDK_IDENTIFIER = 'sentry.php.hypervel';

    public const SDK_VERSION = '3.1.0';

    public static function getSdkIdentifier(): string
    {
        return self::SDK_IDENTIFIER;
    }

    public static function getSdkVersion(): string
    {
        return Composer::getVersions()['hypervel/sentry'] ?? self::SDK_VERSION;
    }
}
