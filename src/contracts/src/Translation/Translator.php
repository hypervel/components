<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Translation;

use Countable;

interface Translator
{
    /**
     * Get the translation for a given key.
     */
    public function get(string $key, array $replace = [], ?string $locale = null): mixed;

    /**
     * Get a translation according to an integer value.
     */
    public function choice(string $key, array|Countable|float|int $number, array $replace = [], ?string $locale = null): string;

    /**
     * Get the translation for a given key.
     */
    public function trans(string $key, array $replace = [], ?string $locale = null): array|string;

    /**
     * Get a translation according to an integer value.
     */
    public function transChoice(string $key, array|Countable|float|int $number, array $replace = [], ?string $locale = null): string;

    /**
     * Get the default locale being used.
     */
    public function getLocale(): string;

    /**
     * Set the default locale.
     */
    public function setLocale(string $locale): void;
}
