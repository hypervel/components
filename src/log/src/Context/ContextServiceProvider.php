<?php

declare(strict_types=1);

namespace Hypervel\Log\Context;

use Hypervel\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Queue;
use Hypervel\Support\Facades\Context;
use Hypervel\Support\ServiceProvider;

class ContextServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->bind(ContextLogProcessorContract::class, fn () => new ContextLogProcessor());
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload): array {
            if (! Repository::hasInstance()) {
                return [];
            }

            /** @phpstan-ignore staticMethod.notFound */
            $context = Context::dehydrate();

            // IMPORTANT: Uses Laravel's payload key for cross-framework queue interoperability.
            return $context === null ? [] : [
                'illuminate:log:context' => $context,
            ];
        });

        // IMPORTANT: Uses Laravel's payload key for cross-framework queue interoperability.
        $this->app['events']->listen(JobProcessing::class, function (JobProcessing $event): void {
            $context = $event->job->payload()['illuminate:log:context'] ?? null;

            if ($context === null) {
                return;
            }

            /* @phpstan-ignore staticMethod.notFound */
            Context::hydrate($context);
        });
    }
}
