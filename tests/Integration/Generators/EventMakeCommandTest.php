<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

class EventMakeCommandTest extends TestCase
{
    protected array $files = [
        'app/Events/FooCreated.php',
        'app/Events/Channel.php',
    ];

    public function testItCanGenerateEventFile(): void
    {
        $this->artisan('make:event', ['name' => 'FooCreated'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Events;',
            'class FooCreated',
        ], 'app/Events/FooCreated.php');
    }

    public function testItCanGenerateEventFileNamedChannel(): void
    {
        $this->artisan('make:event', ['name' => 'Channel'])
            ->assertExitCode(0);

        $this->assertPhpFileCompiles('app/Events/Channel.php');
    }
}
