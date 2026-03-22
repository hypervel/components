<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Contracts;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testing\PendingCommand;
use Hypervel\Testing\TestResponse;

interface TestCase
{
    public function call(
        string $method,
        string $uri,
        array $parameters = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $content = null,
    ): TestResponse;

    public function createApplication(): ApplicationContract;

    public function be(Authenticatable $user, ?string $guard = null): static;

    public function seed(array|string $class = 'Database\Seeders\DatabaseSeeder'): static;

    public function artisan(string $command, array $parameters = []): int|PendingCommand;
}
