<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

/**
 * @internal
 * @coversNothing
 */
class ListenerMakeCommandTest extends TestCase
{
    protected $files = [
        'app/Listeners/FooListener.php',
        'tests/Feature/Listeners/FooListenerTest.php',
    ];

    public function testItCanGenerateListenerFile()
    {
        $this->artisan('make:listener', ['name' => 'FooListener'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Listeners;',
            'class FooListener',
            'public function handle(object $event)',
        ], 'app/Listeners/FooListener.php');

        $this->assertFileNotContains([
            'class FooListener implements ShouldQueue',
        ], 'app/Listeners/FooListener.php');
    }

    public function testItCanGenerateListenerFileForEvent()
    {
        $this->artisan('make:listener', ['name' => 'FooListener', '--event' => 'FooListenerCreated'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Listeners;',
            'use App\Events\FooListenerCreated;',
            'class FooListener',
            'public function handle(FooListenerCreated $event)',
        ], 'app/Listeners/FooListener.php');
    }

    public function testItCanGenerateListenerFileForHypervelEvent()
    {
        $this->artisan('make:listener', ['name' => 'FooListener', '--event' => 'Hypervel\Auth\Events\Login'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Listeners;',
            'use Hypervel\Auth\Events\Login;',
            'class FooListener',
            'public function handle(Login $event)',
        ], 'app/Listeners/FooListener.php');
    }

    public function testItCanGenerateQueuedListenerFile()
    {
        $this->artisan('make:listener', ['name' => 'FooListener', '--queued' => true])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Listeners;',
            'use Hypervel\Contracts\Queue\ShouldQueue;',
            'use Hypervel\Queue\InteractsWithQueue;',
            'class FooListener implements ShouldQueue',
            'public function handle(object $event)',
        ], 'app/Listeners/FooListener.php');
    }

    public function testItCanGenerateQueuedListenerFileForEvent()
    {
        $this->artisan('make:listener', ['name' => 'FooListener', '--queued' => true, '--event' => 'FooListenerCreated'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Listeners;',
            'use App\Events\FooListenerCreated;',
            'use Hypervel\Contracts\Queue\ShouldQueue;',
            'use Hypervel\Queue\InteractsWithQueue;',
            'class FooListener implements ShouldQueue',
            'public function handle(FooListenerCreated $event)',
        ], 'app/Listeners/FooListener.php');
    }

    public function testItCanGenerateQueuedListenerFileForHypervelEvent()
    {
        $this->artisan('make:listener', ['name' => 'FooListener', '--queued' => true, '--event' => 'Hypervel\Auth\Events\Login'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Listeners;',
            'use Hypervel\Auth\Events\Login;',
            'use Hypervel\Contracts\Queue\ShouldQueue;',
            'use Hypervel\Queue\InteractsWithQueue;',
            'class FooListener implements ShouldQueue',
            'public function handle(Login $event)',
        ], 'app/Listeners/FooListener.php');
    }

    public function testItCanGenerateListenerFileWithTest()
    {
        $this->artisan('make:listener', ['name' => 'FooListener', '--test' => true])
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Listeners/FooListener.php');
        $this->assertFilenameExists('tests/Feature/Listeners/FooListenerTest.php');
    }
}
