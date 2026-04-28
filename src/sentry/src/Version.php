<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Container\Container;
use Hypervel\Foundation\PackageManifest;

final class Version
{
    public const SDK_IDENTIFIER = 'sentry.php.hypervel';

    public const SDK_VERSION = '4.21.1';

    public static function getSdkIdentifier(): string
    {
        return self::SDK_IDENTIFIER;
    }

    public static function getSdkVersion(): string
    {
        return Container::getInstance()->make(PackageManifest::class)->version('hypervel/sentry')
            ?? self::SDK_VERSION;
    }
}
