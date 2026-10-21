<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Notifications\Database\Sqlite;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\Client\Response;
use Hypervel\Notifications\Events\NotificationDelivered;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Notification;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

#[RequiresDatabase('sqlite')]
class NotificationTransportModelTest extends TestCase
{
    /**
     * Use a real serializing listener queue.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('queue.default', 'sync');
    }

    #[DataProvider('events')]
    public function testTransportRestorationPreservesModelIdentifierRestoration(string $eventClass): void
    {
        $tableName = (new NotificationTransportRecipient)->getTable();
        Schema::create($tableName, static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        try {
            $recipient = NotificationTransportRecipient::query()->create(['name' => 'Stored recipient']);
            $recipient->name = 'Unsaved recipient';
            $response = new Response(new PsrResponse(429, [], '{"error":"ratelimited"}'));
            $event = $eventClass === NotificationFailed::class
                ? new NotificationFailed($recipient, new Notification, 'slack', ['exception' => new RequestException($response)])
                : new $eventClass($recipient, new Notification, 'slack', $response);
            $dispatcher = $this->app->make(Dispatcher::class);
            $dispatcher->listen($eventClass, NotificationTransportModelListener::class);
            $dispatcher->dispatch($event);

            $received = $this->app->make(NotificationTransportModelListener::class)->received;
            $this->assertCount(1, $received);
            $this->assertNotSame($recipient, $received[0]->notifiable);
            $this->assertSame($recipient->getKey(), $received[0]->notifiable->getKey());
            $this->assertSame('Stored recipient', $received[0]->notifiable->name);
            $this->assertSame('Unsaved recipient', $recipient->name);
            $restoredResponse = $eventClass === NotificationFailed::class
                ? $received[0]->data['exception']->response
                : $received[0]->response;
            $this->assertSame(Response::class, $restoredResponse::class);
            $this->assertSame('ratelimited', $restoredResponse->json('error'));
        } finally {
            Schema::dropIfExists($tableName);
        }
    }

    /**
     * Provide the three events that preserve transport state.
     */
    public static function events(): array
    {
        return [[NotificationSent::class], [NotificationDelivered::class], [NotificationFailed::class]];
    }
}

class NotificationTransportRecipient extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];
}

class NotificationTransportModelListener implements ShouldQueue
{
    public array $received = [];

    /**
     * Record the restored model and transport objects.
     */
    public function handle(NotificationSent|NotificationDelivered|NotificationFailed $event): void
    {
        $this->received[] = $event;
    }
}
