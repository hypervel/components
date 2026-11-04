<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Rules;

use Hypervel\Contracts\Translation\Translator;
use Hypervel\Support\Facades\Lang;

class TranslationLookups
{
    /**
     * Look up translations with known and unknown lookup arguments.
     *
     * @param array<mixed> $arguments
     */
    public function lookup(Translator $translator, string $locale, bool $french, bool $fallback, array $arguments): void
    {
        __('lookups.present');
        __('lookups.missing');
        __('lookups.identical');
        __('unknown.missing');

        __('lookups.missing', [], 'fr');
        __('lookups.present', [], 'fr');
        Lang::get('lookups.present', [], 'fr', false);
        Lang::get('lookups.french', [], 'fr', false);
        Lang::get('lookups.present', [], 'fr', $fallback);
        __('lookups.missing', [], $locale);
        __('lookups.missing', [], $french ? 'fr' : 'en');
        __('lookups.missing', [], $french ? 'fr' : null);

        trans('lookups.missing', [], 'fr');
        trans_choice('lookups.missing', 1, [], 'fr');
        Lang::choice('lookups.missing', 1, [], 'fr');
        $translator->get('lookups.missing', locale: 'fr');
        $translator->choice('lookups.missing', 1, locale: 'fr');
        __(locale: 'fr', key: 'lookups.missing');
        __('lookups.missing', ...$arguments);

        trans_choice('lookups.apples', 1, [], 'fr');
        __('lookups.apples', [], 'fr');
    }
}
