<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Console\ClosureCommand;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ClosureCommandTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        Artisan::command('inspire', function () {
            $this->comment('We must ship. - Taylor Otwell');
        })->purpose('Display an inspiring quote');

        Artisan::command('greet {name}', function (string $name) {
            $this->info("Hello, {$name}!");
        })->describe('Greet a user by name');
    }

    public function testItCanRunClosureCommand()
    {
        $this->artisan('inspire')->expectsOutput('We must ship. - Taylor Otwell');
    }

    public function testPurposeSetsDescription()
    {
        $command = new ClosureCommand('test:purpose', function () {});
        $command->purpose('A purpose description');

        $this->assertSame('A purpose description', $command->getDescription());
    }

    public function testDescribeSetsDescription()
    {
        $command = new ClosureCommand('test:describe', function () {});
        $command->describe('A describe description');

        $this->assertSame('A describe description', $command->getDescription());
    }

    public function testPurposeAndDescribeAreEquivalent()
    {
        $commandA = new ClosureCommand('test:a', function () {});
        $commandA->purpose('Same description');

        $commandB = new ClosureCommand('test:b', function () {});
        $commandB->describe('Same description');

        $this->assertSame($commandA->getDescription(), $commandB->getDescription());
    }

    public function testClosureCommandReceivesArguments()
    {
        $this->artisan('greet', ['name' => 'Taylor'])->expectsOutput('Hello, Taylor!');
    }
}
