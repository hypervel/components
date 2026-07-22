<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\ComponentMakeCommand;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

class ComponentMakeCommandTest extends TestCase
{
    protected $files = [
        'app/View/Components/Foo.php',
        'resources/views/components/foo.blade.php',
        'tests/Feature/View/Components/FooTest.php',
        'resources/views/custom/path/foo.blade.php',
        'app/View/Components/Nested/Foo.php',
        'resources/views/components/nested/foo.blade.php',
        'tests/Feature/View/Components/Nested/FooTest.php',
    ];

    public function testItCanGenerateComponentFile(): void
    {
        $this->artisan('make:component', ['name' => 'Foo'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\View\Components;',
            'use Hypervel\View\Component;',
            'class Foo extends Component',
            "return view('components.foo');",
        ], 'app/View/Components/Foo.php');

        $this->assertFilenameExists('resources/views/components/foo.blade.php');
        $this->assertFilenameNotExists('tests/Feature/View/Components/FooTest.php');
    }

    public function testItCanGenerateInlineComponentFile(): void
    {
        $this->artisan('make:component', ['name' => 'Foo', '--inline' => true])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\View\Components;',
            'use Hypervel\View\Component;',
            'class Foo extends Component',
            "return <<<'blade'",
        ], 'app/View/Components/Foo.php');

        $this->assertFilenameNotExists('resources/views/components/foo.blade.php');
    }

    public function testItCanGenerateComponentFileWithTest(): void
    {
        $this->artisan('make:component', ['name' => 'Foo', '--test' => true])
            ->assertExitCode(0);

        $this->assertFilenameExists('app/View/Components/Foo.php');
        $this->assertFilenameExists('resources/views/components/foo.blade.php');
        $this->assertFilenameExists('tests/Feature/View/Components/FooTest.php');
    }

    public function testItCanGenerateComponentFileWithCustomPath(): void
    {
        $this->artisan('make:component', ['name' => 'Foo', '--path' => 'custom/path'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\View\Components;',
            'use Hypervel\View\Component;',
            'class Foo extends Component',
            "return view('custom.path.foo');",
        ], 'app/View/Components/Foo.php');

        $this->assertFilenameExists('resources/views/custom/path/foo.blade.php');
        $this->assertFilenameNotExists('tests/Feature/View/Components/FooTest.php');
    }

    public function testItCanGenerateNestedComponentFile(): void
    {
        $this->artisan('make:component', ['name' => 'Nested/Foo'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\View\Components\Nested;',
            'use Hypervel\View\Component;',
            'class Foo extends Component',
            "return view('components.nested.foo');",
        ], 'app/View/Components/Nested/Foo.php');

        $this->assertFilenameExists('resources/views/components/nested/foo.blade.php');
        $this->assertFilenameNotExists('tests/Feature/View/Components/Nested/FooTest.php');
    }

    public function testItCanGenerateNestedComponentFileWithCustomPath(): void
    {
        $this->artisan('make:component', ['name' => 'Nested/Foo', '--path' => 'custom/path'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\View\Components\Nested;',
            'use Hypervel\View\Component;',
            'class Foo extends Component',
            "return view('custom.path.foo');",
        ], 'app/View/Components/Nested/Foo.php');

        $this->assertFilenameExists('resources/views/custom/path/foo.blade.php');
        $this->assertFilenameNotExists('tests/Feature/View/Components/Nested/FooTest.php');
    }

    public function testViewPublicationFailureDoesNotReportSuccess(): void
    {
        $files = m::mock(Filesystem::class)->makePartial();
        $publicationException = new RuntimeException('Unable to publish component view.');
        $files->shouldReceive('replace')->once()->andThrow($publicationException);

        $command = new ComponentMakeCommand($files);
        $command->setHypervel($this->app);
        $application = new ConsoleApplication;
        $application->addCommand($command);
        $tester = new CommandTester($command);

        try {
            $tester->execute(['name' => 'FailedView', '--view' => true]);
            $this->fail('Expected component view publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationException, $exception);
        }

        $this->assertStringNotContainsString('created successfully', $tester->getDisplay());
        $this->assertFileDoesNotExist(resource_path('views/components/failed-view.blade.php'));
    }
}
