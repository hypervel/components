<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Messages;

class SlackAttachmentField
{
    /**
     * The title field of the attachment field.
     */
    protected ?string $title = null;

    /**
     * The content of the attachment field.
     */
    protected ?string $content = null;

    /**
     * Whether the content is short.
     */
    protected bool $short = true;

    /**
     * Set the title of the field.
     */
    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Set the content of the field.
     */
    public function content(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Indicates that the content should not be displayed side-by-side with other fields.
     */
    public function long(): static
    {
        $this->short = false;

        return $this;
    }

    /**
     * Get the array representation of the attachment field.
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'value' => $this->content,
            'short' => $this->short,
        ];
    }
}
