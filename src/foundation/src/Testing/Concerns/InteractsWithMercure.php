<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Testing\ParallelTesting;

/**
 * Opt into real-hub tests with MERCURE_URL and isolate each test's topics.
 */
trait InteractsWithMercure
{
    protected string $mercureTopicPrefix;

    /**
     * Configure broadcasting for the test's Mercure hub and topic namespace.
     */
    protected function setUpInteractsWithMercure(): void
    {
        if (env('MERCURE_URL') === null) {
            $this->markTestSkipped('Set MERCURE_URL to run Mercure integration tests for ' . static::class);
        }

        $token = $this->app->make(ParallelTesting::class)->token();
        $this->mercureTopicPrefix = 'https://hypervel.alt/test/' . $token . '-' . bin2hex(random_bytes(8)) . '/';

        $config = $this->app->make('config');
        $config->set('broadcasting.default', 'mercure');
        $config->set('broadcasting.connections.mercure.topic_prefix', $this->mercureTopicPrefix);
    }
}
