<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Fixtures\Jobs;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;
use Hypervel\Saloon\RateLimit\Queue\ReleaseOnRateLimit;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Requests\UserRequest;

class ApiRateLimitedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new ReleaseOnRateLimit];
    }

    /**
     * Create a job that sends through the given connector.
     */
    public function __construct(protected TestConnector $connector)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->connector->send(new UserRequest);
    }
}
