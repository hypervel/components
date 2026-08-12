<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Slack\BlockKit\Composites;

use Hypervel\Notifications\Slack\Contracts\ObjectContract;
use InvalidArgumentException;

class PlainTextOnlyTextObject implements ObjectContract
{
    /**
     * The formatting to use for this text object.
     */
    protected string $text;

    /**
     * Indicates whether emojis in a text field should be escaped into the colon emoji format.
     */
    protected ?bool $emoji = null;

    /**
     * Create a new plain text only text object instance.
     */
    public function __construct(string $text, int $maxLength = 3000, int $minLength = 1)
    {
        if (mb_strlen($text, 'UTF-8') < $minLength) {
            throw new InvalidArgumentException('Text must be at least ' . $minLength . ' character(s) long.');
        }

        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            if (! mb_check_encoding($text, 'UTF-8')) {
                throw new InvalidArgumentException('Text must be valid UTF-8.');
            }

            $text = mb_substr($text, 0, $maxLength - 3, 'UTF-8') . '...';
        }

        $this->text = $text;
    }

    /**
     * Indicate that emojis should be escaped into the colon emoji format.
     */
    public function emoji(): static
    {
        $this->emoji = true;

        return $this;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        $optionalFields = array_filter([
            'emoji' => $this->emoji,
        ]);

        return array_merge([
            'type' => 'plain_text',
            'text' => $this->text,
        ], $optionalFields);
    }
}
