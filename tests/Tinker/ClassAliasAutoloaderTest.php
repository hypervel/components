<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Hypervel\Tests\TestCase;
use Hypervel\Tests\Tinker\Fixtures\App\Foo\Bar;
use Hypervel\Tests\Tinker\Fixtures\Vendor\One\Two\Three;
use Hypervel\Tinker\ClassAliasAutoloader;
use Mockery as m;
use Psy\Shell;

/**
 * @internal
 * @coversNothing
 */
class ClassAliasAutoloaderTest extends TestCase
{
    protected string $classmapPath;

    protected ClassAliasAutoloader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classmapPath = __DIR__ . '/Fixtures/Vendor/composer/autoload_classmap.php';
    }

    protected function tearDown(): void
    {
        $this->loader->unregister();

        parent::tearDown();
    }

    public function testCanAliasClasses()
    {
        $this->loader = ClassAliasAutoloader::register(
            $shell = m::mock(Shell::class),
            $this->classmapPath
        );

        $shell->shouldReceive('writeStdout')
            ->with("[!] Aliasing 'Bar' to 'Hypervel\\Tests\\Tinker\\Fixtures\\App\\Foo\\Bar' for this Tinker session.\n")
            ->once();

        $this->assertTrue(class_exists('Bar'));
        $this->assertInstanceOf(Bar::class, new \Bar());
    }

    public function testCanExcludeNamespacesFromAliasing()
    {
        $this->loader = ClassAliasAutoloader::register(
            $shell = m::mock(Shell::class),
            $this->classmapPath,
            [],
            ['Hypervel\Tests\Tinker\Fixtures\App\Baz']
        );

        $shell->shouldNotReceive('writeStdout');

        $this->assertFalse(class_exists('Qux'));
    }

    public function testVendorClassesAreExcluded()
    {
        $this->loader = ClassAliasAutoloader::register(
            $shell = m::mock(Shell::class),
            $this->classmapPath
        );

        $shell->shouldNotReceive('writeStdout');

        $this->assertFalse(class_exists('Three'));
    }

    public function testVendorClassesCanBeWhitelisted()
    {
        $this->loader = ClassAliasAutoloader::register(
            $shell = m::mock(Shell::class),
            $this->classmapPath,
            ['Hypervel\Tests\Tinker\Fixtures\Vendor\One\Two']
        );

        $shell->shouldReceive('writeStdout')
            ->with("[!] Aliasing 'Three' to 'Hypervel\\Tests\\Tinker\\Fixtures\\Vendor\\One\\Two\\Three' for this Tinker session.\n")
            ->once();

        $this->assertTrue(class_exists('Three'));
        $this->assertInstanceOf(Three::class, new \Three());
    }
}
