<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Console\Commands\GeneratorCommandStub;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class GeneratorCommandTest extends TestCase
{
    public function testGetPathWithRelativePath(): void
    {
        $command = new GeneratorCommandStub;
        $command->setHypervel($this->app);

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('target-path')
            ->andReturn('packages/my-package/src');
        $command->setTestInput($input);

        $path = $command->exposedGetPath('MyNamespace\MyClass');

        $this->assertSame($this->app->basePath('packages/my-package/src/MyClass.php'), $path);
    }

    public function testGetPathWithRelativePathWithTrailingSlash(): void
    {
        $command = new GeneratorCommandStub;
        $command->setHypervel($this->app);

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('target-path')
            ->andReturn('packages/my-package/src/');
        $command->setTestInput($input);

        $path = $command->exposedGetPath('MyNamespace\MyClass');

        $this->assertSame($this->app->basePath('packages/my-package/src/MyClass.php'), $path);
    }

    public function testGetPathWithAbsolutePath(): void
    {
        $command = new GeneratorCommandStub;

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('target-path')
            ->andReturn('/tmp/custom-path');
        $command->setTestInput($input);

        $path = $command->exposedGetPath('MyNamespace\MyClass');

        $this->assertSame('/tmp/custom-path/MyClass.php', $path);
    }

    public function testGetPathWithAbsolutePathWithTrailingSlash(): void
    {
        $command = new GeneratorCommandStub;

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('target-path')
            ->andReturn('/tmp/custom-path/');
        $command->setTestInput($input);

        $path = $command->exposedGetPath('MyNamespace\MyClass');

        $this->assertSame('/tmp/custom-path/MyClass.php', $path);
    }

    public function testGetPathExtractsClassNameFromDeeplyNestedNamespace(): void
    {
        $command = new GeneratorCommandStub;
        $command->setHypervel($this->app);

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('target-path')
            ->andReturn('src/Controllers');
        $command->setTestInput($input);

        $path = $command->exposedGetPath('App\Http\Controllers\Api\V1\UserController');

        $this->assertSame($this->app->basePath('src/Controllers/UserController.php'), $path);
    }

    public function testGetPathDefaultUsesAppPath(): void
    {
        // Pre-set the namespace to avoid composer.json lookup in the test environment
        $reflection = new ReflectionProperty($this->app, 'namespace');
        $reflection->setValue($this->app, 'App\\');

        $command = new GeneratorCommandStub;
        $command->setHypervel($this->app);

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('target-path')
            ->andReturn(null);
        $command->setTestInput($input);

        $path = $command->exposedGetPath('App\Http\Controllers\UserController');

        $appPath = $this->app->path();
        $this->assertSame($appPath . '/Http/Controllers/UserController.php', $path);
    }

    public function testTargetPathOptionIsRegistered(): void
    {
        $command = new GeneratorCommandStub;

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('target-path'));
        $this->assertFalse($definition->getOption('target-path')->isValueRequired());
        $this->assertNull($definition->getOption('target-path')->getDefault());
    }

    public function testTargetNamespaceOptionIsRegistered(): void
    {
        $command = new GeneratorCommandStub;

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('target-namespace'));
        $this->assertNull($definition->getOption('target-namespace')->getDefault());
    }

    public function testUserProviderModelReturnsNullWhenAuthConfigIsMissing(): void
    {
        $this->app->make('config')->set('auth', []);

        $command = new GeneratorCommandStub;
        $command->setHypervel($this->app);

        $this->assertNull($command->exposedUserProviderModel());
    }

    public function testIsReservedNameReturnsTrueForReservedWords(): void
    {
        $command = new GeneratorCommandStub;

        $this->assertTrue($command->exposedIsReservedName('class'));
        $this->assertTrue($command->exposedIsReservedName('match'));
        $this->assertTrue($command->exposedIsReservedName('enum'));
        $this->assertTrue($command->exposedIsReservedName('yield'));
        $this->assertTrue($command->exposedIsReservedName('__CLASS__'));
    }

    public function testIsReservedNameReturnsFalseForNonReservedWords(): void
    {
        $command = new GeneratorCommandStub;

        $this->assertFalse($command->exposedIsReservedName('User'));
        $this->assertFalse($command->exposedIsReservedName('PostController'));
        $this->assertFalse($command->exposedIsReservedName('MyCustomClass'));
    }

    public function testIsReservedNameIsCaseInsensitive(): void
    {
        $command = new GeneratorCommandStub;

        $this->assertTrue($command->exposedIsReservedName('Class'));
        $this->assertTrue($command->exposedIsReservedName('CLASS'));
        $this->assertTrue($command->exposedIsReservedName('Match'));
        $this->assertTrue($command->exposedIsReservedName('ENUM'));
    }

    public function testSortImportsAlphabeticallySortsUseStatements(): void
    {
        $command = new GeneratorCommandStub;

        $stub = <<<'PHP'
<?php

use Zebra\Foo;
use Apple\Bar;
use Mango\Baz;

class MyClass {}
PHP;

        $expected = <<<'PHP'
<?php

use Apple\Bar;
use Mango\Baz;
use Zebra\Foo;

class MyClass {}
PHP;

        $this->assertSame($expected, $command->exposedSortImports($stub));
    }

    public function testSortImportsLeavesNonImportCodeAlone(): void
    {
        $command = new GeneratorCommandStub;

        $stub = <<<'PHP'
<?php

class MyClass {}
PHP;

        $this->assertSame($stub, $command->exposedSortImports($stub));
    }

    public function testQualifyClassPrependsDefaultNamespace(): void
    {
        $command = new GeneratorCommandStub;

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('target-namespace')
            ->andReturn(null);
        $command->setTestInput($input);

        $this->assertSame('App\UserController', $command->exposedQualifyClass('UserController'));
    }

    public function testQualifyClassReplacesForwardSlashesWithBackslashes(): void
    {
        $command = new GeneratorCommandStub;

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('target-namespace')
            ->andReturn(null);
        $command->setTestInput($input);

        $this->assertSame('App\Http\Controllers\UserController', $command->exposedQualifyClass('Http/Controllers/UserController'));
    }

    public function testQualifyClassUsesCustomTargetNamespaceOption(): void
    {
        $command = new GeneratorCommandStub;

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('target-namespace')
            ->andReturn('Custom\Namespace');
        $command->setTestInput($input);

        $this->assertSame('Custom\Namespace\UserController', $command->exposedQualifyClass('UserController'));
    }

    public function testWriteFailureDoesNotCreateMatchingTestOrReportSuccess(): void
    {
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->andReturnFalse();
        $files->shouldReceive('ensureDirectoryExists')->once();
        $files->shouldReceive('get')->once()->andReturn('<?php class DummyClass {}');
        $files->shouldReceive('replace')->once()->andThrow(new RuntimeException('Unable to replace generated file.'));

        $command = new GeneratorCommandStub($files);
        $command->setHypervel($this->app);
        $application = new ConsoleApplication;
        $application->addCommand($command);
        $tester = new CommandTester($command);

        try {
            $tester->execute(['name' => 'GeneratedClass', '--test' => true]);
            $this->fail('Expected generated file publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to replace generated file.', $exception->getMessage());
        }

        $this->assertFalse($command->matchingTestCreationHandled);
        $this->assertStringNotContainsString('created successfully', $tester->getDisplay());
    }

    public function testForcedReplacementPreservesExistingPermissions(): void
    {
        $directory = ParallelTesting::tempDir('GeneratorCommandTestPermissions');
        $path = $directory . '/GeneratedClass.php';
        $files = new Filesystem;
        $files->ensureDirectoryExists($directory);
        $files->put($path, 'old contents');
        chmod($path, 0640);

        try {
            (new GeneratorCommandStub($files))->exposedReplaceFile($path, 'new contents');

            $this->assertSame('new contents', $files->get($path));
            $this->assertSame(0640, fileperms($path) & 0777);
        } finally {
            $files->deleteDirectory($directory);
        }
    }

    public function testDirectoryCreationFailureSurfacesNamedFilesystemError(): void
    {
        $directory = ParallelTesting::tempDir('GeneratorCommandTestDirectory');
        $blockedPath = $directory . '/blocked';
        $files = new Filesystem;
        $files->ensureDirectoryExists($directory);
        $files->put($blockedPath, 'not a directory');

        try {
            $command = new GeneratorCommandStub($files);
            $command->setHypervel($this->app);
            $application = new ConsoleApplication;
            $application->addCommand($command);
            $tester = new CommandTester($command);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("Unable to create directory [{$blockedPath}].");
            $tester->execute(['name' => 'GeneratedClass', '--target-path' => $blockedPath]);
        } finally {
            $files->deleteDirectory($directory);
        }
    }
}
