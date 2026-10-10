<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Pagination\Fixtures\SuperheroApi;

abstract class PaginationTestCase extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        SuperheroApi::fake();
    }
}
