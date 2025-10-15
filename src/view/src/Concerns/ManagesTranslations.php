<?php

namespace Hypervel\View\Concerns;

trait ManagesTranslations
{
    /**
     * The translation replacements for the translation being rendered.
     *
     * @var array
     */
    protected array $translationReplacements = [];

    /**
     * Start a translation block.
     *
     * @param  array  $replacements
     * @return void
     */
    public function startTranslation(array $replacements = []): void
    {
        ob_start();

        $this->translationReplacements = $replacements;
    }

    /**
     * Render the current translation.
     *
     * @return string
     */
    public function renderTranslation(): string
    {
        return $this->container->make('translator')->get(
            trim(ob_get_clean()), $this->translationReplacements
        );
    }
}
