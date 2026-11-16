<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Console\ClearResetsCommand;
use Hypervel\Auth\Passwords\PasswordBroker;
use Hypervel\Auth\Passwords\PasswordBrokerManager;
use Hypervel\Auth\Passwords\TokenRepositoryInterface;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ClearResetsCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testClearsExpiredTokensThroughDefaultBroker(): void
    {
        $command = $this->makeCommand($manager = Double::for(PasswordBrokerManager::class));
        $broker = Double::for(PasswordBroker::class);
        $repository = Double::for(TokenRepositoryInterface::class);

        $manager->expects('broker')->with(null)->returns($broker);
        $broker->expects('getRepository')->returns($repository);
        $repository->expects('deleteExpired');

        $this->assertSame(0, $this->runCommand($command));
    }

    public function testClearsExpiredTokensThroughNamedBroker(): void
    {
        $command = $this->makeCommand($manager = Double::for(PasswordBrokerManager::class));
        $broker = Double::for(PasswordBroker::class);
        $repository = Double::for(TokenRepositoryInterface::class);

        $manager->expects('broker')->with('admins')->returns($broker);
        $broker->expects('getRepository')->returns($repository);
        $repository->expects('deleteExpired');

        $this->assertSame(0, $this->runCommand($command, ['name' => 'admins']));
    }

    /**
     * Create the command with a mocked password broker manager.
     */
    private function makeCommand(PasswordBrokerManager $manager): ClearResetsCommand
    {
        $app = new Application;
        $app->instance('auth.password', $manager);

        $command = new ClearResetsCommand;
        $command->setHypervel($app);

        return $command;
    }

    /**
     * Run the command with the given input.
     */
    private function runCommand(ClearResetsCommand $command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}
