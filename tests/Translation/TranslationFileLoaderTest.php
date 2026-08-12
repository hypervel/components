<?php

declare(strict_types=1);

namespace Hypervel\Tests\Translation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\FileLoader;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class TranslationFileLoaderTest extends TestCase
{
    public function testLoadMethodLoadsTranslationsFromAddedPath(): void
    {
        $files = m::mock(Filesystem::class);
        $loader = new FileLoader($files, __DIR__);
        $loader->addPath(__DIR__ . '/another');

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en/messages.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/en/messages.php')->andReturn(['foo' => 'bar']);

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/another/en/messages.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/another/en/messages.php')->andReturn(['baz' => 'backagesplash']);

        $this->assertEquals(['foo' => 'bar', 'baz' => 'backagesplash'], $loader->load('en', 'messages'));
    }

    public function testLoadMethodHandlesMissingAddedPath(): void
    {
        $files = m::mock(Filesystem::class);
        $loader = new FileLoader($files, __DIR__);
        $loader->addPath(__DIR__ . '/missing');

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en/messages.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/en/messages.php')->andReturn(['foo' => 'bar']);

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/missing/en/messages.php')->andReturn(false);

        $this->assertEquals(['foo' => 'bar'], $loader->load('en', 'messages'));
    }

    public function testLoadMethodOverwritesExistingKeysFromAddedPath(): void
    {
        $files = m::mock(Filesystem::class);
        $loader = new FileLoader($files, __DIR__);
        $loader->addPath(__DIR__ . '/another');

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en/messages.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/en/messages.php')->andReturn(['foo' => 'bar']);

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/another/en/messages.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/another/en/messages.php')->andReturn(['foo' => 'baz']);

        $this->assertEquals(['foo' => 'baz'], $loader->load('en', 'messages'));
    }

    public function testLoadMethodLoadsTranslationsFromMultipleAddedPaths(): void
    {
        $files = m::mock(Filesystem::class);
        $loader = new FileLoader($files, __DIR__);
        $loader->addPath(__DIR__ . '/another');
        $loader->addPath(__DIR__ . '/yet-another');

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en/messages.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/en/messages.php')->andReturn(['foo' => 'bar']);

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/another/en/messages.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/another/en/messages.php')->andReturn(['baz' => 'backagesplash']);

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/yet-another/en/messages.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/yet-another/en/messages.php')->andReturn(['qux' => 'quux']);

        $this->assertEquals(['foo' => 'bar', 'baz' => 'backagesplash', 'qux' => 'quux'], $loader->load('en', 'messages'));
    }

    public function testLoadMethodWithoutNamespacesProperlyCallsLoader(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en/foo.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/en/foo.php')->andReturn(['messages']);

        $this->assertEquals(['messages'], $loader->load('en', 'foo', null));
    }

    public function testLoadMethodWithoutNamespacesProperlyCallsLoaderWithMultiplePaths(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), [__DIR__, __DIR__ . '/second']);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en/foo.php')->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/second/en/foo.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/en/foo.php')->andReturn(['messages' => 'first']);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/second/en/foo.php')->andReturn(['messages' => 'second']);

        $this->assertEquals(['messages' => 'second'], $loader->load('en', 'foo', null));
    }

    public function testLoadMethodWithNamespacesProperlyCallsLoader(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);
        $files->shouldReceive('exists')->once()->with('bar/en/foo.php')->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/vendor/namespace/en/foo.php')->andReturn(false);
        $files->shouldReceive('getRequire')->once()->with('bar/en/foo.php')->andReturn(['foo' => 'bar']);
        $loader->addNamespace('namespace', 'bar');

        $this->assertEquals(['foo' => 'bar'], $loader->load('en', 'foo', 'namespace'));
    }

    public function testLoadMethodWithNamespacesProperlyCallsLoaderWithMultiplePaths(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), [__DIR__, __DIR__ . '/second']);
        $files->shouldReceive('exists')->once()->with('test-namespace-dir/en/foo.php')->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/vendor/namespace/en/foo.php')->andReturn(false);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/second/vendor/namespace/en/foo.php')->andReturn(false);
        $files->shouldReceive('getRequire')->once()->with('test-namespace-dir/en/foo.php')->andReturn(['foo' => 'bar']);
        $loader->addNamespace('namespace', 'test-namespace-dir');

        $this->assertEquals(['foo' => 'bar'], $loader->load('en', 'foo', 'namespace'));
    }

    public function testLoadMethodWithNamespacesProperlyCallsLoaderAndLoadsLocalOverrides(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);
        $files->shouldReceive('exists')->once()->with('bar/en/foo.php')->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/vendor/namespace/en/foo.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with('bar/en/foo.php')->andReturn(['foo' => 'bar']);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/vendor/namespace/en/foo.php')->andReturn(['foo' => 'override', 'baz' => 'boom']);
        $loader->addNamespace('namespace', 'bar');

        $this->assertEquals(['foo' => 'override', 'baz' => 'boom'], $loader->load('en', 'foo', 'namespace'));
    }

    public function testLoadMethodWithNamespacesProperlyCallsLoaderAndLoadsLocalOverridesWithMultiplePaths(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), [__DIR__, __DIR__ . '/second']);
        $files->shouldReceive('exists')->once()->with('test-namespace-dir/en/foo.php')->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/vendor/namespace/en/foo.php')->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/second/vendor/namespace/en/foo.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with('test-namespace-dir/en/foo.php')->andReturn(['foo' => 'bar']);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/vendor/namespace/en/foo.php')->andReturn(['foo' => 'override', 'baz' => 'boom']);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/second/vendor/namespace/en/foo.php')->andReturn(['foo' => 'override-2', 'baz' => 'boom-2']);
        $loader->addNamespace('namespace', 'test-namespace-dir');

        $this->assertEquals(['foo' => 'override-2', 'baz' => 'boom-2'], $loader->load('en', 'foo', 'namespace'));
    }

    public function testLoadMethodWithNamespacesProperlyCallsLoaderAndLoadsLocalOverridesWithMultiplePathsWithMissingKey(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), [__DIR__, __DIR__ . '/second']);
        $files->shouldReceive('exists')->once()->with('test-namespace-dir/en/foo.php')->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/vendor/namespace/en/foo.php')->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/second/vendor/namespace/en/foo.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with('test-namespace-dir/en/foo.php')->andReturn(['foo' => 'bar']);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/vendor/namespace/en/foo.php')->andReturn(['foo' => 'override', 'baz' => 'boom']);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/second/vendor/namespace/en/foo.php')->andReturn(['baz' => 'boom-2']);
        $loader->addNamespace('namespace', 'test-namespace-dir');

        $this->assertEquals(['foo' => 'override', 'baz' => 'boom-2'], $loader->load('en', 'foo', 'namespace'));
    }

    #[DataProvider('invalidLocaleProvider')]
    public function testInvalidLocalesAreRejectedBeforeFilesystemAccess(string $locale): void
    {
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->never();
        $files->shouldReceive('get')->never();
        $files->shouldReceive('getRequire')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid characters present in locale.');

        (new FileLoader($files, __DIR__))->load($locale, 'messages');
    }

    public static function invalidLocaleProvider(): array
    {
        return [
            'forward slash' => ['en/US'],
            'backslash' => ['en\US'],
            'current directory' => ['.'],
            'parent directory' => ['..'],
        ];
    }

    public function testDotBearingLocalesReachPhpAndJsonTranslationPaths(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en.UTF-8/messages.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/en.UTF-8/messages.php')->andReturn(['foo' => 'bar']);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en.UTF-8.json')->andReturn(true);
        $files->shouldReceive('get')->once()->with(__DIR__ . '/en.UTF-8.json')->andReturn('{"foo":"bar"}');

        $this->assertSame(['foo' => 'bar'], $loader->load('en.UTF-8', 'messages'));
        $this->assertSame(['foo' => 'bar'], $loader->load('en.UTF-8', '*', '*'));
    }

    public function testEmptyArraysReturnedWhenFilesDontExist(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en/foo.php')->andReturn(false);
        $files->shouldReceive('getRequire')->never();

        $this->assertEquals([], $loader->load('en', 'foo', null));
    }

    public function testEmptyArraysReturnedWhenFilesDontExistForNamespacedItems(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);
        $files->shouldReceive('getRequire')->never();

        $this->assertEquals([], $loader->load('en', 'foo', 'bar'));
    }

    public function testLoadMethodForJSONProperlyCallsLoader(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en.json')->andReturn(true);
        $files->shouldReceive('get')->once()->with(__DIR__ . '/en.json')->andReturn('{"foo":"bar"}');

        $this->assertEquals(['foo' => 'bar'], $loader->load('en', '*', '*'));
    }

    public function testLoadMethodForJsonAcceptsArraysWithNumericKeys(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en.json')->andReturn(true);
        $files->shouldReceive('get')->once()->with(__DIR__ . '/en.json')->andReturn('["first","second"]');

        $this->assertSame(['first', 'second'], $loader->load('en', '*', '*'));
    }

    public function testLoadMethodForJSONProperlyCallsLoaderForMultiplePaths(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);
        $loader->addJsonPath(__DIR__ . '/another');

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en.json')->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/another/en.json')->andReturn(true);
        $files->shouldReceive('get')->once()->with(__DIR__ . '/en.json')->andReturn('{"foo":"bar"}');
        $files->shouldReceive('get')->once()->with(__DIR__ . '/another/en.json')->andReturn('{"foo":"backagebar", "baz": "backagesplash"}');

        $this->assertEquals(['foo' => 'bar', 'baz' => 'backagesplash'], $loader->load('en', '*', '*'));
    }

    #[DataProvider('invalidJsonRootProvider')]
    public function testLoadMethodThrowsForInvalidJsonRoots(string $json): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);
        $loader->addJsonPath(__DIR__ . '/invalid');

        $files->shouldReceive('exists')->once()->with(__DIR__ . '/invalid/en.json')->andReturn(true);
        $files->shouldReceive('get')->once()->with(__DIR__ . '/invalid/en.json')->andReturn($json);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Translation file [' . __DIR__ . '/invalid/en.json] contains an invalid JSON structure.'
        );

        $loader->load('en', '*', '*');
    }

    public static function invalidJsonRootProvider(): array
    {
        return [
            'integer' => ['1'],
            'true' => ['true'],
            'false' => ['false'],
            'string' => ['"translation"'],
            'null' => ['null'],
            'malformed' => ['.{"foo":"bar"}'],
        ];
    }

    public function testLoadMethodRejectsScalarJsonTranslationValues(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en.json')->andReturn(true);
        $files->shouldReceive('get')->once()->with(__DIR__ . '/en.json')->andReturn('{"unread":0}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Translation file [' . __DIR__ . '/en.json] contains an invalid value for key [unread]. Translation values must be strings or arrays.'
        );

        $loader->load('en', '*', '*');
    }

    public function testLoadMethodAllowsNullJsonTranslationValues(): void
    {
        $loader = new FileLoader($files = m::mock(Filesystem::class), __DIR__);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en.json')->andReturn(true);
        $files->shouldReceive('get')->once()->with(__DIR__ . '/en.json')->andReturn('{"untranslated":null}');

        $this->assertSame(['untranslated' => null], $loader->load('en', '*', '*'));
    }

    public function testAllRegisteredNamespaceReturnProperly(): void
    {
        $loader = new FileLoader(m::mock(Filesystem::class), __DIR__);
        $loader->addNamespace('namespace', 'foo');
        $loader->addNamespace('namespace2', 'bar');
        $this->assertEquals(['namespace' => 'foo', 'namespace2' => 'bar'], $loader->namespaces());
    }

    public function testAllAddedJsonPathsReturnProperly(): void
    {
        $loader = new FileLoader(m::mock(Filesystem::class), __DIR__);
        $path1 = __DIR__ . '/another';
        $path2 = __DIR__ . '/another2';
        $loader->addJsonPath($path1);
        $loader->addJsonPath($path2);
        $this->assertEquals([$path1, $path2], $loader->jsonPaths());
    }

    public function testAllAddedPathsReturnProperly(): void
    {
        $loader = new FileLoader(m::mock(Filesystem::class), __DIR__);
        $path1 = __DIR__ . '/another';
        $path2 = __DIR__ . '/another2';
        $loader->addPath($path1);
        $loader->addPath($path2);
        $this->assertEquals([$path1, $path2], array_slice($loader->paths(), 1));
    }
}
