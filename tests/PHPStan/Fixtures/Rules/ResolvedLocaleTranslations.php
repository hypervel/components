<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Rules;

class ResolvedLocaleTranslations
{
    /**
     * Look up translations in the locales a determineLocalesUsing() callback adds.
     */
    public function lookup(): void
    {
        __('package::lookups.spanish');
        __('package::lookups.missing');
    }
}
