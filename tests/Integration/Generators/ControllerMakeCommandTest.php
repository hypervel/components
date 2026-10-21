<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

class ControllerMakeCommandTest extends TestCase
{
    protected array $files = [
        'app/Http/Controllers/Controller.php',
        'app/Http/Controllers/FooController.php',
        'app/Models/Bar.php',
        'app/Models/Foo.php',
        'tests/Feature/Http/Controllers/FooControllerTest.php',
    ];

    public function testItCanGenerateControllerFile(): void
    {
        $this->artisan('make:controller', ['name' => 'FooController'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Controllers;',
            'use Hypervel\Http\Request;',
            'class FooController',
        ], 'app/Http/Controllers/FooController.php');

        $this->assertFileNotContains([
            'class FooController extends Controller',
            'public function __invoke(Request $request)',
        ], 'app/Http/Controllers/FooController.php');

        $this->assertFilenameNotExists('tests/Feature/Http/Controllers/FooControllerTest.php');
    }

    public function testItCanGenerateControllerFileWhenBaseControllerExists(): void
    {
        $this->artisan('make:controller', ['name' => 'Controller'])
            ->assertExitCode(0);

        $this->artisan('make:controller', ['name' => 'FooController'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Controllers;',
            'use Hypervel\Http\Request;',
            'class Controller',
        ], 'app/Http/Controllers/Controller.php');

        $this->assertFileContains([
            'namespace App\Http\Controllers;',
            'use Hypervel\Http\Request;',
            'class FooController extends Controller',
        ], 'app/Http/Controllers/FooController.php');

        $this->assertFilenameNotExists('tests/Feature/Http/Controllers/FooControllerTest.php');
    }

    public function testItCanGenerateControllerFileWithInvokableTypeOption(): void
    {
        $this->artisan('make:controller', ['name' => 'FooController', '--type' => 'invokable'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Controllers;',
            'use Hypervel\Http\Request;',
            'class FooController',
            'public function __invoke(Request $request)',
        ], 'app/Http/Controllers/FooController.php');
    }

    public function testItCanGenerateControllerFileWithInvokableOption(): void
    {
        $this->artisan('make:controller', ['name' => 'FooController', '--invokable' => true])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Controllers;',
            'use Hypervel\Http\Request;',
            'class FooController',
            'public function __invoke(Request $request)',
        ], 'app/Http/Controllers/FooController.php');
    }

    public function testItCanGenerateControllerFileWithModelOption(): void
    {
        $this->artisan('make:controller', ['name' => 'FooController', '--model' => 'Foo'])
            ->expectsQuestion('A App\Models\Foo model does not exist. Do you want to generate it?', false)
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Controllers;',
            'use App\Models\Foo;',
            'public function index()',
            'public function create()',
            'public function store(Request $request)',
            'public function show(Foo $foo)',
            'public function edit(Foo $foo)',
            'public function update(Request $request, Foo $foo)',
            'public function destroy(Foo $foo)',
        ], 'app/Http/Controllers/FooController.php');
    }

    public function testItCanGenerateControllerFileWithModelAndParentOption(): void
    {
        $this->artisan('make:controller', ['name' => 'FooController', '--model' => 'Bar', '--parent' => 'Foo'])
            ->expectsQuestion('A App\Models\Foo model does not exist. Do you want to generate it?', false)
            ->expectsQuestion('A App\Models\Bar model does not exist. Do you want to generate it?', false)
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Controllers;',
            'use App\Models\Bar;',
            'use App\Models\Foo;',
            'public function index(Foo $foo)',
            'public function create(Foo $foo)',
            'public function store(Request $request, Foo $foo)',
            'public function show(Foo $foo, Bar $bar)',
            'public function edit(Foo $foo, Bar $bar)',
            'public function update(Request $request, Foo $foo, Bar $bar)',
            'public function destroy(Foo $foo, Bar $bar)',
        ], 'app/Http/Controllers/FooController.php');
    }

    public function testItCanGenerateControllerFileWithApiOption(): void
    {
        $this->artisan('make:controller', ['name' => 'FooController', '--api' => true])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Controllers;',
            'use Hypervel\Http\Request;',
            'class FooController',
            'public function index()',
            'public function store(Request $request)',
            'public function update(Request $request, string $id)',
            'public function destroy(string $id)',
        ], 'app/Http/Controllers/FooController.php');

        $this->assertFileNotContains([
            'public function create()',
            'public function edit(string $id)',
        ], 'app/Http/Controllers/FooController.php');
    }

    public function testItCanGenerateControllerFileWithInvokableIgnoresApiOption(): void
    {
        $this->artisan('make:controller', ['name' => 'FooController', '--api' => true, '--invokable' => true])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Controllers;',
            'use Hypervel\Http\Request;',
            'class FooController',
            'public function __invoke(Request $request)',
        ], 'app/Http/Controllers/FooController.php');

        $this->assertFileNotContains([
            'public function index()',
            'public function store(Request $request)',
            'public function update(Request $request, string $id)',
            'public function destroy(string $id)',
        ], 'app/Http/Controllers/FooController.php');
    }

    public function testItCanGenerateControllerFileWithApiAndModelOption(): void
    {
        $this->artisan('make:controller', ['name' => 'FooController', '--model' => 'Foo', '--api' => true])
            ->expectsQuestion('A App\Models\Foo model does not exist. Do you want to generate it?', false)
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Controllers;',
            'use App\Models\Foo;',
            'public function index()',
            'public function store(Request $request)',
            'public function show(Foo $foo)',
            'public function update(Request $request, Foo $foo)',
            'public function destroy(Foo $foo)',
        ], 'app/Http/Controllers/FooController.php');

        $this->assertFileNotContains([
            'public function create()',
            'public function edit(Foo $foo)',
        ], 'app/Http/Controllers/FooController.php');
    }

    public function testItCanGenerateControllerFileWithTest(): void
    {
        $this->artisan('make:controller', ['name' => 'FooController', '--test' => true])
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Http/Controllers/FooController.php');
        $this->assertFilenameExists('tests/Feature/Http/Controllers/FooControllerTest.php');
    }
}
