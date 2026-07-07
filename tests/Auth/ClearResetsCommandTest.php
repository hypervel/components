<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Console\ClearResetsCommand;
use Hypervel\Auth\Passwords\PasswordBroker;
use Hypervel\Auth\Passwords\PasswordBrokerManager;
use Hypervel\Auth\Passwords\TokenRepositoryInterface;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ClearResetsCommandTest extends TestCase
{
    public function testClearsExpiredTokensThroughDefaultBroker(): void
    {
        $command = $this->makeCommand($manager = m::mock(PasswordBrokerManager::class));
        $broker = m::mock(PasswordBroker::class);
        $repository = m::mock(TokenRepositoryInterface::class);

        $manager->shouldReceive('broker')->once()->with(null)->andReturn($broker);
        $broker->shouldReceive('getRepository')->once()->andReturn($repository);
        $repository->shouldReceive('deleteExpired')->once();

        $this->assertSame(0, $this->runCommand($command));
    }

    public function testClearsExpiredTokensThroughNamedBroker(): void
    {
        $command = $this->makeCommand($manager = m::mock(PasswordBrokerManager::class));
        $broker = m::mock(PasswordBroker::class);
        $repository = m::mock(TokenRepositoryInterface::class);

        $manager->shouldReceive('broker')->once()->with('admins')->andReturn($broker);
        $broker->shouldReceive('getRepository')->once()->andReturn($repository);
        $repository->shouldReceive('deleteExpired')->once();

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
