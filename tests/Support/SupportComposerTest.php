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

class SupportComposerTest extends TestCase
{
    public function testGetLoader(): void
    {
        $loader = Composer::getLoader();

        $this->assertInstanceOf(ClassLoader::class, $loader);
    }

    public function testSetAndGetLoader(): void
    {
        $original = Composer::getLoader();
        $custom = new ClassLoader;

        Composer::setLoader($custom);

        $this->assertSame($custom, Composer::getLoader());

        Composer::setLoader($original);
    }

    public function testDumpAutoloadRunsTheCorrectCommand(): void
    {
        $composer = $this->mockComposer(['composer', 'dump-autoload']);

        $composer->dumpAutoloads();
    }

    public function testDumpAutoloadRunsTheCorrectCommandWhenCustomComposerPharIsPresent(): void
    {
        $expectedProcessArguments = [php_binary(), 'composer.phar', 'dump-autoload'];

        $composer = $this->mockComposer($expectedProcessArguments, customComposerPhar: true);

        $composer->dumpAutoloads();
    }

    public function testDumpAutoloadRunsTheCorrectCommandWithExtraArguments(): void
    {
        $composer = $this->mockComposer(['composer', 'dump-autoload', '--no-scripts']);

        $composer->dumpAutoloads('--no-scripts');
    }

    public function testDumpOptimizedTheCorrectCommand(): void
    {
        $composer = $this->mockComposer(['composer', 'dump-autoload', '--optimize']);

        $composer->dumpOptimized();
    }

    public function testRequirePackagesRunsTheCorrectCommand(): void
    {
        $composer = $this->mockComposer(
            ['composer', 'require', 'pestphp/pest:^2.0', 'pestphp/pest-plugin-laravel:^2.0', '--dev'],
            expectedEnvironment: ['COMPOSER_MEMORY_LIMIT' => '-1'],
        );

        $composer->requirePackages(['pestphp/pest:^2.0', 'pestphp/pest-plugin-laravel:^2.0'], true);
    }

    public function testRemovePackagesRunsTheCorrectCommand(): void
    {
        $composer = $this->mockComposer(
            ['composer', 'remove', 'phpunit/phpunit', '--dev'],
            expectedEnvironment: ['COMPOSER_MEMORY_LIMIT' => '-1'],
        );

        $composer->removePackages(['phpunit/phpunit'], true);
    }

    /**
     * Create a Composer manager expecting the given process arguments.
     */
    private function mockComposer(array $expectedProcessArguments, bool $customComposerPhar = false, ?array $expectedEnvironment = null): Composer
    {
        $directory = __DIR__;

        $files = m::mock(Filesystem::class);
        $files->expects('exists')->with($directory . '/composer.phar')->andReturn($customComposerPhar);

        $process = m::mock(Process::class);
        $process->expects('run');

        $composer = $this->getMockBuilder(Composer::class)
            ->onlyMethods(['getProcess'])
            ->setConstructorArgs([$files, $directory])
            ->getMock();
        $expectation = $composer->expects($this->once())->method('getProcess');

        if ($expectedEnvironment === null) {
            $expectation->with($expectedProcessArguments);
        } else {
            $expectation->with($expectedProcessArguments, $expectedEnvironment);
        }

        $expectation->willReturn($process);

        return $composer;
    }
}
