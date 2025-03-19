<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Closure;
use Hypervel\Context\ApplicationContext;

trait Localizable
{
    /**
     * Run the callback with the given locale.
     */
    public function withLocale(?string $locale, Closure $callback): mixed
    {
        if (! $locale) {
            return $callback();
        }

        /** @var \Hypervel\Foundation\Application $app */
        $app = ApplicationContext::getContainer();

        $original = $app->getLocale();

        try {
            $app->setLocale($locale);

            return $callback();
        } finally {
            $app->setLocale($original);
        }
    }
}
