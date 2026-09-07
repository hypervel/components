<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

use Hypervel\Console\Command as HypervelCommand;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;

class DisabledReverbServiceProviderTest extends ReverbTestCase
{
    /**
     * Define environment setup.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('reverb.enabled', false);
    }

    public function testRegistersClearStateCommandWhenReverbIsDisabled(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('reverb:clear-state')
            ->assertExitCode(HypervelCommand::SUCCESS);
    }
}
