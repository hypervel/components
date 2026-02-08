<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Config\Repository;

class Switcher
{
    public function __construct(protected Repository $config)
    {
    }

    public function isEnabled(string $key, bool $default = true): bool
    {
        return (bool) $this->config->get('sentry.enable.' . $key, $default);
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
