<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Countable;
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;

class Pluralizer
{
    /**
     * The cached inflector instance.
     */
    protected static ?Inflector $inflector = null;

    /**
     * The language that should be used by the inflector.
     */
    protected static string $language = 'english';

    /**
     * Uncountable non-nouns word forms.
     *
     * @var list<string>
     */
    public static array $uncountable = [
        'recommended',
        'related',
    ];

    /**
     * Get the plural form of an English word.
     */
    public static function plural(string $value, array|Countable|int $count = 2): string
    {
        if (is_countable($count)) {
            $count = count($count);
        }

        if ((int) abs($count) === 1 || static::uncountable($value) || preg_match('/^(.*)[A-Za-z0-9\x{0080}-\x{FFFF}]$/u', $value) == 0) {
            return $value;
        }

        $plural = static::inflector()->pluralize($value);

        return static::matchCase($plural, $value);
    }

    /**
     * Get the singular form of an English word.
     */
    public static function singular(string $value): string
    {
        $singular = static::inflector()->singularize($value);

        return static::matchCase($singular, $value);
    }

    /**
     * Determine if the given value is uncountable.
     */
    protected static function uncountable(string $value): bool
    {
        return in_array(strtolower($value), static::$uncountable);
    }

    /**
     * Attempt to match the case on two strings.
     */
    protected static function matchCase(string $value, string $comparison): string
    {
        $functions = ['mb_strtolower', 'mb_strtoupper', 'ucfirst', 'ucwords'];

        foreach ($functions as $function) {
            if ($function($comparison) === $comparison) {
                return $function($value);
            }
        }

        return $value;
    }

    /**
     * Get the inflector instance.
     */
    public static function inflector(): Inflector
    {
        if (is_null(static::$inflector)) {
            static::$inflector = InflectorFactory::createForLanguage(static::$language)->build();
        }

        return static::$inflector;
    }

    /**
     * Specify the language that should be used by the inflector.
     */
    public static function useLanguage(string $language): void
    {
        static::$language = $language;
        static::$inflector = null;
    }
}
