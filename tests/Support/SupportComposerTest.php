<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Composer\Autoload\ClassLoader;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Composer;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Process\Process;

use function Hypervel\Support\php_binary;

/**
 * @internal
 * @coversNothing
 */
class SupportComposerTest extends TestCase
{
    public function testGetLoader()
    {
        $loader = Composer::getLoader();

        $this->assertInstanceOf(ClassLoader::class, $loader);
    }

    public function testSetAndGetLoader()
    {
        $original = Composer::getLoader();
        $custom = new ClassLoader;

        Composer::setLoader($custom);

        $this->assertSame($custom, Composer::getLoader());

        Composer::setLoader($original);
    }

    public function testDumpAutoloadRunsTheCorrectCommand()
    {
        $composer = $this->mockComposer(['composer', 'dump-autoload']);

        $composer->dumpAutoloads();
    }

    public function testDumpAutoloadRunsTheCorrectCommandWhenCustomComposerPharIsPresent()
    {
        $expectedProcessArguments = [php_binary(), 'composer.phar', 'dump-autoload'];

        $composer = $this->mockComposer($expectedProcessArguments, customComposerPhar: true);

        $composer->dumpAutoloads();
    }

    public function testDumpAutoloadRunsTheCorrectCommandWithExtraArguments()
    {
        $composer = $this->mockComposer(['composer', 'dump-autoload', '--no-scripts']);

        $composer->dumpAutoloads('--no-scripts');
    }

    public function testDumpOptimizedTheCorrectCommand()
    {
        $composer = $this->mockComposer(['composer', 'dump-autoload', '--optimize']);

        $composer->dumpOptimized();
    }

    public function testRequirePackagesRunsTheCorrectCommand()
    {
        $composer = $this->mockComposer(['composer', 'require', 'pestphp/pest:^2.0', 'pestphp/pest-plugin-laravel:^2.0', '--dev']);

        $composer->requirePackages(['pestphp/pest:^2.0', 'pestphp/pest-plugin-laravel:^2.0'], true);
    }

    public function testRemovePackagesRunsTheCorrectCommand()
    {
        $composer = $this->mockComposer(['composer', 'remove', 'phpunit/phpunit', '--dev']);

        $composer->removePackages(['phpunit/phpunit'], true);
    }

    private function mockComposer(array $expectedProcessArguments, bool $customComposerPhar = false): Composer
    {
        $directory = __DIR__;

        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->once()->with($directory . '/composer.phar')->andReturn($customComposerPhar);

        $process = m::mock(Process::class);
        $process->shouldReceive('run')->once();

        $composer = $this->getMockBuilder(Composer::class)
            ->onlyMethods(['getProcess'])
            ->setConstructorArgs([$files, $directory])
            ->getMock();
        $composer->expects($this->once())
            ->method('getProcess')
            ->with($expectedProcessArguments)
            ->willReturn($process);

        return $composer;
    }
}
