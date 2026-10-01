<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Closure;
use Hypervel\Prompts\Support\Utils;
use Hypervel\Support\Collection;

class AutoCompletePrompt extends Prompt
{
    use Concerns\TypedValue;

    /**
     * The options for the autocomplete prompt.
     *
     * @var array<string>|Closure(string): (array<string>|Collection<int, string>)
     */
    public array|Closure $options;

    protected string $match = '';

    protected int $highlighted = 0;

    /**
     * @var null|array<string>
     */
    protected ?array $matches = null;

    /**
     * Create a new AutoCompletePrompt instance.
     *
     * @param array<string>|Closure(string): (array<string>|Collection<int, string>)|Collection<int, string> $options
     */
    public function __construct(
        public string $label,
        array|Collection|Closure $options = [],
        public string $placeholder = '',
        public string $default = '',
        public bool|string $required = false,
        public mixed $validate = null,
        public string $hint = '',
        public ?Closure $transform = null,
    ) {
        $this->options = $options instanceof Collection ? $options->all() : $options;

        $this->on('key', function ($key) {
            if (in_array($key, [Key::UP, Key::UP_ARROW])) {
                $matches = $this->matches();

                if (count($matches) > 0) {
                    $this->highlighted = ($this->highlighted - 1 + count($matches)) % count($matches);
                }

                return;
            }

            if (in_array($key, [Key::DOWN, Key::DOWN_ARROW])) {
                $matches = $this->matches();

                if (count($matches) > 0) {
                    $this->highlighted = ($this->highlighted + 1) % count($matches);
                }

                return;
            }

            if ($key === Key::TAB && $this->cursorPosition >= mb_strlen($this->typedValue)) {
                $match = $this->getMatch();

                if ($match !== '' && mb_strlen($match) > mb_strlen($this->value())) {
                    // Ghost text is showing — accept it
                    $this->typedValue = $match;
                    $this->cursorPosition = mb_strlen($match);
                } else {
                    // No ghost text — request suggestions
                    $this->matches = null;
                    $this->highlighted = 0;
                }

                return;
            }

            if (in_array($key, [Key::RIGHT, Key::RIGHT_ARROW]) && $this->cursorPosition >= mb_strlen($this->typedValue)) {
                $match = $this->getMatch();

                if ($match !== '') {
                    $this->typedValue = $match;
                    $this->cursorPosition = mb_strlen($match);
                }

                return;
            }

            // Any other key resets the highlight and match cache
            $this->highlighted = 0;
            $this->matches = null;
        });

        $this->trackTypedValue(
            $default,
            ignore: fn ($key) => in_array($key, [Key::UP, Key::UP_ARROW, Key::DOWN, Key::DOWN_ARROW]),
        );
    }

    /**
     * Get the entered value with a virtual cursor.
     */
    public function valueWithCursor(int $maxWidth): string
    {
        if ($this->value() === '') {
            return $this->dim($this->addCursor($this->placeholder, 0, $maxWidth));
        }

        $this->match = $this->getMatch();

        $ghostText = '';

        if ($this->match !== '' && mb_strlen($this->match) > mb_strlen($this->value())) {
            $ghostText = mb_substr($this->match, mb_strlen($this->value()));
        }

        // When cursor is at the end and there's ghost text, make the first
        // ghost character the inverted cursor so it flows naturally.
        if ($ghostText !== '' && $this->cursorPosition >= mb_strlen($this->value())) {
            $value = $this->addCursor($this->value() . mb_substr($ghostText, 0, 1), $this->cursorPosition, $maxWidth);
            $ghostText = mb_substr($ghostText, 1);
        } else {
            $value = $this->addCursor($this->value(), $this->cursorPosition, $maxWidth);
        }

        $remainingWidth = $maxWidth - mb_strwidth(Utils::stripEscapeSequences($value));

        if ($ghostText === '' || $remainingWidth <= 0) {
            return $value;
        }

        return $value . $this->dim(mb_strimwidth($ghostText, 0, $remainingWidth, '…'));
    }

    /**
     * Get the current matches for the typed value.
     *
     * @return array<string>
     */
    public function matches(): array
    {
        if (is_array($this->matches)) {
            return $this->matches;
        }

        if ($this->options instanceof Closure) {
            $options = ($this->options)($this->value());

            return $this->matches = array_values($options instanceof Collection ? $options->all() : $options);
        }

        return $this->matches = array_values(array_filter(
            $this->options,
            fn (string $option): bool => str_starts_with(mb_strtolower($option), mb_strtolower($this->value())),
        ));
    }

    /**
     * Get the current match.
     */
    protected function getMatch(): string
    {
        return $this->matches()[$this->highlighted] ?? '';
    }
}
