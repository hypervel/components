<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Mail\Mailable;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Queue\Middleware\RateLimited;
use Hypervel\Support\Facades\Mail;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SendingQueuedMailTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('mail', [
            'default' => 'array',
            'mailers' => [
                'array' => ['transport' => 'array'],
            ],
        ]);

        $app['view']->addLocation(__DIR__ . '/Fixtures');
    }

    public function testMailIsSentWithDefaultLocale()
    {
        Queue::fake();

        Mail::to('test@mail.com')->queue(new SendingQueuedMailTestMail);

        Queue::assertPushed(SendQueuedMailable::class, function ($job) {
            return $job->middleware[0] instanceof RateLimited;
        });
    }

    public function testMailIsSentWhenRoutingQueue()
    {
        Queue::fake();

        Queue::route(Mailable::class, 'mail-queue', 'mail-connection');

        Mail::to('test@mail.com')->queue(new SendingQueuedMailTestMail);

        Queue::connection('mail-connection')->assertPushedOn('mail-queue', SendQueuedMailable::class);
    }

    public function testMailIsSentWithDelay()
    {
        Queue::fake();

        $delay = now()->addMinutes(10);

        Mail::to('test@mail.com')->later($delay, new SendingQueuedMailTestMail);

        Queue::assertPushed(SendQueuedMailable::class, function ($job) use ($delay) {
            return $job->delay === $delay;
        });
    }
}

class SendingQueuedMailTestMail extends Mailable
{
    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->view('view');
    }

    public function middleware(): array
    {
        return [new RateLimited('limiter')];
    }
}
