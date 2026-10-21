<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

class MiddlewareMakeCommandTest extends TestCase
{
    protected array $files = [
        'app/Http/Middleware/Foo.php',
        'tests/Feature/Http/Middleware/FooTest.php',
    ];

    public function testItCanGenerateMiddlewareFile(): void
    {
        $this->artisan('make:middleware', ['name' => 'Foo'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Middleware;',
            'use Closure;',
            'use Hypervel\Http\Request;',
            'class Foo',
            'public function handle(Request $request, Closure $next)',
            'return $next($request);',
        ], 'app/Http/Middleware/Foo.php');

        $this->assertFilenameNotExists('tests/Feature/Http/Middleware/FooTest.php');
    }

    public function testItCanGenerateMiddlewareFileWithTests(): void
    {
        $this->artisan('make:middleware', ['name' => 'Foo', '--test' => true])
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Http/Middleware/Foo.php');
        $this->assertFilenameExists('tests/Feature/Http/Middleware/FooTest.php');
    }
}
