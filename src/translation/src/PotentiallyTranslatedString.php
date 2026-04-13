<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Countable;
use Hypervel\Contracts\Translation\Translator;
use Stringable;

class PotentiallyTranslatedString implements Stringable
{
    /**
     * The translated string.
     */
    protected ?string $translation = null;

    /**
     * Create a new potentially translated string.
     *
     * @param string $string the string that may be translated
     * @param Translator $translator the validator that may perform the translation
     */
    public function __construct(
        protected string $string,
        protected Translator $translator
    ) {
    }

    /**
     * Translate the string.
     */
    public function translate(array $replace = [], ?string $locale = null): static
    {
        $this->translation = $this->translator->get($this->string, $replace, $locale);

        return $this;
    }

    /**
     * Translates the string based on a count.
     */
    public function translateChoice(array|Countable|float|int $number, array $replace = [], ?string $locale = null): static
    {
        $this->translation = $this->translator->choice($this->string, $number, $replace, $locale);

        return $this;
    }

    /**
     * Get the original string.
     */
    public function original(): string
    {
        return $this->string;
    }

    /**
     * Get the potentially translated string.
     */
    public function __toString(): string
    {
        return $this->translation ?? $this->string;
    }

    /**
     * Get the potentially translated string.
     */
    public function toString(): string
    {
        return (string) $this;
    }
}
