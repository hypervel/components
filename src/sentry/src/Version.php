<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\PackageManifest;

final class Version
{
    public const string SDK_IDENTIFIER = 'sentry.php.hypervel';

    public const string SDK_VERSION = Application::VERSION;

    /**
     * Get the installed version of the hypervel/sentry package.
     *
     * Split installs may run a package version that differs from the framework version.
     */
    public static function getSdkVersion(): string
    {
        return Container::getInstance()->make(PackageManifest::class)->version('hypervel/sentry')
            ?? self::SDK_VERSION;
    }
}
