<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Messages;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hyperf\Support\Traits\InteractsWithTime;

class SlackAttachment
{
    use InteractsWithTime;

    /**
     * The attachment's title.
     */
    public ?string $title = null;

    /**
     * The attachment's URL.
     */
    public ?string $url = null;

    /**
     * The attachment's pretext.
     */
    public ?string $pretext = null;

    /**
     * The attachment's text content.
     */
    public ?string $content = null;

    /**
     * A plain-text summary of the attachment.
     */
    public ?string $fallback = null;

    /**
     * The attachment's color.
     */
    public ?string $color = null;

    /**
     * The attachment's fields.
     */
    public array $fields = [];

    /**
     * The fields containing markdown.
     */
    public array $markdown = [];

    /**
     * The attachment's image url.
     */
    public ?string $imageUrl = null;

    /**
     * The attachment's thumb url.
     */
    public ?string $thumbUrl = null;

    /**
     * The attachment's actions.
     */
    public array $actions = [];

    /**
     * The attachment author's name.
     */
    public ?string $authorName = null;

    /**
     * The attachment author's link.
     */
    public ?string $authorLink = null;

    /**
     * The attachment author's icon.
     */
    public ?string $authorIcon = null;

    /**
     * The attachment's footer.
     */
    public ?string $footer = null;

    /**
     * The attachment's footer icon.
     */
    public ?string $footerIcon = null;

    /**
     * The attachment's timestamp.
     */
    public int $timestamp = 0;

    /**
     * The attachment's callback ID.
     */
    public string $callbackId = '';

    /**
     * Set the title of the attachment.
     */
    public function title(string $title, ?string $url = null): static
    {
        $this->title = $title;
        $this->url = $url;

        return $this;
    }

    /**
     * Set the pretext of the attachment.
     */
    public function pretext(string $pretext): static
    {
        $this->pretext = $pretext;

        return $this;
    }

    /**
     * Set the content (text) of the attachment.
     */
    public function content(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    /**
     * A plain-text summary of the attachment.
     */
    public function fallback(string $fallback): static
    {
        $this->fallback = $fallback;

        return $this;
    }

    /**
     * Set the color of the attachment.
     */
    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /**
     * Add a field to the attachment.
     */
    public function field(Closure|string $title, string $content = ''): static
    {
        if (is_callable($title)) {
            $callback = $title;

            $callback($attachmentField = new SlackAttachmentField());

            $this->fields[] = $attachmentField;

            return $this;
        }

        $this->fields[$title] = $content;

        return $this;
    }

    /**
     * Set the fields of the attachment.
     */
    public function fields(array $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Set the fields containing markdown.
     */
    public function markdown(array $fields): static
    {
        $this->markdown = $fields;

        return $this;
    }

    /**
     * Set the image URL.
     */
    public function image(string $url): static
    {
        $this->imageUrl = $url;

        return $this;
    }

    /**
     * Set the URL to the attachment thumbnail.
     */
    public function thumb(string $url): static
    {
        $this->thumbUrl = $url;

        return $this;
    }

    /**
     * Add an action (button) under the attachment.
     */
    public function action(string $title, string $url, string $style = ''): static
    {
        $this->actions[] = [
            'type' => 'button',
            'text' => $title,
            'url' => $url,
            'style' => $style,
        ];

        return $this;
    }

    /**
     * Set the author of the attachment.
     */
    public function author(string $name, ?string $link = null, ?string $icon = null): static
    {
        $this->authorName = $name;
        $this->authorLink = $link;
        $this->authorIcon = $icon;

        return $this;
    }

    /**
     * Set the footer content.
     */
    public function footer(string $footer): static
    {
        $this->footer = $footer;

        return $this;
    }

    /**
     * Set the footer icon.
     */
    public function footerIcon(string $icon): static
    {
        $this->footerIcon = $icon;

        return $this;
    }

    /**
     * Set the timestamp a DateTimeInterface, DateInterval, or the number of seconds that should be added to the current time.
     */
    public function timestamp(DateInterval|DateTimeInterface|int $timestamp): static
    {
        $this->timestamp = $this->availableAt($timestamp);

        return $this;
    }

    /**
     * Set the callback ID.
     */
    public function callbackId(string $callbackId): static
    {
        $this->callbackId = $callbackId;

        return $this;
    }
}
