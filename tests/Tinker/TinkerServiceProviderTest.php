<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\TestCase;
use Hypervel\Tinker\Console\TinkerCommand;
use Hypervel\Tinker\TinkerServiceProvider;

class TinkerServiceProviderTest extends TestCase
{
    protected function getPackageProviders(Application $app): array
    {
        return [TinkerServiceProvider::class];
    }

    public function testTinkerCommandIsRegistered()
    {
        $command = $this->app->make('command.tinker');

        $this->assertInstanceOf(TinkerCommand::class, $command);
    }

    public function testTinkerConfigIsMerged()
    {
        $config = $this->app->make('config');

        $this->assertIsArray($config->get('tinker.commands'));
        $this->assertIsArray($config->get('tinker.alias'));
        $this->assertIsArray($config->get('tinker.dont_alias'));
        $this->assertNotNull($config->get('tinker.trust_project'));
    }
}
