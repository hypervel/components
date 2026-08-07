<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Translation;

use Countable;

interface Translator
{
    /**
     * Get the translation for a given key.
     */
    public function get(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): array|string;

    /**
     * Get the specified string translation value.
     */
    public function string(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): string;

    /**
     * Get the specified array translation value.
     *
     * @return array<array-key, mixed>
     */
    public function array(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): array;

    /**
     * Get a translation according to an integer value.
     */
    public function choice(string $key, array|Countable|float|int $number, array $replace = [], ?string $locale = null): string;

    /**
     * Get the current locale being used.
     */
    public function getLocale(): string;

    /**
     * Set the current locale.
     */
    public function setLocale(string $locale): void;

    /**
     * Get the fallback locale being used.
     */
    public function getFallback(): string;

    /**
     * Set the fallback locale being used.
     *
     * Boot-only. The fallback is shared by the worker's Translator instance and
     * affects every subsequent translation lookup in that worker. Use
     * setLocale() for a current-request locale override.
     */
    public function setFallback(string $fallback): void;
}
