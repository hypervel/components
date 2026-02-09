<?php

declare(strict_types=1);

namespace Hypervel\Support;

use ArrayAccess;
use Closure;
use Countable;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Dumpable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\Tappable;
use JsonSerializable;
use RuntimeException;
use Stringable as BaseStringable;

class Stringable implements JsonSerializable, ArrayAccess, BaseStringable
{
    use Conditionable;
    use Dumpable;
    use Macroable;
    use Tappable;

    /**
     * The underlying string value.
     */
    protected string $value;

    /**
     * Create a new instance of the class.
     */
    public function __construct(mixed $value = '')
    {
        $this->value = (string) $value;
    }

    /**
     * Return the remainder of a string after the first occurrence of a given value.
     */
    public function after(string $search): static
    {
        return new static(Str::after($this->value, $search));
    }

    /**
     * Return the remainder of a string after the last occurrence of a given value.
     */
    public function afterLast(string $search): static
    {
        return new static(Str::afterLast($this->value, $search));
    }

    /**
     * Append the given values to the string.
     */
    public function append(string ...$values): static
    {
        return new static($this->value . implode('', $values));
    }

    /**
     * Append a new line to the string.
     */
    public function newLine(int $count = 1): static
    {
        return $this->append(str_repeat(PHP_EOL, $count));
    }

    /**
     * Transliterate a UTF-8 value to ASCII.
     */
    public function ascii(string $language = 'en'): static
    {
        return new static(Str::ascii($this->value, $language));
    }

    /**
     * Get the trailing name component of the path.
     */
    public function basename(string $suffix = ''): static
    {
        return new static(basename($this->value, $suffix));
    }

    /**
     * Get the character at the specified index.
     */
    public function charAt(int $index): string|false
    {
        return Str::charAt($this->value, $index);
    }

    /**
     * Remove the given string if it exists at the start of the current string.
     */
    public function chopStart(string|array $needle): static
    {
        return new static(Str::chopStart($this->value, $needle));
    }

    /**
     * Remove the given string if it exists at the end of the current string.
     */
    public function chopEnd(string|array $needle): static
    {
        return new static(Str::chopEnd($this->value, $needle));
    }

    /**
     * Get the basename of the class path.
     */
    public function classBasename(): static
    {
        return new static(class_basename($this->value));
    }

    /**
     * Get the portion of a string before the first occurrence of a given value.
     */
    public function before(string $search): static
    {
        return new static(Str::before($this->value, $search));
    }

    /**
     * Get the portion of a string before the last occurrence of a given value.
     */
    public function beforeLast(string $search): static
    {
        return new static(Str::beforeLast($this->value, $search));
    }

    /**
     * Get the portion of a string between two given values.
     */
    public function between(string $from, string $to): static
    {
        return new static(Str::between($this->value, $from, $to));
    }

    /**
     * Get the smallest possible portion of a string between two given values.
     */
    public function betweenFirst(string $from, string $to): static
    {
        return new static(Str::betweenFirst($this->value, $from, $to));
    }

    /**
     * Convert a value to camel case.
     */
    public function camel(): static
    {
        return new static(Str::camel($this->value));
    }

    /**
     * Determine if a given string contains a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public function contains(string|iterable $needles, bool $ignoreCase = false): bool
    {
        return Str::contains($this->value, $needles, $ignoreCase);
    }

    /**
     * Determine if a given string contains all array values.
     *
     * @param iterable<string> $needles
     */
    public function containsAll(iterable $needles, bool $ignoreCase = false): bool
    {
        return Str::containsAll($this->value, $needles, $ignoreCase);
    }

    /**
     * Determine if a given string doesn't contain a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public function doesntContain(string|iterable $needles, bool $ignoreCase = false): bool
    {
        return Str::doesntContain($this->value, $needles, $ignoreCase);
    }

    /**
     * Convert the case of a string.
     */
    public function convertCase(int $mode = MB_CASE_FOLD, ?string $encoding = 'UTF-8'): static
    {
        return new static(Str::convertCase($this->value, $mode, $encoding));
    }

    /**
     * Replace consecutive instances of a given character with a single character.
     */
    public function deduplicate(string $character = ' '): static
    {
        return new static(Str::deduplicate($this->value, $character));
    }

    /**
     * Get the parent directory's path.
     */
    public function dirname(int $levels = 1): static
    {
        return new static(dirname($this->value, $levels));
    }

    /**
     * Determine if a given string ends with a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public function endsWith(string|iterable $needles): bool
    {
        return Str::endsWith($this->value, $needles);
    }

    /**
     * Determine if a given string doesn't end with a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public function doesntEndWith(string|iterable $needles): bool
    {
        return Str::doesntEndWith($this->value, $needles);
    }

    /**
     * Determine if the string is an exact match with the given value.
     */
    public function exactly(Stringable|string $value): bool
    {
        if ($value instanceof Stringable) {
            $value = $value->toString();
        }

        return $this->value === $value;
    }

    /**
     * Extracts an excerpt from text that matches the first instance of a phrase.
     */
    public function excerpt(string $phrase = '', array $options = []): ?string
    {
        return Str::excerpt($this->value, $phrase, $options);
    }

    /**
     * Explode the string into a collection.
     *
     * @return Collection<int, string>
     */
    public function explode(string $delimiter, int $limit = PHP_INT_MAX): Collection
    {
        return new Collection(explode($delimiter, $this->value, $limit));
    }

    /**
     * Split a string using a regular expression or by length.
     *
     * @return Collection<int, string>
     */
    public function split(string|int $pattern, int $limit = -1, int $flags = 0): Collection
    {
        if (filter_var($pattern, FILTER_VALIDATE_INT) !== false) {
            return new Collection(mb_str_split($this->value, $pattern)); // @phpstan-ignore return.type
        }

        $segments = preg_split($pattern, $this->value, $limit, $flags);

        return ! empty($segments) ? new Collection($segments) : new Collection(); // @phpstan-ignore return.type
    }

    /**
     * Cap a string with a single instance of a given value.
     */
    public function finish(string $cap): static
    {
        return new static(Str::finish($this->value, $cap));
    }

    /**
     * Determine if a given string matches a given pattern.
     *
     * @param iterable<string>|string $pattern
     */
    public function is(string|iterable $pattern, bool $ignoreCase = false): bool
    {
        return Str::is($pattern, $this->value, $ignoreCase);
    }

    /**
     * Determine if a given string is 7 bit ASCII.
     */
    public function isAscii(): bool
    {
        return Str::isAscii($this->value);
    }

    /**
     * Determine if a given string is valid JSON.
     */
    public function isJson(): bool
    {
        return Str::isJson($this->value);
    }

    /**
     * Determine if a given value is a valid URL.
     */
    public function isUrl(array $protocols = []): bool
    {
        return Str::isUrl($this->value, $protocols);
    }

    /**
     * Determine if a given string is a valid UUID.
     *
     * @param null|'max'|int<0, 8> $version
     */
    public function isUuid(int|string|null $version = null): bool
    {
        return Str::isUuid($this->value, $version);
    }

    /**
     * Determine if a given string is a valid ULID.
     */
    public function isUlid(): bool
    {
        return Str::isUlid($this->value);
    }

    /**
     * Determine if the given string is empty.
     */
    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    /**
     * Determine if the given string is not empty.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Convert a string to kebab case.
     */
    public function kebab(): static
    {
        return new static(Str::kebab($this->value));
    }

    /**
     * Return the length of the given string.
     */
    public function length(?string $encoding = null): int
    {
        return Str::length($this->value, $encoding);
    }

    /**
     * Limit the number of characters in a string.
     */
    public function limit(int $limit = 100, string $end = '...', bool $preserveWords = false): static
    {
        return new static(Str::limit($this->value, $limit, $end, $preserveWords));
    }

    /**
     * Convert the given string to lower-case.
     */
    public function lower(): static
    {
        return new static(Str::lower($this->value));
    }

    /**
     * Convert GitHub flavored Markdown into HTML.
     */
    public function markdown(array $options = [], array $extensions = []): static
    {
        return new static(Str::markdown($this->value, $options, $extensions));
    }

    /**
     * Convert inline Markdown into HTML.
     */
    public function inlineMarkdown(array $options = [], array $extensions = []): static
    {
        return new static(Str::inlineMarkdown($this->value, $options, $extensions));
    }

    /**
     * Masks a portion of a string with a repeated character.
     */
    public function mask(string $character, int $index, ?int $length = null, string $encoding = 'UTF-8'): static
    {
        return new static(Str::mask($this->value, $character, $index, $length, $encoding));
    }

    /**
     * Get the string matching the given pattern.
     */
    public function match(string $pattern): static
    {
        return new static(Str::match($pattern, $this->value));
    }

    /**
     * Determine if a given string matches a given pattern.
     *
     * @param iterable<string>|string $pattern
     */
    public function isMatch(string|iterable $pattern): bool
    {
        return Str::isMatch($pattern, $this->value);
    }

    /**
     * Get the string matching the given pattern.
     */
    public function matchAll(string $pattern): Collection
    {
        return Str::matchAll($pattern, $this->value);
    }

    /**
     * Determine if the string matches the given pattern.
     */
    public function test(string $pattern): bool
    {
        return $this->isMatch($pattern);
    }

    /**
     * Remove all non-numeric characters from a string.
     */
    public function numbers(): static
    {
        return new static(Str::numbers($this->value));
    }

    /**
     * Pad both sides of the string with another.
     */
    public function padBoth(int $length, string $pad = ' '): static
    {
        return new static(Str::padBoth($this->value, $length, $pad));
    }

    /**
     * Pad the left side of the string with another.
     */
    public function padLeft(int $length, string $pad = ' '): static
    {
        return new static(Str::padLeft($this->value, $length, $pad));
    }

    /**
     * Pad the right side of the string with another.
     */
    public function padRight(int $length, string $pad = ' '): static
    {
        return new static(Str::padRight($this->value, $length, $pad));
    }

    /**
     * Parse a Class@method style callback into class and method.
     *
     * @return array<int, null|string>
     */
    public function parseCallback(?string $default = null): array
    {
        return Str::parseCallback($this->value, $default);
    }

    /**
     * Call the given callback and return a new string.
     */
    public function pipe(callable $callback): static
    {
        return new static($callback($this));
    }

    /**
     * Get the plural form of an English word.
     */
    public function plural(int|array|Countable $count = 2, bool $prependCount = false): static
    {
        return new static(Str::plural($this->value, $count, $prependCount));
    }

    /**
     * Pluralize the last word of an English, studly caps case string.
     */
    public function pluralStudly(int|array|Countable $count = 2): static
    {
        return new static(Str::pluralStudly($this->value, $count));
    }

    /**
     * Pluralize the last word of an English, Pascal caps case string.
     */
    public function pluralPascal(int|array|Countable $count = 2): static
    {
        return new static(Str::pluralStudly($this->value, $count));
    }

    /**
     * Find the multi-byte safe position of the first occurrence of the given substring.
     */
    public function position(string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return Str::position($this->value, $needle, $offset, $encoding);
    }

    /**
     * Prepend the given values to the string.
     */
    public function prepend(string ...$values): static
    {
        return new static(implode('', $values) . $this->value);
    }

    /**
     * Remove any occurrence of the given string in the subject.
     *
     * @param iterable<string>|string $search
     */
    public function remove(string|iterable $search, bool $caseSensitive = true): static
    {
        return new static(Str::remove($search, $this->value, $caseSensitive));
    }

    /**
     * Reverse the string.
     */
    public function reverse(): static
    {
        return new static(Str::reverse($this->value));
    }

    /**
     * Repeat the string.
     */
    public function repeat(int $times): static
    {
        return new static(str_repeat($this->value, $times));
    }

    /**
     * Replace the given value in the given string.
     *
     * @param iterable<string>|string $search
     * @param iterable<string>|string $replace
     */
    public function replace(string|iterable $search, string|iterable $replace, bool $caseSensitive = true): static
    {
        return new static(Str::replace($search, $replace, $this->value, $caseSensitive));
    }

    /**
     * Replace a given value in the string sequentially with an array.
     *
     * @param iterable<string> $replace
     */
    public function replaceArray(string $search, iterable $replace): static
    {
        return new static(Str::replaceArray($search, $replace, $this->value));
    }

    /**
     * Replace the first occurrence of a given value in the string.
     */
    public function replaceFirst(string $search, string $replace): static
    {
        return new static(Str::replaceFirst($search, $replace, $this->value));
    }

    /**
     * Replace the first occurrence of the given value if it appears at the start of the string.
     */
    public function replaceStart(string $search, string $replace): static
    {
        return new static(Str::replaceStart($search, $replace, $this->value));
    }

    /**
     * Replace the last occurrence of a given value in the string.
     */
    public function replaceLast(string $search, string $replace): static
    {
        return new static(Str::replaceLast($search, $replace, $this->value));
    }

    /**
     * Replace the last occurrence of a given value if it appears at the end of the string.
     */
    public function replaceEnd(string $search, string $replace): static
    {
        return new static(Str::replaceEnd($search, $replace, $this->value));
    }

    /**
     * Replace the patterns matching the given regular expression.
     *
     * @param Closure|string|string[] $replace
     */
    public function replaceMatches(array|string $pattern, Closure|array|string $replace, int $limit = -1): static
    {
        if ($replace instanceof Closure) {
            return new static(preg_replace_callback($pattern, $replace, $this->value, $limit));
        }

        return new static(preg_replace($pattern, $replace, $this->value, $limit));
    }

    /**
     * Parse input from a string to a collection, according to a format.
     */
    public function scan(string $format): Collection
    {
        return new Collection(sscanf($this->value, $format));
    }

    /**
     * Remove all "extra" blank space from the given string.
     */
    public function squish(): static
    {
        return new static(Str::squish($this->value));
    }

    /**
     * Begin a string with a single instance of a given value.
     */
    public function start(string $prefix): static
    {
        return new static(Str::start($this->value, $prefix));
    }

    /**
     * Strip HTML and PHP tags from the given string.
     *
     * @param null|string|string[] $allowedTags
     */
    public function stripTags(array|string|null $allowedTags = null): static
    {
        return new static(strip_tags($this->value, $allowedTags));
    }

    /**
     * Convert the given string to upper-case.
     */
    public function upper(): static
    {
        return new static(Str::upper($this->value));
    }

    /**
     * Convert the given string to proper case.
     */
    public function title(): static
    {
        return new static(Str::title($this->value));
    }

    /**
     * Convert the given string to proper case for each word.
     */
    public function headline(): static
    {
        return new static(Str::headline($this->value));
    }

    /**
     * Convert the given string to APA-style title case.
     */
    public function apa(): static
    {
        return new static(Str::apa($this->value));
    }

    /**
     * Transliterate a string to its closest ASCII representation.
     */
    public function transliterate(?string $unknown = '?', ?bool $strict = false): static
    {
        return new static(Str::transliterate($this->value, $unknown, $strict));
    }

    /**
     * Get the singular form of an English word.
     */
    public function singular(): static
    {
        return new static(Str::singular($this->value));
    }

    /**
     * Generate a URL friendly "slug" from a given string.
     *
     * @param array<string, string> $dictionary
     */
    public function slug(string $separator = '-', ?string $language = 'en', array $dictionary = ['@' => 'at']): static
    {
        return new static(Str::slug($this->value, $separator, $language, $dictionary));
    }

    /**
     * Convert a string to snake case.
     */
    public function snake(string $delimiter = '_'): static
    {
        return new static(Str::snake($this->value, $delimiter));
    }

    /**
     * Determine if a given string starts with a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public function startsWith(string|iterable $needles): bool
    {
        return Str::startsWith($this->value, $needles);
    }

    /**
     * Determine if a given string doesn't start with a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public function doesntStartWith(string|iterable $needles): bool
    {
        return Str::doesntStartWith($this->value, $needles);
    }

    /**
     * Convert a value to studly caps case.
     */
    public function studly(): static
    {
        return new static(Str::studly($this->value));
    }

    /**
     * Convert the string to Pascal case.
     */
    public function pascal(): static
    {
        return new static(Str::pascal($this->value));
    }

    /**
     * Returns the portion of the string specified by the start and length parameters.
     */
    public function substr(int $start, ?int $length = null, string $encoding = 'UTF-8'): static
    {
        return new static(Str::substr($this->value, $start, $length, $encoding));
    }

    /**
     * Returns the number of substring occurrences.
     */
    public function substrCount(string $needle, int $offset = 0, ?int $length = null): int
    {
        return Str::substrCount($this->value, $needle, $offset, $length);
    }

    /**
     * Replace text within a portion of a string.
     *
     * @param string|string[] $replace
     * @param int|int[] $offset
     * @param null|int|int[] $length
     */
    public function substrReplace(string|array $replace, int|array $offset = 0, int|array|null $length = null): static
    {
        return new static(Str::substrReplace($this->value, $replace, $offset, $length));
    }

    /**
     * Swap multiple keywords in a string with other keywords.
     */
    public function swap(array $map): static
    {
        return new static(strtr($this->value, $map));
    }

    /**
     * Take the first or last {$limit} characters.
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return $this->substr($limit);
        }

        return $this->substr(0, $limit);
    }

    /**
     * Trim the string of the given characters.
     */
    public function trim(?string $characters = null): static
    {
        return new static(Str::trim(...array_merge([$this->value], func_get_args())));
    }

    /**
     * Left trim the string of the given characters.
     */
    public function ltrim(?string $characters = null): static
    {
        return new static(Str::ltrim(...array_merge([$this->value], func_get_args())));
    }

    /**
     * Right trim the string of the given characters.
     */
    public function rtrim(?string $characters = null): static
    {
        return new static(Str::rtrim(...array_merge([$this->value], func_get_args())));
    }

    /**
     * Make a string's first character lowercase.
     */
    public function lcfirst(): static
    {
        return new static(Str::lcfirst($this->value));
    }

    /**
     * Make a string's first character uppercase.
     */
    public function ucfirst(): static
    {
        return new static(Str::ucfirst($this->value));
    }

    /**
     * Capitalize the first character of each word in a string.
     */
    public function ucwords(string $separators = " \t\r\n\f\v"): static
    {
        return new static(Str::ucwords($this->value, $separators));
    }

    /**
     * Split a string by uppercase characters.
     *
     * @return Collection<int, string>
     */
    public function ucsplit(): Collection
    {
        return new Collection(Str::ucsplit($this->value));
    }

    /**
     * Execute the given callback if the string contains a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public function whenContains(string|iterable $needles, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->contains($needles), $callback, $default);
    }

    /**
     * Execute the given callback if the string contains all array values.
     *
     * @param iterable<string> $needles
     */
    public function whenContainsAll(iterable $needles, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->containsAll($needles), $callback, $default);
    }

    /**
     * Execute the given callback if the string is empty.
     */
    public function whenEmpty(callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->isEmpty(), $callback, $default);
    }

    /**
     * Execute the given callback if the string is not empty.
     */
    public function whenNotEmpty(callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->isNotEmpty(), $callback, $default);
    }

    /**
     * Execute the given callback if the string ends with a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public function whenEndsWith(string|iterable $needles, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->endsWith($needles), $callback, $default);
    }

    /**
     * Execute the given callback if the string doesn't end with a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public function whenDoesntEndWith(string|iterable $needles, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->doesntEndWith($needles), $callback, $default);
    }

    /**
     * Execute the given callback if the string is an exact match with the given value.
     */
    public function whenExactly(string $value, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->exactly($value), $callback, $default);
    }

    /**
     * Execute the given callback if the string is not an exact match with the given value.
     */
    public function whenNotExactly(string $value, callable $callback, ?callable $default = null): mixed
    {
        return $this->when(! $this->exactly($value), $callback, $default);
    }

    /**
     * Execute the given callback if the string matches a given pattern.
     *
     * @param iterable<string>|string $pattern
     */
    public function whenIs(string|iterable $pattern, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->is($pattern), $callback, $default);
    }

    /**
     * Execute the given callback if the string is 7 bit ASCII.
     */
    public function whenIsAscii(callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->isAscii(), $callback, $default);
    }

    /**
     * Execute the given callback if the string is a valid UUID.
     */
    public function whenIsUuid(callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->isUuid(), $callback, $default);
    }

    /**
     * Execute the given callback if the string is a valid ULID.
     */
    public function whenIsUlid(callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->isUlid(), $callback, $default);
    }

    /**
     * Execute the given callback if the string starts with a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public function whenStartsWith(string|iterable $needles, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->startsWith($needles), $callback, $default);
    }

    /**
     * Execute the given callback if the string doesn't start with a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public function whenDoesntStartWith(string|iterable $needles, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->doesntStartWith($needles), $callback, $default);
    }

    /**
     * Execute the given callback if the string matches the given pattern.
     */
    public function whenTest(string $pattern, callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->test($pattern), $callback, $default);
    }

    /**
     * Limit the number of words in a string.
     */
    public function words(int $words = 100, string $end = '...'): static
    {
        return new static(Str::words($this->value, $words, $end));
    }

    /**
     * Get the number of words a string contains.
     */
    public function wordCount(?string $characters = null): int
    {
        return Str::wordCount($this->value, $characters);
    }

    /**
     * Wrap a string to a given number of characters.
     */
    public function wordWrap(int $characters = 75, string $break = "\n", bool $cutLongWords = false): static
    {
        return new static(Str::wordWrap($this->value, $characters, $break, $cutLongWords));
    }

    /**
     * Wrap the string with the given strings.
     */
    public function wrap(string $before, ?string $after = null): static
    {
        return new static(Str::wrap($this->value, $before, $after));
    }

    /**
     * Unwrap the string with the given strings.
     */
    public function unwrap(string $before, ?string $after = null): static
    {
        return new static(Str::unwrap($this->value, $before, $after));
    }

    /**
     * Convert the string into a `HtmlString` instance.
     */
    public function toHtmlString(): HtmlString
    {
        return new HtmlString($this->value);
    }

    /**
     * Convert the string to Base64 encoding.
     */
    public function toBase64(): static
    {
        return new static(base64_encode($this->value));
    }

    /**
     * Decode the Base64 encoded string.
     */
    public function fromBase64(bool $strict = false): static
    {
        return new static(base64_decode($this->value, $strict));
    }

    /**
     * Convert the string to a vector embedding using AI.
     *
     * @return array<int, float>
     *
     * @throws RuntimeException
     */
    public function toEmbeddings(bool $cache = false): array
    {
        // TODO: Implement AI embedding conversion (requires AI service configuration)
        throw new RuntimeException('String to vector embedding conversion is not yet implemented.');
    }

    /**
     * Hash the string using the given algorithm.
     */
    public function hash(string $algorithm): static
    {
        return new static(hash($algorithm, $this->value));
    }

    /**
     * Encrypt the string.
     */
    public function encrypt(bool $serialize = false): static
    {
        return new static(encrypt($this->value, $serialize));
    }

    /**
     * Decrypt the string.
     */
    public function decrypt(bool $serialize = false): static
    {
        return new static(decrypt($this->value, $serialize));
    }

    /**
     * Dump the string.
     */
    public function dump(mixed ...$args): static
    {
        dump($this->value, ...$args);

        return $this;
    }

    /**
     * Get the underlying string value.
     */
    public function value(): string
    {
        return $this->toString();
    }

    /**
     * Get the underlying string value.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Get the underlying string value as an integer.
     */
    public function toInteger(int $base = 10): int
    {
        return intval($this->value, $base);
    }

    /**
     * Get the underlying string value as a float.
     */
    public function toFloat(): float
    {
        return (float) $this->value;
    }

    /**
     * Get the underlying string value as a boolean.
     *
     * Returns true when value is "1", "true", "on", and "yes". Otherwise, returns false.
     */
    public function toBoolean(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get the underlying string value as a Carbon instance.
     *
     * @throws \Carbon\Exceptions\InvalidFormatException
     */
    public function toDate(?string $format = null, ?string $tz = null): mixed
    {
        if (is_null($format)) {
            return Date::parse($this->value, $tz);
        }

        return Date::createFromFormat($format, $this->value, $tz);
    }

    /**
     * Get the underlying string value as a Uri instance.
     */
    public function toUri(): Uri
    {
        return Uri::of($this->value);
    }

    /**
     * Convert the object to a string when JSON encoded.
     */
    public function jsonSerialize(): string
    {
        return $this->__toString();
    }

    /**
     * Determine if the given offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->value[$offset]);
    }

    /**
     * Get the value at the given offset.
     */
    public function offsetGet(mixed $offset): string
    {
        return $this->value[$offset];
    }

    /**
     * Set the value at the given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->value[$offset] = $value;
    }

    /**
     * Unset the value at the given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->value[$offset]);
    }

    /**
     * Proxy dynamic properties onto methods.
     */
    public function __get(string $key): mixed
    {
        return $this->{$key}();
    }

    /**
     * Get the raw string value.
     */
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
