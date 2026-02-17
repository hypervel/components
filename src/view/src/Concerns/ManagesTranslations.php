<?php

declare(strict_types=1);

namespace Hypervel\View\Concerns;

use Hypervel\Context\Context;

trait ManagesTranslations
{
    /**
     * Context key for translation replacements.
     */
    protected const TRANSLATION_REPLACEMENTS_CONTEXT_KEY = 'translation_replacements';

    /**
     * Start a translation block.
     */
    public function startTranslation(array $replacements = []): void
    {
        ob_start();

        Context::set(static::TRANSLATION_REPLACEMENTS_CONTEXT_KEY, $replacements);
    }

    /**
     * Render the current translation.
     */
    public function renderTranslation(): string
    {
        return $this->container->make('translator')->get(
            trim(ob_get_clean()),
            Context::get(static::TRANSLATION_REPLACEMENTS_CONTEXT_KEY, [])
        );
    }
}
