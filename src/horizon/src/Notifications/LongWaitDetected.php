<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Notifications;

use Hypervel\Bus\Queueable;
use Hypervel\Horizon\Contracts\LongWaitDetectedNotification;
use Hypervel\Horizon\Horizon;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Messages\NexmoMessage;
use Hypervel\Notifications\Messages\SlackMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Hypervel\Notifications\Slack\SlackMessage as ChannelIdSlackMessage;
use Hypervel\Support\Str;

class LongWaitDetected extends Notification implements LongWaitDetectedNotification
{
    use Queueable;

    /**
     * The queue connection name.
     *
     * @var string
     */
    public $longWaitConnection;

    /**
     * The queue name.
     *
     * @var string
     */
    public $longWaitQueue;

    /**
     * The wait time in seconds.
     *
     * @var int
     */
    public $seconds;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $connection, string $queue, int $seconds)
    {
        $this->longWaitQueue = $queue;
        $this->seconds = $seconds;
        $this->longWaitConnection = $connection;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(mixed $notifiable): array
    {
        return array_filter([
            Horizon::$slackWebhookUrl ? 'slack' : null,
            Horizon::$smsNumber ? 'nexmo' : null,
            Horizon::$email ? 'mail' : null,
        ]);
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->error()
            ->subject(config('app.name') . ': Long Queue Wait Detected')
            ->greeting('Oh no! Something needs your attention.')
            ->line(sprintf(
                'The "%s" queue on the "%s" connection has a wait time of %s seconds.',
                $this->longWaitQueue,
                $this->longWaitConnection,
                $this->seconds
            ));
    }

    /**
     * Get the Slack representation of the notification.
     */
    public function toSlack(mixed $notifiable): ChannelIdSlackMessage|SlackMessage
    {
        $fromName = 'Laravel Horizon';
        $title = 'Long Wait Detected';
        $text = 'Oh no! Something needs your attention.';
        $imageUrl = 'https://laravel.com/assets/img/horizon-48px.png';

        $content = sprintf(
            '[%s] The "%s" queue on the "%s" connection has a wait time of %s seconds.',
            config('app.name'),
            $this->longWaitQueue,
            $this->longWaitConnection,
            $this->seconds
        );

        if (class_exists('\Illuminate\Notifications\Slack\SlackMessage')
            && class_exists('\Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock')
            && ! (is_string(Horizon::$slackWebhookUrl) && Str::startsWith(Horizon::$slackWebhookUrl, ['http://', 'https://']))) {
            return (new ChannelIdSlackMessage())
                ->username($fromName)
                ->image($imageUrl)
                ->text($text)
                ->headerBlock($title)
                ->sectionBlock(function (SectionBlock $block) use ($content): void { // @phpstan-ignore-line
                    $block->text($content);
                });
        }

        return (new SlackMessage()) // @phpstan-ignore-line
            ->from($fromName)
            ->to(Horizon::$slackChannel)
            ->image($imageUrl)
            ->error()
            ->content($text)
            ->attachment(function ($attachment) use ($title, $content) {
                $attachment->title($title)
                    ->content($content);
            });
    }

    /**
     * Get the Nexmo / SMS representation of the notification.
     */
    public function toNexmo(mixed $notifiable): NexmoMessage
    {
        return (new NexmoMessage())->content(sprintf( // @phpstan-ignore-line
            '[%s] The "%s" queue on the "%s" connection has a wait time of %s seconds.',
            config('app.name'),
            $this->longWaitQueue,
            $this->longWaitConnection,
            $this->seconds
        ));
    }

    /**
     * The unique signature of the notification.
     */
    public function signature(): string
    {
        return md5($this->longWaitConnection . $this->longWaitQueue);
    }
}
