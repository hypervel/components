<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Faking;

use Closure;
use Hypervel\Saloon\Exceptions\FixtureException;

/**
 * @internal
 */
class FixtureRedactor
{
    /**
     * Recursively replace matching array attributes.
     *
     * @param array<string, mixed> $source
     * @param array<string, Closure(mixed): mixed|string> $rules
     * @return array<string, mixed>
     */
    public static function recursivelyReplaceAttributes(array $source, array $rules, bool $caseSensitiveKeys = true): array
    {
        if ($caseSensitiveKeys === false) {
            $rules = array_change_key_case($rules, CASE_LOWER);
        }

        array_walk_recursive($source, static function (mixed &$value, int|string $key) use ($rules, $caseSensitiveKeys): void {
            if ($caseSensitiveKeys === false) {
                $key = strtolower((string) $key);
            }

            if (! array_key_exists($key, $rules)) {
                return;
            }

            $swappedValue = $rules[$key];

            $value = $swappedValue instanceof Closure ? $swappedValue($value) : $swappedValue;
        });

        return $source;
    }

    /**
     * Replace sensitive regular expression patterns.
     *
     * @param array<string, Closure(string): string|string> $patterns
     */
    public static function replaceSensitiveRegexPatterns(string $source, array $patterns): string
    {
        foreach ($patterns as $pattern => $replacement) {
            $matches = [];

            if (preg_match_all($pattern, $source, $matches) === false) {
                throw new FixtureException(sprintf(
                    'Unable to apply the fixture redaction pattern [%s]: %s',
                    $pattern,
                    preg_last_error_msg(),
                ));
            }

            $matches = array_unique($matches[0] ?? []);

            foreach ($matches as $match) {
                $value = $replacement instanceof Closure ? $replacement($match) : $replacement;

                $source = str_replace($match, $value, $source);
            }
        }

        return $source;
    }
}
