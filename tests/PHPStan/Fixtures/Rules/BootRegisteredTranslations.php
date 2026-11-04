<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Rules;

class BootRegisteredTranslations
{
    /**
     * Look up translations registered while the application boots.
     */
    public function lookup(): void
    {
        __('package::lookups.present');
        __('package::lookups.missing');
        __('added.line');
        __('added.missing');
        __('added.missing', [], 'fr');
    }
}
