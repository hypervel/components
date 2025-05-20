<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hyperf\Stringable\Str as BaseStr;
use Ramsey\Uuid\Exception\InvalidUuidStringException;
use Ramsey\Uuid\Rfc4122\FieldsInterface;
use Ramsey\Uuid\UuidFactory;

class Str extends BaseStr
{
    /**
     * Determine if a given string matches a given pattern.
     *
     * @param iterable<string>|string $pattern
     * @param string $value
     * @param bool $ignoreCase
     */
    public static function is($pattern, $value, $ignoreCase = false): bool
    {
        $value = (string) $value;

        if (! is_iterable($pattern)) {
            $pattern = [$pattern];
        }

        foreach ($pattern as $pattern) {
            $pattern = (string) $pattern;

            // If the given value is an exact match we can of course return true right
            // from the beginning. Otherwise, we will translate asterisks and do an
            // actual pattern match against the two strings to see if they match.
            if ($pattern === '*' || $pattern === $value) {
                return true;
            }

            if ($ignoreCase && mb_strtolower($pattern) === mb_strtolower($value)) {
                return true;
            }

            $pattern = preg_quote($pattern, '#');

            // Asterisks are translated into zero-or-more regular expression wildcards
            // to make it convenient to check if the strings starts with the given
            // pattern such as "library/*", making any string check convenient.
            $pattern = str_replace('\*', '.*', $pattern);

            if (preg_match('#^' . $pattern . '\z#' . ($ignoreCase ? 'isu' : 'su'), $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given value is a valid UUID.
     *
     * @param mixed $value
     * @param null|'max'|int<0, 8> $version
     */
    public static function isUuid($value, $version = null): bool
    {
        if (! is_string($value)) {
            return false;
        }

        if ($version === null) {
            return preg_match('/^[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}$/D', $value) > 0;
        }

        $factory = new UuidFactory();

        try {
            $factoryUuid = $factory->fromString($value);
        } catch (InvalidUuidStringException) {
            return false;
        }

        $fields = $factoryUuid->getFields();

        if (! $fields instanceof FieldsInterface) {
            return false;
        }

        if ($version === 0 || $version === 'nil') {
            return $fields->isNil();
        }

        if ($version === 'max') {
            /* @phpstan-ignore-next-line */
            return $fields->isMax();
        }

        return $fields->getVersion() === $version;
    }
}
