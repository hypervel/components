<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Slack\BlockKit\Elements\Selects;

use Hypervel\Notifications\Slack\BlockKit\Composites\PlainTextOnlyTextObject;
use Hypervel\Notifications\Slack\Contracts\AccessoryContract;
use InvalidArgumentException;

abstract class SelectElement implements AccessoryContract
{
    /**
     * An identifier for this action.
     *
     * You can use this when you receive an interaction payload to identify the source of the action.
     *
     * Should be unique among all other action_ids in the containing block.
     *
     * Maximum length for this field is 255 characters.
     */
    protected string $actionId;

    /**
     * A plain_text only text object that defines the placeholder text shown on the menu.
     *
     * Maximum length for the text in this field is 150 characters.
     */
    protected ?PlainTextOnlyTextObject $placeholder = null;

    /**
     * Indicates whether the element should automatically gain focus when the view loads.
     *
     * When set to `true`, this element will automatically receive focus in the UI.
     * Useful for prioritizing user interaction.
     */
    protected ?bool $focusOnLoad = null;

    /**
     * Set the action ID for the select.
     */
    public function id(string $id): static
    {
        if (mb_strlen($id, 'UTF-8') > 255) {
            throw new InvalidArgumentException('Maximum length for the action_id field is 255 characters.');
        }

        $this->actionId = $id;

        return $this;
    }

    /**
     * Set the placeholder text.
     */
    public function placeholder(string $text): static
    {
        $this->placeholder = new PlainTextOnlyTextObject($text, 150);

        return $this;
    }

    /**
     * Set whether the element should automatically gain focus when the view loads.
     */
    public function focus(bool $focus = true): static
    {
        $this->focusOnLoad = $focus;

        return $this;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return array_filter([
            'action_id' => $this->actionId,
            'placeholder' => $this->placeholder?->toArray(),
            'focus_on_load' => $this->focusOnLoad,
        ], static fn ($value): bool => $value !== null);
    }
}
