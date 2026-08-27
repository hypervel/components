<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Closure;
use InvalidArgumentException;

class NumberPrompt extends Prompt
{
    use Concerns\TypedValue;

    private const string INTEGER_PATTERN = '/^[+-]?[0-9]+$/D';

    /**
     * Create a new NumberPrompt instance.
     */
    public function __construct(
        public string $label,
        public string $placeholder = '',
        public int|string $default = '',
        public bool|string $required = false,
        public mixed $validate = null,
        public string $hint = '',
        public ?Closure $transform = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?int $step = null,
    ) {
        $this->trackTypedValue((string) $default);

        $this->step = max(1, $this->step ?? 1);
        $this->min ??= PHP_INT_MIN;
        $this->max ??= PHP_INT_MAX;

        if ($this->min > $this->max) {
            throw new InvalidArgumentException('The minimum value must not be greater than the maximum value.');
        }

        $this->on('key', function (string $key) {
            match ($key) {
                Key::UP, Key::UP_ARROW => $this->increaseValue(),
                Key::DOWN, Key::DOWN_ARROW => $this->decreaseValue(),
                default => null,
            };
        });
    }

    /**
     * Parse a signed decimal integer.
     */
    public static function parseInteger(string $value): ?int
    {
        if (preg_match(self::INTEGER_PATTERN, $value) !== 1) {
            return null;
        }

        $negative = $value[0] === '-';
        $digits = ltrim(ltrim($value, '+-'), '0');

        if ($digits === '') {
            return 0;
        }

        $limit = $negative ? ltrim((string) PHP_INT_MIN, '-') : (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return null;
        }

        return (int) $value;
    }

    // REMOVED: Laravel's wrapValidation() conflicts with centralized intrinsic validation.
    // Override validateIntrinsic() instead.

    /**
     * Validate rules intrinsic to the number prompt.
     */
    public function validateIntrinsic(mixed $value): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_int($value)) {
            return $this->validateBounds($value);
        }

        if (is_string($value)) {
            $integer = static::parseInteger($value);

            if ($integer !== null) {
                return $this->validateBounds($integer);
            }

            if (preg_match(self::INTEGER_PATTERN, $value) === 1) {
                return $value[0] === '-'
                    ? 'Must be at least ' . $this->min
                    : 'Must be at most ' . $this->max;
            }
        }

        return 'Must be an integer';
    }

    /**
     * Validate the integer against the configured bounds.
     */
    private function validateBounds(int $value): ?string
    {
        if ($value < $this->min) {
            return 'Must be at least ' . $this->min;
        }

        if ($value > $this->max) {
            return 'Must be at most ' . $this->max;
        }

        return null;
    }

    /**
     * Increase the value of the prompt by the step.
     */
    protected function increaseValue(): void
    {
        if ($this->typedValue === '') {
            $value = $this->min === PHP_INT_MIN ? 1 : $this->min;
            $this->typedValue = (string) min($this->max, max($this->min, $value));
            $this->cursorPosition = mb_strlen($this->typedValue);

            return;
        }

        $value = static::parseInteger($this->typedValue);

        if ($value !== null) {
            $value = $value > PHP_INT_MAX - $this->step ? PHP_INT_MAX : $value + $this->step;
            $this->typedValue = (string) min($this->max, $value);
            $this->cursorPosition = mb_strlen($this->typedValue);
        }
    }

    /**
     * Decrease the value of the prompt by the step.
     */
    protected function decreaseValue(): void
    {
        if ($this->typedValue === '') {
            $value = $this->max === PHP_INT_MAX ? 0 : $this->max;
            $this->typedValue = (string) min($this->max, max($this->min, $value));
            $this->cursorPosition = mb_strlen($this->typedValue);

            return;
        }

        $value = static::parseInteger($this->typedValue);

        if ($value !== null) {
            $value = $value < PHP_INT_MIN + $this->step ? PHP_INT_MIN : $value - $this->step;
            $this->typedValue = (string) max($this->min, $value);
            $this->cursorPosition = mb_strlen($this->typedValue);
        }
    }

    /**
     * Get the value of the prompt.
     */
    public function value(): int|string
    {
        $value = static::parseInteger($this->typedValue);

        return $value ?? $this->typedValue;
    }

    /**
     * Get the entered value with a virtual cursor.
     */
    public function valueWithCursor(int $maxWidth): string
    {
        if ($this->typedValue === '') {
            return $this->dim($this->addCursor($this->placeholder, 0, $maxWidth));
        }

        return $this->addCursor($this->typedValue, $this->cursorPosition, $maxWidth);
    }
}
