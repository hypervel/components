<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Messages;

use Closure;

class SlackMessage
{
    /**
     * The "level" of the notification (info, success, warning, error).
     */
    public string $level = 'info';

    /**
     * The username to send the message from.
     */
    public ?string $username = null;

    /**
     * The user emoji icon for the message.
     */
    public ?string $icon = null;

    /**
     * The user image icon for the message.
     */
    public ?string $image = null;

    /**
     * The channel to send the message on.
     */
    public ?string $channel = null;

    /**
     * The text content of the message.
     */
    public ?string $content = null;

    /**
     * Indicates if channel names and usernames should be linked.
     */
    public bool $linkNames = false;

    /**
     * Indicates if a preview of links should be inlined in the message.
     */
    public bool $unfurlLinks = false;

    /**
     * Indicates if a preview of links to media should be inlined in the message.
     */
    public bool $unfurlMedia = false;

    /**
     * The message's attachments.
     */
    public array $attachments = [];

    /**
     * Additional request options for the Guzzle HTTP client.
     */
    public array $http = [];

    /**
     * Indicate that the notification gives information about an operation.
     */
    public function info(): static
    {
        $this->level = 'info';

        return $this;
    }

    /**
     * Indicate that the notification gives information about a successful operation.
     */
    public function success(): static
    {
        $this->level = 'success';

        return $this;
    }

    /**
     * Indicate that the notification gives information about a warning.
     */
    public function warning(): static
    {
        $this->level = 'warning';

        return $this;
    }

    /**
     * Indicate that the notification gives information about an error.
     */
    public function error(): static
    {
        $this->level = 'error';

        return $this;
    }

    /**
     * Set a custom username and optional emoji icon for the Slack message.
     */
    public function from(string $username, ?string $icon = null): static
    {
        $this->username = $username;

        if (! is_null($icon)) {
            $this->icon = $icon;
        }

        return $this;
    }

    /**
     * Set a custom image icon the message should use.
     */
    public function image(string $image): static
    {
        $this->image = $image;

        return $this;
    }

    /**
     * Set the Slack channel the message should be sent to.
     */
    public function to(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    /**
     * Set the content of the Slack message.
     */
    public function content(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Define an attachment for the message.
     */
    public function attachment(Closure $callback): static
    {
        $this->attachments[] = $attachment = new SlackAttachment();

        $callback($attachment);

        return $this;
    }

    /**
     * Get the color for the message.
     */
    public function color(): ?string
    {
        switch ($this->level) {
            case 'success':
                return 'good';
            case 'error':
                return 'danger';
            case 'warning':
                return 'warning';
        }

        return null;
    }

    /**
     * Find and link channel names and usernames.
     */
    public function linkNames(): static
    {
        $this->linkNames = true;

        return $this;
    }

    /**
     * Unfurl links to rich display.
     */
    public function unfurlLinks(bool $unfurlLinks): static
    {
        $this->unfurlLinks = $unfurlLinks;

        return $this;
    }

    /**
     * Unfurl media to rich display.
     */
    public function unfurlMedia(bool $unfurlMedia): static
    {
        $this->unfurlMedia = $unfurlMedia;

        return $this;
    }

    /**
     * Set additional request options for the Guzzle HTTP client.
     */
    public function http(array $options): static
    {
        $this->http = $options;

        return $this;
    }
}
