<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Passport\Client;
use Hypervel\Passport\Console\ClientCommand;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ClientCommandTest extends PassportTestCase
{
    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function testPublicOptionControlsClientConfidentiality(bool $device, bool $public): void
    {
        $options = ['--name' => 'Example', '--redirect_uri' => 'https://app.example/callback'];

        if ($device) {
            $options['--device'] = true;
        }

        if ($public) {
            $options['--public'] = true;
        }

        $tester = $this->tester();
        $tester->execute($options, ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
        $client = Client::sole();
        $this->assertSame(! $public, $client->confidential());
        $this->assertTrue($client->hasGrantType($device ? 'urn:ietf:params:oauth:grant-type:device_code' : 'authorization_code'));
    }

    public function testPersonalClientDefaultsToThePassportGuardsProvider(): void
    {
        config([
            'auth.providers.customers' => config('auth.providers.users'),
            'auth.guards.passport.provider' => 'customers',
            'auth.guards.other-passport' => ['driver' => 'passport', 'provider' => 'users'],
            'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
        ]);

        $tester = $this->tester();
        $tester->execute(['--personal' => true, '--name' => 'Personal'], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
        $client = Client::sole();
        $this->assertSame('customers', $client->provider);
        $this->assertTrue($client->hasGrantType('personal_access'));
    }

    /**
     * Run the command with real input handling and repository writes.
     */
    protected function tester(): CommandTester
    {
        $command = $this->app->make(ClientCommand::class);
        $command->setHypervel($this->app);
        $application = new Application;
        $application->addCommand($command);

        return new CommandTester($command);
    }
}
