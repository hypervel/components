<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Channels;

use GuzzleHttp\Client as HttpClient;
use Hypervel\Notifications\Messages\SlackAttachment;
use Hypervel\Notifications\Messages\SlackAttachmentField;
use Hypervel\Notifications\Messages\SlackMessage as LegacySlackMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\Slack\SlackMessage;
use Hypervel\Support\Collection;
use Psr\Http\Message\ResponseInterface;

class SlackWebhookChannel
{
    /**
     * Create a new Slack channel instance.
     */
    public function __construct(
        protected HttpClient $http
    ) {
    }

    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): ?ResponseInterface
    {
        if (! $url = $notifiable->routeNotificationFor('slack', $notification)) {
            return null;
        }

        return $this->http->post($url, $this->buildJsonPayload(
            $notification->toSlack($notifiable) // @phpstan-ignore method.notFound
        ));
    }

    /**
     * Build up a JSON payload for the Slack webhook.
     */
    public function buildJsonPayload(SlackMessage|LegacySlackMessage $message): array
    {
        if ($message instanceof SlackMessage) {
            return ['json' => $message->toArray()];
        }

        $optionalFields = array_filter([
            'channel' => data_get($message, 'channel'),
            'icon_emoji' => data_get($message, 'icon'),
            'icon_url' => data_get($message, 'image'),
            'link_names' => data_get($message, 'linkNames') ?: null,
            'unfurl_links' => data_get($message, 'unfurlLinks'),
            'unfurl_media' => data_get($message, 'unfurlMedia'),
            'username' => data_get($message, 'username'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return array_merge([
            'json' => array_merge([
                'text' => $message->content,
                'attachments' => $this->attachments($message),
            ], $optionalFields),
        ], $message->http);
    }

    /**
     * Format the message's attachments.
     */
    protected function attachments(LegacySlackMessage $message): array
    {
        return Collection::make($message->attachments)->map(function (SlackAttachment $attachment) use ($message): array {
            return array_filter([
                'actions' => $attachment->actions,
                'author_icon' => $attachment->authorIcon,
                'author_link' => $attachment->authorLink,
                'author_name' => $attachment->authorName,
                'callback_id' => $attachment->callbackId,
                'color' => $attachment->color ?: $message->color(),
                'fallback' => $attachment->fallback,
                'fields' => $this->fields($attachment),
                'footer' => $attachment->footer,
                'footer_icon' => $attachment->footerIcon,
                'image_url' => $attachment->imageUrl,
                'mrkdwn_in' => $attachment->markdown,
                'pretext' => $attachment->pretext,
                'text' => $attachment->content,
                'thumb_url' => $attachment->thumbUrl,
                'title' => $attachment->title,
                'title_link' => $attachment->url,
                'ts' => $attachment->timestamp,
            ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        })->all();
    }

    /**
     * Format the attachment's fields.
     */
    protected function fields(SlackAttachment $attachment): array
    {
        return Collection::make($attachment->fields)->map(function (mixed $value, int|string $key): array {
            if ($value instanceof SlackAttachmentField) {
                return $value->toArray();
            }

            return ['title' => $key, 'value' => $value, 'short' => true];
        })->values()->all();
    }
}
