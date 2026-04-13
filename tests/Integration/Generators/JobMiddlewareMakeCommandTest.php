<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

/**
 * @internal
 * @coversNothing
 */
class JobMiddlewareMakeCommandTest extends TestCase
{
    protected $files = [
        'app/Jobs/Middleware/Foo.php',
        'tests/Feature/Jobs/Middleware/FooTest.php',
    ];

    public function testItCanGenerateJobMiddlewareFile()
    {
        $this->artisan('make:job-middleware', ['name' => 'Foo'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Jobs\Middleware;',
            'class Foo',
        ], 'app/Jobs/Middleware/Foo.php');

        $this->assertFilenameNotExists('tests/Feature/Jobs/Middleware/FooTest.php');
    }

    public function testItCanGenerateJobMiddlewareFileWithTest()
    {
        $this->artisan('make:job-middleware', ['name' => 'Foo', '--test' => true])
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Jobs/Middleware/Foo.php');
        $this->assertFilenameExists('tests/Feature/Jobs/Middleware/FooTest.php');
    }
}
