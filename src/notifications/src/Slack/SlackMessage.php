<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Slack;

use Closure;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Notifications\Slack\BlockKit\Blocks\ActionsBlock;
use Hypervel\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Hypervel\Notifications\Slack\BlockKit\Blocks\DividerBlock;
use Hypervel\Notifications\Slack\BlockKit\Blocks\HeaderBlock;
use Hypervel\Notifications\Slack\BlockKit\Blocks\ImageBlock;
use Hypervel\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Hypervel\Notifications\Slack\Contracts\BlockContract;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Conditionable;
use JsonException;
use LogicException;

class SlackMessage implements Arrayable
{
    use Conditionable;

    /**
     * The channel to send the message on.
     */
    protected ?string $channel = null;

    /**
     * The text content of the message.
     */
    protected ?string $text = null;

    /**
     * The message's blocks.
     *
     * @var array<array<mixed>|BlockContract>
     */
    protected array $blocks = [];

    /**
     * The user emoji icon for the message.
     */
    protected ?string $icon = null;

    /**
     * The user image icon for the message.
     */
    protected ?string $image = null;

    /**
     * The JSON metadata for the message.
     */
    protected ?EventMetadata $metaData = null;

    /**
     * Indicates if you want the message to parse markdown or not.
     */
    protected ?bool $mrkdwn = null;

    /**
     * Indicates if you want a preview of links inlined in the message.
     */
    protected ?bool $unfurlLinks = null;

    /**
     * Indicates if you want a preview of links to media inlined in the message.
     */
    protected ?bool $unfurlMedia = null;

    /**
     * The username to send the message as.
     */
    protected ?string $username = null;

    /**
     * Unique, per-channel, timestamp for each message. If provided, send message as a thread reply to this message.
     */
    protected ?string $threadTs = null;

    /**
     * If sending message as reply to thread, whether to 'broadcast' a reference to the thread reply to the parent conversation.
     */
    protected ?bool $broadcastReply = null;

    /**
     * Set the Slack channel the message should be sent to.
     */
    public function to(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    /**
     * Set the fallback and notification text of the Slack message.
     */
    public function text(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Add a new Actions block to the message.
     */
    public function actionsBlock(Closure $callback): static
    {
        $this->blocks[] = $block = new ActionsBlock();

        $callback($block);

        return $this;
    }

    /**
     * Add a new Context block to the message.
     */
    public function contextBlock(Closure $callback): static
    {
        $this->blocks[] = $block = new ContextBlock();

        $callback($block);

        return $this;
    }

    /**
     * Add a new Divider block to the message.
     */
    public function dividerBlock(): static
    {
        $this->blocks[] = new DividerBlock();

        return $this;
    }

    /**
     * Add a new Header block to the message.
     */
    public function headerBlock(string $text, ?Closure $callback = null): static
    {
        $this->blocks[] = new HeaderBlock($text, $callback);

        return $this;
    }

    /**
     * Add a new Image block to the message.
     */
    public function imageBlock(string $url, Closure|string|null $altText = null, ?Closure $callback = null): static
    {
        if ($altText instanceof Closure) {
            $callback = $altText;
            $altText = null;
        }

        $this->blocks[] = $image = new ImageBlock($url, $altText);

        if ($callback) {
            $callback($image);
        }

        return $this;
    }

    /**
     * Add a new Section block to the message.
     */
    public function sectionBlock(Closure $callback): static
    {
        $this->blocks[] = $block = new SectionBlock();

        $callback($block);

        return $this;
    }

    /**
     * Set a custom image icon the message should use.
     */
    public function emoji(string $emoji): static
    {
        $this->image = null;
        $this->icon = $emoji;

        return $this;
    }

    /**
     * Set a custom image icon the message should use.
     */
    public function image(string $image): static
    {
        $this->icon = null;
        $this->image = $image;

        return $this;
    }

    /**
     * Set the metadata the message should include.
     */
    public function metadata(string $eventType, array $payload = []): static
    {
        $this->metaData = new EventMetadata($eventType, $payload);

        return $this;
    }

    /**
     * Disable Slack's markup parsing.
     */
    public function disableMarkdownParsing(): static
    {
        $this->mrkdwn = false;

        return $this;
    }

    /**
     * Unfurl links for rich display.
     */
    public function unfurlLinks(bool $unfurlLinks = true): static
    {
        $this->unfurlLinks = $unfurlLinks;

        return $this;
    }

    /**
     * Unfurl media for rich display.
     */
    public function unfurlMedia(bool $unfurlMedia = true): static
    {
        $this->unfurlMedia = $unfurlMedia;

        return $this;
    }

    /**
     * Set the user name for the Slack bot.
     */
    public function username(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Set the thread timestamp (message ID) to send as reply to thread.
     */
    public function threadTimestamp(?string $threadTimestamp): static
    {
        $this->threadTs = $threadTimestamp;

        return $this;
    }

    /**
     * Only applicable if threadTimestamp is set. Broadcasts a reference to the threaded reply to the parent conversation.
     */
    public function broadcastReply(?bool $broadcastReply = true): static
    {
        $this->broadcastReply = $broadcastReply;

        return $this;
    }

    /**
     * Specify a raw Block Kit Builder JSON payload for the message.
     *
     * @throws JsonException
     * @throws LogicException
     */
    public function usingBlockKitTemplate(string $template): static
    {
        $blocks = json_decode($template, true, flags: JSON_THROW_ON_ERROR);

        if (! array_key_exists('blocks', $blocks)) {
            throw new LogicException('The blocks array key is missing.');
        }

        array_push($this->blocks, ...$blocks['blocks']);

        return $this;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        if (empty($this->blocks) && $this->text === null) {
            throw new LogicException('Slack messages must contain at least a text message or block.');
        }

        if (count($this->blocks) > 50) {
            throw new LogicException('Slack messages can only contain up to 50 blocks.');
        }

        $optionalFields = array_filter([
            'text' => $this->text,
            'blocks' => ! empty($this->blocks) ? array_map(fn ($block) => $block instanceof BlockContract ? $block->toArray() : $block, $this->blocks) : null,
            'icon_emoji' => $this->icon,
            'icon_url' => $this->image,
            'metadata' => $this->metaData?->toArray(),
            'mrkdwn' => $this->mrkdwn,
            'thread_ts' => $this->threadTs,
            'reply_broadcast' => $this->broadcastReply,
            'unfurl_links' => $this->unfurlLinks,
            'unfurl_media' => $this->unfurlMedia,
            'username' => $this->username,
        ], fn ($value) => $value !== null);

        return array_merge([
            'channel' => $this->channel,
        ], $optionalFields);
    }

    /**
     * Dump the payload as a URL to the Slack Block Kit Builder.
     */
    public function dd(bool $raw = false)
    {
        if ($raw) {
            dd($this->toArray());
        }

        dd('https://app.slack.com/block-kit-builder#' . rawurlencode(json_encode(Arr::except($this->toArray(), ['username', 'text', 'channel']))));
    }
}
