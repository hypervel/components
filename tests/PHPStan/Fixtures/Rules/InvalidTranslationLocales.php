<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Rules;

class InvalidTranslationLocales
{
    /**
     * Look up translations for a locale that names another directory.
     */
    public function lookup(): void
    {
        __('package::lookups.present', [], '../fr');
        __('package::lookups.missing');
    }
}
