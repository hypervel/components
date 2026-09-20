<?php

declare(strict_types=1);

namespace Hypervel\Log\Context;

use Hypervel\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Hypervel\Log\Context\Events\ContextDehydrating;
use Hypervel\Log\Context\Events\ContextHydrated;
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
        // Keep the coroutine repository as the single owner, including in copied contexts.
        $this->app->bind(Repository::class, static fn (): Repository => Repository::getInstance());

        $this->app->bind(ContextLogProcessorContract::class, fn () => new ContextLogProcessor);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload) use ($events): array {
            if (! Repository::hasInstance() && ! $events->hasListeners(ContextDehydrating::class)) {
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
        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($events): void {
            $context = $event->job->payload()['illuminate:log:context'] ?? null;

            if ($context !== null || Repository::hasInstance() || $events->hasListeners(ContextHydrated::class)) {
                /* @phpstan-ignore staticMethod.notFound */
                Context::hydrate($context);
            }
        });
    }
}
