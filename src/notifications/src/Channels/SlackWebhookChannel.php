<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Channels;

use GuzzleHttp\Client as HttpClient;
use Hypervel\Support\Collection;
use Hypervel\Notifications\Messages\SlackAttachment;
use Hypervel\Notifications\Messages\SlackAttachmentField;
use Hypervel\Notifications\Messages\SlackMessage;
use Hypervel\Notifications\Notification;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class SlackWebhookChannel
{
    /**
     * Create a new Slack channel instance.
     */
    public function __construct(
        protected HttpClient $client
    ) {
    }

    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): ?ResponseInterface
    {
        if (! method_exists($notification, 'toSlack')) {
            throw new RuntimeException('Notification is missing `toSlack` method.');
        }

        if (! $url = $notifiable->routeNotificationFor('slack', $notification)) {
            return null;
        }

        return $this->client->post($url, $this->buildJsonPayload(
            $notification->toSlack($notifiable) // @phpstan-ignore-line
        ));
    }

    /**
     * Build up a JSON payload for the Slack webhook.
     */
    public function buildJsonPayload(SlackMessage $message): array
    {
        $optionalFields = array_filter([
            'channel' => data_get($message, 'channel'),
            'icon_emoji' => data_get($message, 'icon'),
            'icon_url' => data_get($message, 'image'),
            'link_names' => data_get($message, 'linkNames'),
            'unfurl_links' => data_get($message, 'unfurlLinks'),
            'unfurl_media' => data_get($message, 'unfurlMedia'),
            'username' => data_get($message, 'username'),
        ]);

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
    protected function attachments(SlackMessage $message): array
    {
        return Collection::make($message->attachments)->map(function ($attachment) use ($message) {
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
            ]);
        })->all();
    }

    /**
     * Format the attachment's fields.
     */
    protected function fields(SlackAttachment $attachment): array
    {
        return Collection::make($attachment->fields)->map(function ($value, $key) {
            if ($value instanceof SlackAttachmentField) {
                return $value->toArray();
            }

            return ['title' => $key, 'value' => $value, 'short' => true];
        })->values()->all();
    }
}
