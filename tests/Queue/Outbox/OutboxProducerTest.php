<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Queue\ShouldQueueViaOutbox;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Mail;
use Hypervel\Support\Facades\Notification as NotificationFacade;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class OutboxProducerTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Select Redis without allowing staging to contact a transport.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('queue.default', 'redis');
        $config->set('queue.outbox.databases', [$config->string('database.default')]);
    }

    /**
     * Load only the shipped outbox schema.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--realpath' => true,
            '--path' => dirname(__DIR__, 3) . '/src/testbench/hypervel/migrations/0001_01_01_000009_testbench_create_job_outbox_table.php',
        ];
    }

    public function testPublicProducerApisStageTheirExistingWrapperJobs(): void
    {
        $this->app->instance('redis', m::mock(Factory::class));
        Event::listen('outbox.created', OutboxProducerListener::class);

        DB::transaction(static function (): void {
            Event::dispatch('outbox.created', ['order-17']);
            Mail::to('customer@example.com')->send(new OutboxProducerMailable);
            NotificationFacade::route('mail', 'customer@example.com')->notify(new OutboxProducerNotification);
            Event::dispatch(new OutboxProducerBroadcast('order-17'));
        });

        $rows = DB::table('job_outbox')->orderBy('id')->get();
        $jobs = $rows->map(static function (object $row): object {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

            return unserialize($payload['data']['command']);
        });

        $this->assertSame([
            CallQueuedListener::class,
            SendQueuedMailable::class,
            SendQueuedNotifications::class,
            BroadcastEvent::class,
        ], $jobs->map(static fn (object $job): string => $job::class)->all());
        $this->assertSame(['order-17'], $jobs[0]->data);
        $this->assertSame('customer@example.com', $jobs[1]->mailable->to[0]['address']);
        $this->assertSame('customer@example.com', $jobs[2]->notifiables->first()->routes['mail']);
        $this->assertSame('order-17', $jobs[3]->event->order);
        $this->assertSame(['redis'], $rows->pluck('connection')->unique()->all());
    }
}

class OutboxProducerListener implements ShouldQueueViaOutbox
{
    /**
     * Handle the queued event.
     */
    public function handle(string $order): void
    {
    }
}

class OutboxProducerMailable extends Mailable implements ShouldQueueViaOutbox
{
    use Queueable;

    /**
     * Build a mail message without an external template dependency.
     */
    public function build(): static
    {
        return $this->subject('Order received')->html('Your order was received.');
    }
}

class OutboxProducerNotification extends Notification implements ShouldQueueViaOutbox
{
    use Queueable;

    /**
     * Select the queued mail notification channel.
     */
    public function via(AnonymousNotifiable $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the notification mail message.
     */
    public function toMail(AnonymousNotifiable $notifiable): MailMessage
    {
        return (new MailMessage)->line('Your order was received.');
    }
}

class OutboxProducerBroadcast implements ShouldBroadcast, ShouldQueueViaOutbox
{
    use Queueable;

    /**
     * Identify the order in the broadcast payload.
     */
    public function __construct(public string $order)
    {
    }

    /**
     * Select the broadcast channel.
     */
    public function broadcastOn(): array
    {
        return ['orders'];
    }
}
