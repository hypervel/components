<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hyperf\Contract\ConfigInterface;

class Switcher
{
    public function __construct(protected ConfigInterface $config)
    {
    }

    public function isBreadcrumbEnable(string $key): bool
    {
        return (bool) $this->config->get('sentry.breadcrumbs.' . $key, true);
    }

    public function isTracingEnable(string $key): bool
    {
        if (! $this->config->get('sentry.enable_tracing', true)) {
            return false;
        }

        return (bool) $this->config->get('sentry.tracing.' . $key, true);
    }
}
