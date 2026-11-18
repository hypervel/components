<?php

declare(strict_types=1);

namespace Hypervel\Tests\Translation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\FileLoader;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class TranslationFileLoaderTest extends TestCase
{
    use VerifiesDoubles;

    public function testLoadMethodLoadsTranslationsFromAddedPath(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $loader = new FileLoader($files, __DIR__);
        $loader->addPath(__DIR__ . '/another');

        $files->expects('exists')->with(__DIR__ . '/en/messages.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/en/messages.php')->returns(['foo' => 'bar']);

        $files->expects('exists')->with(__DIR__ . '/another/en/messages.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/another/en/messages.php')->returns(['baz' => 'backagesplash']);

        $this->assertEquals(['foo' => 'bar', 'baz' => 'backagesplash'], $loader->load('en', 'messages'));
    }

    public function testLoadMethodIgnoresLocalesAndGroupsContainingPathTraversal(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('exists')->never();
        $files->expects('getRequire')->never();
        $loader = new FileLoader($files, __DIR__);
        $loader->addNamespace('namespace', __DIR__ . '/namespace');

        // Hypervel rejects unsafe nonempty locales with an exception; see testInvalidLocalesAreRejectedBeforeFilesystemAccess().
        $this->assertEquals([], $loader->load('', 'messages'));
        $this->assertEquals([], $loader->load('', 'messages', 'namespace'));
        $this->assertEquals([], $loader->load('', '*', '*'));
        $this->assertEquals([], $loader->load('en', '../../secret/config'));
        $this->assertEquals([], $loader->load('en', '..\secret\config'));
        $this->assertEquals([], $loader->load('en', ''));
        $this->assertEquals([], $loader->load('en', "messages\0"));
        $this->assertEquals([], $loader->load('en', '../secret', 'namespace'));
    }

    public function testLoadMethodAllowsGroupsInSubdirectories(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $loader = new FileLoader($files, __DIR__);

        $files->expects('exists')->with(__DIR__ . '/en/admin/messages.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/en/admin/messages.php')->returns(['foo' => 'bar']);

        $this->assertEquals(['foo' => 'bar'], $loader->load('en', 'admin/messages'));
    }

    public function testLoadMethodHandlesMissingAddedPath(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $loader = new FileLoader($files, __DIR__);
        $loader->addPath(__DIR__ . '/missing');

        $files->expects('exists')->with(__DIR__ . '/en/messages.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/en/messages.php')->returns(['foo' => 'bar']);

        $files->expects('exists')->with(__DIR__ . '/missing/en/messages.php')->returns(false);

        $this->assertEquals(['foo' => 'bar'], $loader->load('en', 'messages'));
    }

    public function testLoadMethodOverwritesExistingKeysFromAddedPath(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $loader = new FileLoader($files, __DIR__);
        $loader->addPath(__DIR__ . '/another');

        $files->expects('exists')->with(__DIR__ . '/en/messages.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/en/messages.php')->returns(['foo' => 'bar']);

        $files->expects('exists')->with(__DIR__ . '/another/en/messages.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/another/en/messages.php')->returns(['foo' => 'baz']);

        $this->assertEquals(['foo' => 'baz'], $loader->load('en', 'messages'));
    }

    public function testLoadMethodLoadsTranslationsFromMultipleAddedPaths(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $loader = new FileLoader($files, __DIR__);
        $loader->addPath(__DIR__ . '/another');
        $loader->addPath(__DIR__ . '/yet-another');

        $files->expects('exists')->with(__DIR__ . '/en/messages.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/en/messages.php')->returns(['foo' => 'bar']);

        $files->expects('exists')->with(__DIR__ . '/another/en/messages.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/another/en/messages.php')->returns(['baz' => 'backagesplash']);

        $files->expects('exists')->with(__DIR__ . '/yet-another/en/messages.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/yet-another/en/messages.php')->returns(['qux' => 'quux']);

        $this->assertEquals(['foo' => 'bar', 'baz' => 'backagesplash', 'qux' => 'quux'], $loader->load('en', 'messages'));
    }

    public function testLoadMethodWithoutNamespacesProperlyCallsLoader(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $loader = new FileLoader($files, __DIR__);
        $files->expects('exists')->with(__DIR__ . '/en/foo.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/en/foo.php')->returns(['messages']);

        $this->assertEquals(['messages'], $loader->load('en', 'foo', null));
    }

    public function testLoadMethodWithoutNamespacesProperlyCallsLoaderWithMultiplePaths(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('exists')->with(__DIR__ . '/en/foo.php')->returns(true);
        $files->expects('exists')->with(__DIR__ . '/second/en/foo.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/en/foo.php')->returns(['messages' => 'first']);
        $files->expects('getRequire')->with(__DIR__ . '/second/en/foo.php')->returns(['messages' => 'second']);
        $loader = new FileLoader($files, [__DIR__, __DIR__ . '/second']);

        $this->assertEquals(['messages' => 'second'], $loader->load('en', 'foo', null));
    }

    public function testLoadMethodWithNamespacesProperlyCallsLoader(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('exists')->with('bar/en/foo.php')->returns(true);
        $files->expects('exists')->with(__DIR__ . '/vendor/namespace/en/foo.php')->returns(false);
        $files->expects('getRequire')->with('bar/en/foo.php')->returns(['foo' => 'bar']);
        $loader = new FileLoader($files, __DIR__);
        $loader->addNamespace('namespace', 'bar');

        $this->assertEquals(['foo' => 'bar'], $loader->load('en', 'foo', 'namespace'));
    }

    public function testLoadMethodWithNamespacesProperlyCallsLoaderWithMultiplePaths(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('exists')->with('test-namespace-dir/en/foo.php')->returns(true);
        $files->expects('exists')->with(__DIR__ . '/vendor/namespace/en/foo.php')->returns(false);
        $files->expects('exists')->with(__DIR__ . '/second/vendor/namespace/en/foo.php')->returns(false);
        $files->expects('getRequire')->with('test-namespace-dir/en/foo.php')->returns(['foo' => 'bar']);
        $loader = new FileLoader($files, [__DIR__, __DIR__ . '/second']);
        $loader->addNamespace('namespace', 'test-namespace-dir');

        $this->assertEquals(['foo' => 'bar'], $loader->load('en', 'foo', 'namespace'));
    }

    public function testLoadMethodWithNamespacesProperlyCallsLoaderAndLoadsLocalOverrides(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('exists')->with('bar/en/foo.php')->returns(true);
        $files->expects('exists')->with(__DIR__ . '/vendor/namespace/en/foo.php')->returns(true);
        $files->expects('getRequire')->with('bar/en/foo.php')->returns(['foo' => 'bar']);
        $files->expects('getRequire')->with(__DIR__ . '/vendor/namespace/en/foo.php')->returns(['foo' => 'override', 'baz' => 'boom']);
        $loader = new FileLoader($files, __DIR__);
        $loader->addNamespace('namespace', 'bar');

        $this->assertEquals(['foo' => 'override', 'baz' => 'boom'], $loader->load('en', 'foo', 'namespace'));
    }

    public function testLoadMethodWithNamespacesProperlyCallsLoaderAndLoadsLocalOverridesWithMultiplePaths(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('exists')->with('test-namespace-dir/en/foo.php')->returns(true);
        $files->expects('exists')->with(__DIR__ . '/vendor/namespace/en/foo.php')->returns(true);
        $files->expects('exists')->with(__DIR__ . '/second/vendor/namespace/en/foo.php')->returns(true);
        $files->expects('getRequire')->with('test-namespace-dir/en/foo.php')->returns(['foo' => 'bar']);
        $files->expects('getRequire')->with(__DIR__ . '/vendor/namespace/en/foo.php')->returns(['foo' => 'override', 'baz' => 'boom']);
        $files->expects('getRequire')->with(__DIR__ . '/second/vendor/namespace/en/foo.php')->returns(['foo' => 'override-2', 'baz' => 'boom-2']);
        $loader = new FileLoader($files, [__DIR__, __DIR__ . '/second']);
        $loader->addNamespace('namespace', 'test-namespace-dir');

        $this->assertEquals(['foo' => 'override-2', 'baz' => 'boom-2'], $loader->load('en', 'foo', 'namespace'));
    }

    public function testLoadMethodWithNamespacesProperlyCallsLoaderAndLoadsLocalOverridesWithMultiplePathsWithMissingKey(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('exists')->with('test-namespace-dir/en/foo.php')->returns(true);
        $files->expects('exists')->with(__DIR__ . '/vendor/namespace/en/foo.php')->returns(true);
        $files->expects('exists')->with(__DIR__ . '/second/vendor/namespace/en/foo.php')->returns(true);
        $files->expects('getRequire')->with('test-namespace-dir/en/foo.php')->returns(['foo' => 'bar']);
        $files->expects('getRequire')->with(__DIR__ . '/vendor/namespace/en/foo.php')->returns(['foo' => 'override', 'baz' => 'boom']);
        $files->expects('getRequire')->with(__DIR__ . '/second/vendor/namespace/en/foo.php')->returns(['baz' => 'boom-2']);
        $loader = new FileLoader($files, [__DIR__, __DIR__ . '/second']);
        $loader->addNamespace('namespace', 'test-namespace-dir');

        $this->assertEquals(['foo' => 'override', 'baz' => 'boom-2'], $loader->load('en', 'foo', 'namespace'));
    }

    #[DataProvider('invalidLocaleProvider')]
    public function testInvalidLocalesAreRejectedBeforeFilesystemAccess(string $locale, string $group = 'messages', ?string $namespace = null): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('exists')->never();
        $files->expects('get')->never();
        $files->expects('getRequire')->never();
        $loader = new FileLoader($files, __DIR__);
        $loader->addNamespace('namespace', __DIR__ . '/namespace');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Invalid characters present in locale.');

        $loader->load($locale, $group, $namespace);
    }

    /**
     * Provide invalid locale identifiers.
     */
    public static function invalidLocaleProvider(): array
    {
        return [
            'forward slash' => ['en/US'],
            'backslash' => ['en\US'],
            'current directory' => ['.'],
            'parent directory' => ['..'],
            'parent directory prefix' => ['../secret', 'config'],
            'backslash parent directory prefix' => ['..\secret', 'config'],
            'parent directory with nested group' => ['..', 'secret/config'],
            'null byte' => ["en\0"],
            'namespaced group' => ['../secret', 'config', 'namespace'],
            'json translations' => ['../secret', '*', '*'],
        ];
    }

    public function testDotBearingLocalesReachPhpAndJsonTranslationPaths(): void
    {
        $loader = new FileLoader($files = Double::for(Filesystem::class)->strict(), __DIR__);

        $files->expects('exists')->with(__DIR__ . '/en.UTF-8/messages.php')->returns(true);
        $files->expects('getRequire')->with(__DIR__ . '/en.UTF-8/messages.php')->returns(['foo' => 'bar']);
        $files->expects('exists')->with(__DIR__ . '/en.UTF-8.json')->returns(true);
        $files->expects('get')->with(__DIR__ . '/en.UTF-8.json')->returns('{"foo":"bar"}');

        $this->assertSame(['foo' => 'bar'], $loader->load('en.UTF-8', 'messages'));
        $this->assertSame(['foo' => 'bar'], $loader->load('en.UTF-8', '*', '*'));
    }

    public function testEmptyArraysReturnedWhenFilesDontExist(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('exists')->with(__DIR__ . '/en/foo.php')->returns(false);
        $files->expects('getRequire')->never();
        $loader = new FileLoader($files, __DIR__);

        $this->assertSame([], $loader->load('en', 'foo', null));
    }

    public function testEmptyArraysReturnedWhenFilesDontExistForNamespacedItems(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('getRequire')->never();
        $loader = new FileLoader($files, __DIR__);

        $this->assertSame([], $loader->load('en', 'foo', 'bar'));
    }

    public function testLoadMethodForJSONProperlyCallsLoader(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('exists')->with(__DIR__ . '/en.json')->returns(true);
        $files->expects('get')->with(__DIR__ . '/en.json')->returns('{"foo":"bar"}');
        $loader = new FileLoader($files, __DIR__);

        $this->assertEquals(['foo' => 'bar'], $loader->load('en', '*', '*'));
    }

    public function testLoadMethodForJsonAcceptsArraysWithNumericKeys(): void
    {
        $loader = new FileLoader($files = Double::for(Filesystem::class)->strict(), __DIR__);
        $files->expects('exists')->with(__DIR__ . '/en.json')->returns(true);
        $files->expects('get')->with(__DIR__ . '/en.json')->returns('["first","second"]');

        $this->assertSame(['first', 'second'], $loader->load('en', '*', '*'));
    }

    public function testLoadMethodForJSONProperlyCallsLoaderForMultiplePaths(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $loader = new FileLoader($files, __DIR__);
        $loader->addJsonPath(__DIR__ . '/another');

        $files->expects('exists')->with(__DIR__ . '/en.json')->returns(true);
        $files->expects('exists')->with(__DIR__ . '/another/en.json')->returns(true);
        $files->expects('get')->with(__DIR__ . '/en.json')->returns('{"foo":"bar"}');
        $files->expects('get')->with(__DIR__ . '/another/en.json')->returns('{"foo":"backagebar", "baz": "backagesplash"}');

        $this->assertEquals(['foo' => 'bar', 'baz' => 'backagesplash'], $loader->load('en', '*', '*'));
    }

    public function testLoadMethodForJSONPreservesNumericKeys(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $loader = new FileLoader($files, __DIR__);
        $loader->addJsonPath(__DIR__ . '/another');

        $files->expects('exists')->with(__DIR__ . '/en.json')->returns(true);
        $files->expects('exists')->with(__DIR__ . '/another/en.json')->returns(true);
        $files->expects('get')->with(__DIR__ . '/en.json')->returns('{"404":"Page not found","foo":"bar"}');
        $files->expects('get')->with(__DIR__ . '/another/en.json')->returns('{"404":"Not found","500":"Server error"}');

        $this->assertSame([404 => 'Page not found', 500 => 'Server error', 'foo' => 'bar'], $loader->load('en', '*', '*'));
    }

    #[DataProvider('invalidJsonRootProvider')]
    public function testLoadMethodThrowExceptionWhenProvideInvalidJSON(string $json): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $loader = new FileLoader($files, __DIR__);
        $loader->addJsonPath(__DIR__ . '/invalid');

        $files->expects('exists')->with(__DIR__ . '/invalid/en.json')->returns(true);
        $files->expects('get')->with(__DIR__ . '/invalid/en.json')->returns($json);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'Translation file [' . __DIR__ . '/invalid/en.json] contains an invalid JSON structure.'
        );

        $loader->load('en', '*', '*');
    }

    /**
     * Provide invalid JSON document roots.
     */
    public static function invalidJsonRootProvider(): array
    {
        return [
            'integer' => ['1'],
            'true' => ['true'],
            'false' => ['false'],
            'string' => ['"translation"'],
            'null' => ['null'],
            'malformed' => ['.{"foo":"cricket", "baz": "football"}'],
        ];
    }

    public function testLoadMethodRejectsScalarJsonTranslationValues(): void
    {
        $loader = new FileLoader($files = Double::for(Filesystem::class)->strict(), __DIR__);
        $files->expects('exists')->with(__DIR__ . '/en.json')->returns(true);
        $files->expects('get')->with(__DIR__ . '/en.json')->returns('{"unread":0}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'Translation file [' . __DIR__ . '/en.json] contains an invalid value for key [unread]. Translation values must be strings or arrays.'
        );

        $loader->load('en', '*', '*');
    }

    public function testLoadMethodAllowsNullJsonTranslationValues(): void
    {
        $loader = new FileLoader($files = Double::for(Filesystem::class)->strict(), __DIR__);
        $files->expects('exists')->with(__DIR__ . '/en.json')->returns(true);
        $files->expects('get')->with(__DIR__ . '/en.json')->returns('{"untranslated":null}');

        $this->assertSame(['untranslated' => null], $loader->load('en', '*', '*'));
    }

    public function testAllRegisteredNamespaceReturnProperly(): void
    {
        $loader = new FileLoader(Double::for(Filesystem::class)->strict(), __DIR__);
        $loader->addNamespace('namespace', 'foo');
        $loader->addNamespace('namespace2', 'bar');
        $this->assertEquals(['namespace' => 'foo', 'namespace2' => 'bar'], $loader->namespaces());
    }

    public function testAllAddedJsonPathsReturnProperly(): void
    {
        $loader = new FileLoader(Double::for(Filesystem::class)->strict(), __DIR__);
        $path1 = __DIR__ . '/another';
        $path2 = __DIR__ . '/another2';
        $loader->addJsonPath($path1);
        $loader->addJsonPath($path2);
        $this->assertEquals([$path1, $path2], $loader->jsonPaths());
    }

    public function testAllAddedPathsReturnProperly(): void
    {
        $loader = new FileLoader(Double::for(Filesystem::class)->strict(), __DIR__);
        $path1 = __DIR__ . '/another';
        $path2 = __DIR__ . '/another2';
        $loader->addPath($path1);
        $loader->addPath($path2);
        $this->assertEquals([$path1, $path2], array_slice($loader->paths(), 1));
    }
}
