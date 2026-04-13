<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Testbench\Contracts\Attributes\Invokable;

use function Hypervel\Testbench\package_path;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class UsesFrameworkConfiguration implements Invokable
{
    public function __invoke(ApplicationContract $app): mixed
    {
        /** @var Application $app */
        $app->bind(LoadConfiguration::class, LoadConfiguration::class);
        $app->useConfigPath(package_path('src', 'foundation', 'config'));
        $app->dontMergeFrameworkConfiguration();

        return null;
    }
}
