<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Slack\BlockKit\Elements\Selects;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Notifications\Slack\BlockKit\Composites\TextObject;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Stringable;

class SelectOption implements Arrayable
{
    /**
     * The option text.
     */
    protected TextObject $text;

    /**
     * The option value.
     */
    protected string $value;

    /**
     * Create a new select option instance.
     */
    public function __construct(string $text, Stringable|string|int|float|bool $value)
    {
        $this->text($text);
        $this->value($value);
    }

    /**
     * Set the option's text value.
     */
    protected function text(string $text): void
    {
        $this->text = new TextObject($text, 75);
    }

    /**
     * Set the option's value.
     */
    protected function value(Stringable|string|int|float|bool $value): void
    {
        /** @var string $normalizedValue */
        $normalizedValue = preg_replace('/[^a-z0-9_\-.]/', '', Str::lower((string) $value));

        if ($normalizedValue === '') {
            throw new InvalidArgumentException('The option value must contain at least one supported character.');
        }

        $this->value = $normalizedValue;
    }

    /**
     * Convert the select option to an array.
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text->toArray(),
            'value' => $this->value,
        ];
    }
}
