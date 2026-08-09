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

    protected ?ClassAliasAutoloader $loader = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classmapPath = __DIR__ . '/Fixtures/Vendor/composer/autoload_classmap.php';
    }

    protected function tearDown(): void
    {
        try {
            $this->loader?->unregister();
        } finally {
            parent::tearDown();
        }
    }

    public function testCanAliasClasses(): void
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

    public function testCanExcludeNamespacesFromAliasing(): void
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

    public function testVendorClassesAreExcluded(): void
    {
        $this->loader = ClassAliasAutoloader::register(
            $shell = m::mock(Shell::class),
            $this->classmapPath
        );

        $shell->shouldNotReceive('writeStdout');

        $this->assertFalse(class_exists('TinkerThree'));
    }

    public function testVendorClassesCanBeWhitelisted(): void
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

    public function testIncludedAliasesMatchClassAndNamespaceBoundaries(): void
    {
        $loader = new ClassAliasAutoloader(
            m::mock(Shell::class),
            $this->classmapPath,
            ['Acme\Package\Thing\\'],
        );
        $vendorPath = dirname($this->classmapPath, 2);

        $this->assertTrue($loader->isAliasable('Acme\Package\Thing', $vendorPath . '/Thing.php'));
        $this->assertTrue($loader->isAliasable('Acme\Package\Thing\Child', $vendorPath . '/Child.php'));
        $this->assertFalse($loader->isAliasable('Acme\Package\ThingElse', $vendorPath . '/ThingElse.php'));
    }

    public function testExcludedAliasesMatchClassAndNamespaceBoundaries(): void
    {
        $loader = new ClassAliasAutoloader(
            m::mock(Shell::class),
            $this->classmapPath,
            [],
            ['App\Nova\\'],
        );
        $applicationPath = dirname($this->classmapPath, 3) . '/App';

        $this->assertFalse($loader->isAliasable('App\Nova', $applicationPath . '/Nova.php'));
        $this->assertFalse($loader->isAliasable('App\Nova\Resource', $applicationPath . '/Resource.php'));
        $this->assertTrue($loader->isAliasable('App\NovaThing', $applicationPath . '/NovaThing.php'));
    }

    public function testVendorPathsMatchDirectoryBoundaries(): void
    {
        $loader = new ClassAliasAutoloader(m::mock(Shell::class), $this->classmapPath);
        $vendorPath = dirname($this->classmapPath, 2);

        $this->assertFalse($loader->isAliasable('Vendor\Package\Thing', $vendorPath . '/Package/Thing.php'));
        $this->assertTrue($loader->isAliasable('App\VendorThing', $vendorPath . '-local/VendorThing.php'));
        $this->assertFalse($loader->isAliasable('VendorThing', $vendorPath . '-local/VendorThing.php'));
    }
}
