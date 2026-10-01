<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Contracts;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testing\PendingCommand;
use Hypervel\Testing\TestResponse;

interface TestCase
{
    /**
     * Call the given URI and return the Response.
     */
    public function call(
        string $method,
        string $uri,
        array $parameters = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $content = null,
    ): TestResponse;

    /**
     * Create the application.
     *
     * Needs to be implemented by subclasses.
     */
    public function createApplication(): ApplicationContract;

    /**
     * Set the currently logged in user for the application.
     */
    public function be(Authenticatable $user, ?string $guard = null): static;

    /**
     * Seed a given database connection.
     */
    public function seed(array|string $class = 'Database\Seeders\DatabaseSeeder'): static;

    /**
     * Call artisan command and return code.
     */
    public function artisan(string $command, array $parameters = []): int|PendingCommand;
}
