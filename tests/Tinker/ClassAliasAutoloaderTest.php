<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Hypervel\Tests\TestCase;
use Hypervel\Tests\Tinker\Fixtures\App\Foo\TinkerBar;
use Hypervel\Tests\Tinker\Fixtures\Vendor\One\Two\TinkerThree;
use Hypervel\Tinker\ClassAliasAutoloader;
use Psy\Shell;
use Symfony\Component\Console\Output\BufferedOutput;

class ClassAliasAutoloaderTest extends TestCase
{
    protected string $classmapPath;

    protected ?ClassAliasAutoloader $loader = null;

    protected Shell $shell;

    protected BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classmapPath = __DIR__ . '/Fixtures/Vendor/composer/autoload_classmap.php';
        $this->output = new BufferedOutput;
        $this->shell = new Shell;
        $this->shell->setOutput($this->output);
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
            $this->shell,
            $this->classmapPath
        );

        $this->assertTrue(class_exists('TinkerBar'));
        $this->assertSame("[!] Aliasing 'TinkerBar' to 'Hypervel\\Tests\\Tinker\\Fixtures\\App\\Foo\\TinkerBar' for this Tinker session.\n", $this->output->fetch());
        $this->assertInstanceOf(TinkerBar::class, new \TinkerBar);
    }

    public function testCanExcludeNamespacesFromAliasing(): void
    {
        $this->loader = ClassAliasAutoloader::register(
            $this->shell,
            $this->classmapPath,
            [],
            ['Hypervel\Tests\Tinker\Fixtures\App\Baz']
        );

        $this->assertFalse(class_exists('TinkerQux'));
        $this->assertSame('', $this->output->fetch());
    }

    public function testVendorClassesAreExcluded(): void
    {
        $loader = new ClassAliasAutoloader(
            $this->shell,
            $this->classmapPath
        );

        // PHP class aliases are permanent, so call the loader directly instead of
        // checking class_exists(), which a whitelisting test may already satisfy.
        $loader->aliasClass('TinkerThree');

        $this->assertSame('', $this->output->fetch());
    }

    public function testVendorClassesCanBeWhitelisted(): void
    {
        $this->loader = ClassAliasAutoloader::register(
            $this->shell,
            $this->classmapPath,
            ['Hypervel\Tests\Tinker\Fixtures\Vendor\One\Two']
        );

        $this->assertTrue(class_exists('TinkerThree'));
        $this->assertSame("[!] Aliasing 'TinkerThree' to 'Hypervel\\Tests\\Tinker\\Fixtures\\Vendor\\One\\Two\\TinkerThree' for this Tinker session.\n", $this->output->fetch());
        $this->assertInstanceOf(TinkerThree::class, new \TinkerThree);
    }

    public function testIncludedAliasesMatchClassAndNamespaceBoundaries(): void
    {
        $loader = new ClassAliasAutoloader(
            $this->shell,
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
            $this->shell,
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
        $loader = new ClassAliasAutoloader($this->shell, $this->classmapPath);
        $vendorPath = dirname($this->classmapPath, 2);

        $this->assertFalse($loader->isAliasable('Vendor\Package\Thing', $vendorPath . '/Package/Thing.php'));
        $this->assertTrue($loader->isAliasable('App\VendorThing', $vendorPath . '-local/VendorThing.php'));
        $this->assertFalse($loader->isAliasable('VendorThing', $vendorPath . '-local/VendorThing.php'));
    }
}
