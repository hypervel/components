<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Hypervel\View\FileViewFinder;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class ViewFileViewFinderTest extends TestCase
{
    public function testBasicViewFinding(): void
    {
        $finder = $this->getFinder();
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo.blade.php')->andReturn(true);

        $this->assertEquals(__DIR__ . '/foo.blade.php', $finder->find(view: 'foo'));
    }

    public function testCascadingFileLoading(): void
    {
        $finder = $this->getFinder();
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo.blade.php')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo.php')->andReturn(true);

        $this->assertEquals(__DIR__ . '/foo.php', $finder->find('foo'));
    }

    public function testDirectoryCascadingFileLoading(): void
    {
        $finder = $this->getFinder();
        $finder->addLocation(__DIR__ . '/nested');
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo.blade.php')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo.php')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo.css')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo.html')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/nested/foo.blade.php')->andReturn(true);

        $this->assertEquals(__DIR__ . '/nested/foo.blade.php', $finder->find('foo'));
    }

    public function testNamespacedBasicFileLoading(): void
    {
        $finder = $this->getFinder();
        $finder->addNamespace('foo', __DIR__ . '/foo');
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo/bar/baz.blade.php')->andReturn(true);

        $this->assertEquals(__DIR__ . '/foo/bar/baz.blade.php', $finder->find('foo::bar.baz'));
    }

    public function testCascadingNamespacedFileLoading(): void
    {
        $finder = $this->getFinder();
        $finder->addNamespace('foo', __DIR__ . '/foo');
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo/bar/baz.blade.php')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo/bar/baz.php')->andReturn(true);

        $this->assertEquals(__DIR__ . '/foo/bar/baz.php', $finder->find('foo::bar.baz'));
    }

    public function testDirectoryCascadingNamespacedFileLoading(): void
    {
        $finder = $this->getFinder();
        $finder->addNamespace('foo', [__DIR__ . '/foo', __DIR__ . '/bar']);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo/bar/baz.blade.php')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo/bar/baz.php')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo/bar/baz.css')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo/bar/baz.html')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/bar/bar/baz.blade.php')->andReturn(true);

        $this->assertEquals(__DIR__ . '/bar/bar/baz.blade.php', $finder->find('foo::bar.baz'));
    }

    public function testExceptionThrownWhenViewNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $finder = $this->getFinder();
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo.blade.php')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo.php')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo.css')->andReturn(false);
        $finder->getFilesystem()->expects('exists')->with(__DIR__ . '/foo.html')->andReturn(false);

        $finder->find('foo');
    }

    public function testExceptionThrownOnInvalidViewName(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('No hint path defined for [name].'));

        $finder = $this->getFinder();
        $finder->find('name::');
    }

    public function testExceptionThrownWhenNoHintPathIsRegistered(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('No hint path defined for [name].'));

        $finder = $this->getFinder();
        $finder->find('name::foo');
    }

    public function testAddingExtensionPrependsNotAppends(): void
    {
        $finder = $this->getFinder();
        $finder->addExtension('baz');
        $extensions = $finder->getExtensions();
        $this->assertSame('baz', reset($extensions));
    }

    public function testAddingExtensionsReplacesOldOnes(): void
    {
        $finder = $this->getFinder();
        $finder->addExtension('baz');
        $finder->addExtension('baz');

        $this->assertCount(5, $finder->getExtensions());
    }

    public function testPassingViewWithHintReturnsTrue(): void
    {
        $finder = $this->getFinder();

        $this->assertTrue($finder->hasHintInformation('hint::foo.bar'));
    }

    public function testPassingViewWithoutHintReturnsFalse(): void
    {
        $finder = $this->getFinder();

        $this->assertFalse($finder->hasHintInformation('foo.bar'));
    }

    public function testPassingViewWithFalseHintReturnsFalse(): void
    {
        $finder = $this->getFinder();

        $this->assertFalse($finder->hasHintInformation('::foo.bar'));
    }

    #[DataProvider('pathsProvider')]
    public function testNormalizedPaths(string $originalPath, string $exceptedPath): void
    {
        $finder = $this->getFinder();
        $finder->prependLocation($originalPath);
        $normalizedPath = $finder->getPaths()[0];
        $this->assertSame($exceptedPath, $normalizedPath);
    }

    /**
     * Provide paths and their normalized forms.
     *
     * @return array<array{string, string}>
     */
    public static function pathsProvider(): array
    {
        return [
            ['incorrect_path', 'incorrect_path'],
        ];
    }

    /**
     * Create a view finder with a mocked filesystem.
     */
    protected function getFinder(): FileViewFinder
    {
        return new FileViewFinder(m::mock(Filesystem::class), [__DIR__]);
    }
}
