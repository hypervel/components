<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Countable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Translation\Translator as TranslatorContract;

/**
 * Translate the given message.
 */
function __(?string $key = null, array $replace = [], ?string $locale = null): array|string
{
    return Container::getInstance()
        ->make(TranslatorContract::class)
        ->trans($key, $replace, $locale);
}

/**
 * Translate the given message.
 *
 * @return ($key is null ? TranslatorContract : array|string)
 */
function trans(?string $key = null, array $replace = [], ?string $locale = null): array|string|TranslatorContract
{
    if (is_null($key)) {
        Container::getInstance()
            ->make(TranslatorContract::class);
    }

    return Container::getInstance()
        ->make(TranslatorContract::class)
        ->get($key, $replace, $locale);
}

/**
 * Translates the given message based on a count.
 */
function trans_choice(string $key, array|Countable|float|int $number, array $replace = [], ?string $locale = null): string
{
    return Container::getInstance()
        ->make(TranslatorContract::class)
        ->transChoice($key, $number, $replace, $locale);
}
