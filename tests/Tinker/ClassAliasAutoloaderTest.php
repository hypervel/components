<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Hypervel\Tests\TestCase;
use Hypervel\Tests\Tinker\Fixtures\App\Foo\TinkerBar;
use Hypervel\Tests\Tinker\Fixtures\Vendor\One\Two\TinkerThree;
use Hypervel\Tinker\ClassAliasAutoloader;
use Mockery as m;
use Psy\Shell;

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
            ->with("[!] Aliasing 'TinkerBar' to 'Hypervel\\Tests\\Tinker\\Fixtures\\App\\Foo\\TinkerBar' for this Tinker session.\n")
            ->once();

        $this->assertTrue(class_exists('TinkerBar'));
        $this->assertInstanceOf(TinkerBar::class, new \TinkerBar);
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

        $this->assertFalse(class_exists('TinkerQux'));
    }

    public function testVendorClassesAreExcluded()
    {
        $this->loader = ClassAliasAutoloader::register(
            $shell = m::mock(Shell::class),
            $this->classmapPath
        );

        $shell->shouldNotReceive('writeStdout');

        $this->assertFalse(class_exists('TinkerThree'));
    }

    public function testVendorClassesCanBeWhitelisted()
    {
        $this->loader = ClassAliasAutoloader::register(
            $shell = m::mock(Shell::class),
            $this->classmapPath,
            ['Hypervel\Tests\Tinker\Fixtures\Vendor\One\Two']
        );

        $shell->shouldReceive('writeStdout')
            ->with("[!] Aliasing 'TinkerThree' to 'Hypervel\\Tests\\Tinker\\Fixtures\\Vendor\\One\\Two\\TinkerThree' for this Tinker session.\n")
            ->once();

        $this->assertTrue(class_exists('TinkerThree'));
        $this->assertInstanceOf(TinkerThree::class, new \TinkerThree);
    }
}
