<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Console\Command\GeneratorCommandStub;
use Mockery as m;
use ReflectionProperty;
use Symfony\Component\Console\Input\InputInterface;

/**
 * @internal
 * @coversNothing
 */
class GeneratorCommandTest extends TestCase
{
    public function testGetPathWithRelativePath()
    {
        $command = new GeneratorCommandStub();

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('path')
            ->andReturn('packages/my-package/src');
        $command->setTestInput($input);

        $path = $command->exposedGetPath('MyNamespace\MyClass');

        $this->assertSame(BASE_PATH . '/packages/my-package/src/MyClass.php', $path);
    }

    public function testGetPathWithRelativePathWithTrailingSlash()
    {
        $command = new GeneratorCommandStub();

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('path')
            ->andReturn('packages/my-package/src/');
        $command->setTestInput($input);

        $path = $command->exposedGetPath('MyNamespace\MyClass');

        $this->assertSame(BASE_PATH . '/packages/my-package/src/MyClass.php', $path);
    }

    public function testGetPathWithAbsolutePath()
    {
        $command = new GeneratorCommandStub();

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('path')
            ->andReturn('/tmp/custom-path');
        $command->setTestInput($input);

        $path = $command->exposedGetPath('MyNamespace\MyClass');

        $this->assertSame('/tmp/custom-path/MyClass.php', $path);
    }

    public function testGetPathWithAbsolutePathWithTrailingSlash()
    {
        $command = new GeneratorCommandStub();

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('path')
            ->andReturn('/tmp/custom-path/');
        $command->setTestInput($input);

        $path = $command->exposedGetPath('MyNamespace\MyClass');

        $this->assertSame('/tmp/custom-path/MyClass.php', $path);
    }

    public function testGetPathExtractsClassNameFromDeeplyNestedNamespace()
    {
        $command = new GeneratorCommandStub();

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('path')
            ->andReturn('src/Controllers');
        $command->setTestInput($input);

        $path = $command->exposedGetPath('App\Http\Controllers\Api\V1\UserController');

        $this->assertSame(BASE_PATH . '/src/Controllers/UserController.php', $path);
    }

    public function testGetPathDefaultUsesAppPath()
    {
        // Pre-set the namespace to avoid composer.json lookup in the test environment
        $reflection = new ReflectionProperty($this->app, 'namespace');
        $reflection->setValue($this->app, 'App\\');

        $command = new GeneratorCommandStub();

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('path')
            ->andReturn(null);
        $command->setTestInput($input);

        $path = $command->exposedGetPath('App\Http\Controllers\UserController');

        $appPath = $this->app->path();
        $this->assertSame($appPath . '/Http/Controllers/UserController.php', $path);
    }

    public function testPathOptionIsRegistered()
    {
        $command = new GeneratorCommandStub();

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('path'));
        $this->assertFalse($definition->getOption('path')->isValueRequired());
        $this->assertNull($definition->getOption('path')->getDefault());
    }

    public function testForceOptionIsRegistered()
    {
        $command = new GeneratorCommandStub();

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('force'));
        $this->assertFalse($definition->getOption('force')->acceptValue());
    }

    public function testNamespaceOptionIsRegistered()
    {
        $command = new GeneratorCommandStub();

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('namespace'));
        $this->assertNull($definition->getOption('namespace')->getDefault());
    }

    public function testIsReservedNameReturnsTrueForReservedWords()
    {
        $command = new GeneratorCommandStub();

        $this->assertTrue($command->exposedIsReservedName('class'));
        $this->assertTrue($command->exposedIsReservedName('match'));
        $this->assertTrue($command->exposedIsReservedName('enum'));
        $this->assertTrue($command->exposedIsReservedName('yield'));
        $this->assertTrue($command->exposedIsReservedName('__CLASS__'));
    }

    public function testIsReservedNameReturnsFalseForNonReservedWords()
    {
        $command = new GeneratorCommandStub();

        $this->assertFalse($command->exposedIsReservedName('User'));
        $this->assertFalse($command->exposedIsReservedName('PostController'));
        $this->assertFalse($command->exposedIsReservedName('MyCustomClass'));
    }

    public function testIsReservedNameIsCaseInsensitive()
    {
        $command = new GeneratorCommandStub();

        $this->assertTrue($command->exposedIsReservedName('Class'));
        $this->assertTrue($command->exposedIsReservedName('CLASS'));
        $this->assertTrue($command->exposedIsReservedName('Match'));
        $this->assertTrue($command->exposedIsReservedName('ENUM'));
    }

    public function testSortImportsAlphabeticallySortsUseStatements()
    {
        $command = new GeneratorCommandStub();

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

    public function testSortImportsLeavesNonImportCodeAlone()
    {
        $command = new GeneratorCommandStub();

        $stub = <<<'PHP'
<?php

class MyClass {}
PHP;

        $this->assertSame($stub, $command->exposedSortImports($stub));
    }

    public function testQualifyClassPrependsDefaultNamespace()
    {
        $command = new GeneratorCommandStub();

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('namespace')
            ->andReturn(null);
        $command->setTestInput($input);

        $this->assertSame('App\UserController', $command->exposedQualifyClass('UserController'));
    }

    public function testQualifyClassReplacesForwardSlashesWithBackslashes()
    {
        $command = new GeneratorCommandStub();

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('namespace')
            ->andReturn(null);
        $command->setTestInput($input);

        $this->assertSame('App\Http\Controllers\UserController', $command->exposedQualifyClass('Http/Controllers/UserController'));
    }

    public function testQualifyClassUsesCustomNamespaceOption()
    {
        $command = new GeneratorCommandStub();

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')
            ->with('namespace')
            ->andReturn('Custom\Namespace');
        $command->setTestInput($input);

        $this->assertSame('Custom\Namespace\UserController', $command->exposedQualifyClass('UserController'));
    }
}
