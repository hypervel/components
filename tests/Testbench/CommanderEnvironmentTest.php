<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Testbench\Console\Commander;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArgvInput;

use function Hypervel\Testbench\package_path;

class CommanderEnvironmentTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');

        unset($_ENV['APP_RUNNING_IN_CONSOLE'], $_SERVER['APP_RUNNING_IN_CONSOLE']);

        parent::tearDown();
    }

    #[Test]
    public function itMarksServeCommandsAsNonConsoleBeforeBootstrappingTheApplication(): void
    {
        $commander = new CommanderHarness([], package_path());

        $commander->prepareCommandEnvironmentPublic(new ArgvInput(['testbench', 'serve']));

        $this->assertSame('false', getenv('APP_RUNNING_IN_CONSOLE'));
        $this->assertSame('false', $_ENV['APP_RUNNING_IN_CONSOLE']);
        $this->assertSame('false', $_SERVER['APP_RUNNING_IN_CONSOLE']);
    }

    #[Test]
    public function itLeavesNonServeCommandsOnTheNormalConsolePath(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE=true');
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'true';
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'true';

        $commander = new CommanderHarness([], package_path());

        $commander->prepareCommandEnvironmentPublic(new ArgvInput(['testbench', 'about']));

        $this->assertSame('true', getenv('APP_RUNNING_IN_CONSOLE'));
        $this->assertSame('true', $_ENV['APP_RUNNING_IN_CONSOLE']);
        $this->assertSame('true', $_SERVER['APP_RUNNING_IN_CONSOLE']);
    }
}

final class CommanderHarness extends Commander
{
    /**
     * Expose command environment preparation for focused tests.
     */
    public function prepareCommandEnvironmentPublic(ArgvInput $input): void
    {
        $this->prepareCommandEnvironment($input);
    }
}
