<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Hypervel\View\Compilers\BladeCompiler;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class ViewBladeCompilerTest extends TestCase
{
    public function testIsExpiredReturnsTrueIfCompiledFileDoesntExist(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php')->andReturn(false);
        $this->assertTrue($compiler->isExpired('foo'));
    }

    public function testCannotConstructWithBadCachePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Please provide a valid cache path.');

        new BladeCompiler($this->getFiles(), '');
    }

    public function testIsExpiredReturnsTrueWhenModificationTimesWarrant(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php')->andReturn(true);
        $files->shouldReceive('lastModified')->once()->with('foo')->andReturn(100);
        $files->shouldReceive('lastModified')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php')->andReturn(0);
        $this->assertTrue($compiler->isExpired('foo'));
    }

    public function testIsExpiredReturnsFalseWhenUseCacheIsTrueAndNoFileModification(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php')->andReturn(true);
        $files->shouldReceive('lastModified')->once()->with('foo')->andReturn(0);
        $files->shouldReceive('lastModified')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php')->andReturn(100);
        $this->assertFalse($compiler->isExpired('foo'));
    }

    public function testIsExpiredReturnsTrueWhenUseCacheIsFalse(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__, shouldCache: false);
        $this->assertTrue($compiler->isExpired('foo'));
    }

    public function testIsExpiredReturnsFalseWhenIgnoreCacheTimestampsIsTrue(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__, shouldCheckTimestamps: false);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php')->andReturn(true);
        $this->assertFalse($compiler->isExpired('foo'));
    }

    public function testCompilePathIsProperlyCreated(): void
    {
        $compiler = new BladeCompiler($this->getFiles(), __DIR__);
        $this->assertEquals(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php', $compiler->getCompiledPath('foo'));
    }

    public function testCompileCompilesFileAndReturnsContents(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('get')->once()->with('foo')->andReturn('Hello World');
        $files->shouldReceive('exists')->once()->with(__DIR__)->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php')->andReturn(false);
        $files->shouldReceive('replace')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php', 'Hello World<?php /**PATH foo ENDPATH**/ ?>');
        $compiler->compile('foo');
    }

    public function testCompileCompilesFileAndReturnsContentsCreatingDirectory(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('get')->once()->with('foo')->andReturn('Hello World');
        $files->shouldReceive('exists')->once()->with(__DIR__)->andReturn(false);
        $files->shouldReceive('makeDirectory')->once()->with(__DIR__, 0777, true, true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php')->andReturn(false);
        $files->shouldReceive('replace')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php', 'Hello World<?php /**PATH foo ENDPATH**/ ?>');
        $compiler->compile('foo');
    }

    public function testCompileUpdatesCacheIfChanged(): void
    {
        $compiledPath = __DIR__ . '/' . hash('xxh128', 'v3foo') . '.php';
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('get')->once()->with('foo')->andReturn('Hello World');
        $files->shouldReceive('exists')->once()->with(__DIR__)->andReturn(true);
        $files->shouldReceive('exists')->once()->with($compiledPath)->andReturn(true);
        $files->shouldReceive('hash')->once()->with($compiledPath, 'xxh128')->andReturn(hash('xxh128', 'outdated content'));
        $files->shouldReceive('replace')->once()->with($compiledPath, 'Hello World<?php /**PATH foo ENDPATH**/ ?>');
        $compiler->compile('foo');
    }

    public function testCompileKeepsCacheIfUnchanged(): void
    {
        $compiledPath = __DIR__ . '/' . hash('xxh128', 'v3foo') . '.php';
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('get')->once()->with('foo')->andReturn('Hello World');
        $files->shouldReceive('exists')->once()->with(__DIR__)->andReturn(true);
        $files->shouldReceive('exists')->once()->with($compiledPath)->andReturn(true);
        $files->shouldReceive('hash')->once()->with($compiledPath, 'xxh128')->andReturn(hash('xxh128', 'Hello World<?php /**PATH foo ENDPATH**/ ?>'));
        $files->shouldReceive('lastModified')->once()->with('foo')->andReturn(100);
        $files->shouldReceive('lastModified')->once()->with($compiledPath)->andReturn(200);
        $files->shouldReceive('replace')->never();
        $compiler->compile('foo');
    }

    public function testCompileRefreshesCacheTimestampIfUnchangedButExpired(): void
    {
        $files = new Filesystem;
        $directory = ParallelTesting::tempDir('ViewBladeCompiler');
        $source = $directory . '/source.blade.php';
        $cache = $directory . '/cache';

        try {
            $files->deleteDirectory($directory);
            $files->ensureDirectoryExists($cache);
            $files->put($source, 'Hello World');

            $compiler = new BladeCompiler($files, $cache);
            $compiler->compile($source);

            $compiled = $compiler->getCompiledPath($source);

            $compiledModified = time();
            $sourceModified = $compiledModified + 10;

            touch($source, $sourceModified);
            touch($compiled, $compiledModified);

            clearstatcache(true, $source);
            clearstatcache(true, $compiled);

            $this->assertTrue($compiler->isExpired($source));

            $compiler->compile($source);

            clearstatcache(true, $source);
            clearstatcache(true, $compiled);

            $this->assertGreaterThan($files->lastModified($source), $files->lastModified($compiled));
            $this->assertFalse($compiler->isExpired($source));
        } finally {
            $files->deleteDirectory($directory);
        }
    }

    public function testCompileCompilesAndGetThePath(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('get')->once()->with('foo')->andReturn('Hello World');
        $files->shouldReceive('exists')->once()->with(__DIR__)->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php')->andReturn(false);
        $files->shouldReceive('replace')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php', 'Hello World<?php /**PATH foo ENDPATH**/ ?>');
        $compiler->compile('foo');
        $this->assertSame('foo', $compiler->getPath());
    }

    public function testCompileSetAndGetThePath(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $compiler->setPath('foo');
        $this->assertSame('foo', $compiler->getPath());
    }

    public function testCompileWithPathSetBefore(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('get')->once()->with('foo')->andReturn('Hello World');
        $files->shouldReceive('exists')->once()->with(__DIR__)->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php')->andReturn(false);
        $files->shouldReceive('replace')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php', 'Hello World<?php /**PATH foo ENDPATH**/ ?>');
        $compiler->setPath('foo');
        $compiler->compile();
        $this->assertSame('foo', $compiler->getPath());
    }

    public function testCompilePathsAreIsolatedBetweenConcurrentCoroutines(): void
    {
        $firstCompiledPath = __DIR__ . '/' . hash('xxh128', 'v3first') . '.php';
        $secondCompiledPath = __DIR__ . '/' . hash('xxh128', 'v3second') . '.php';

        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('get')->once()->with('first')->andReturnUsing(function (): string {
            usleep(5000);

            return 'First';
        });
        $files->shouldReceive('get')->once()->with('second')->andReturn('Second');
        $files->shouldReceive('exists')->twice()->with(__DIR__)->andReturn(true);
        $files->shouldReceive('exists')->once()->with($firstCompiledPath)->andReturn(false);
        $files->shouldReceive('exists')->once()->with($secondCompiledPath)->andReturn(false);
        $files->shouldReceive('replace')->once()->with($firstCompiledPath, 'First<?php /**PATH first ENDPATH**/ ?>');
        $files->shouldReceive('replace')->once()->with($secondCompiledPath, 'Second<?php /**PATH second ENDPATH**/ ?>');

        parallel([
            'first' => fn () => $compiler->compile('first'),
            'second' => fn () => $compiler->compile('second'),
        ]);
    }

    public function testSectionNamesAreIsolatedBetweenConcurrentCompilations(): void
    {
        $compiler = new BladeCompiler($this->getFiles(), __DIR__);
        $firstPaused = new Channel(1);
        $releaseFirst = new Channel(1);

        $compiler->directive('pause', function (string $expression) use ($firstPaused, $releaseFirst): string {
            $firstPaused->push(true);
            $releaseFirst->pop();

            return '';
        });

        try {
            $results = parallel([
                'first' => fn (): string => $compiler->compileString("@section('first') @pause @parent"),
                'second' => function () use ($compiler, $firstPaused, $releaseFirst): string {
                    $firstPaused->pop();
                    $compiled = $compiler->compileString("@section('second') @parent");
                    $releaseFirst->push(true);

                    return $compiled;
                },
            ]);
        } finally {
            $firstPaused->close();
            $releaseFirst->close();
        }

        $this->assertStringContainsString("Factory::parentPlaceholder('first')", $results['first']);
        $this->assertStringContainsString("Factory::parentPlaceholder('second')", $results['second']);
    }

    public function testStandaloneParentUsesTheEmptySectionPlaceholder(): void
    {
        $compiler = new BladeCompiler($this->getFiles(), __DIR__);
        $compiler->compileString("@section('prior')");

        $this->assertSame(
            "<?php echo \\Hypervel\\View\\Factory::parentPlaceholder(''); ?>",
            $compiler->compileString('@parent')
        );
    }

    public function testEchoFormatDefaultIsVisibleInsideSiblingCoroutines(): void
    {
        $compiler = new BladeCompiler($this->getFiles(), __DIR__);
        $compiler->setEchoFormat('boot(%s)');

        $results = parallel([
            'first' => fn (): string => $compiler->compileString('{{ $first }}'),
            'second' => fn (): string => $compiler->compileString('{{ $second }}'),
        ]);

        $this->assertSame('<?php echo boot($first); ?>', $results['first']);
        $this->assertSame('<?php echo boot($second); ?>', $results['second']);
    }

    public function testEchoFormatOverridesAreNestedAndRestored(): void
    {
        $compiler = new BladeCompiler($this->getFiles(), __DIR__);
        $compiler->setEchoFormat('boot(%s)');

        $result = $compiler->usingEchoFormat('outer(%s)', function () use ($compiler): string {
            $inner = $compiler->usingEchoFormat(
                'inner(%s)',
                fn (): string => $compiler->compileString('{{ $value }}')
            );

            return $inner . '|' . $compiler->compileString('{{ $value }}');
        });

        $this->assertSame(
            '<?php echo inner($value); ?>|<?php echo outer($value); ?>',
            $result
        );
        $this->assertSame('<?php echo boot($value); ?>', $compiler->compileString('{{ $value }}'));
    }

    public function testEchoFormatOverrideIsRestoredAfterFailure(): void
    {
        $compiler = new BladeCompiler($this->getFiles(), __DIR__);
        $compiler->setEchoFormat('boot(%s)');

        try {
            $compiler->usingEchoFormat('temporary(%s)', fn (): never => throw new RuntimeException('failed'));
        } catch (RuntimeException $exception) {
            $this->assertSame('failed', $exception->getMessage());
        }

        $this->assertSame('<?php echo boot($value); ?>', $compiler->compileString('{{ $value }}'));
    }

    public function testEchoFormatOverrideIsIsolatedBetweenConcurrentCompilations(): void
    {
        $compiler = new BladeCompiler($this->getFiles(), __DIR__);
        $compiler->setEchoFormat('boot(%s)');
        $firstPaused = new Channel(1);
        $releaseFirst = new Channel(1);

        try {
            $results = parallel([
                'first' => fn (): string => $compiler->usingEchoFormat(
                    'first(%s)',
                    function () use ($compiler, $firstPaused, $releaseFirst): string {
                        $firstPaused->push(true);
                        $releaseFirst->pop();

                        return $compiler->compileString('{{ $value }}');
                    }
                ),
                'second' => function () use ($compiler, $firstPaused, $releaseFirst): string {
                    $firstPaused->pop();
                    $compiled = $compiler->compileString('{{ $value }}');
                    $releaseFirst->push(true);

                    return $compiled;
                },
            ]);
        } finally {
            $firstPaused->close();
            $releaseFirst->close();
        }

        $this->assertSame('<?php echo first($value); ?>', $results['first']);
        $this->assertSame('<?php echo boot($value); ?>', $results['second']);
    }

    public function testFooterExtensionContract(): void
    {
        $compiler = new TestableBladeCompiler($this->getFiles(), __DIR__);

        $compiler->pushTestFooter('first');
        $compiler->pushTestFooter('second');

        $this->assertSame("body\nsecond\nfirst", $compiler->addTestFooters("\nbody"));
    }

    public function testEndDirectiveExtensionContracts(): void
    {
        $compiler = new TestableBladeCompiler($this->getFiles(), __DIR__);

        $this->assertSame(
            "session:('session') error:('error') context:('context')",
            $compiler->compileString("@endsession('session') @enderror('error') @endcontext('context')")
        );
    }

    public function testParenthesisGuardRejectsIncompleteExpressions(): void
    {
        $compiler = new TestableBladeCompiler($this->getFiles(), __DIR__);

        $this->assertFalse($compiler->hasBalancedParentheses('example('));
    }

    public function testRawTagsCanBeSetToLegacyValues(): void
    {
        $compiler = new BladeCompiler($this->getFiles(), __DIR__);
        $compiler->setEchoFormat('%s');

        $this->assertSame('<?php echo e($name); ?>', $compiler->compileString('{{{ $name }}}'));
        $this->assertSame('<?php echo $name; ?>', $compiler->compileString('{{ $name }}'));
        $this->assertSame('<?php echo $name; ?>', $compiler->compileString('{{
            $name
        }}'));
    }

    #[DataProvider('appendViewPathDataProvider')]
    public function testIncludePathToTemplate(string $content, string $compiled): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('get')->once()->with('foo')->andReturn($content);
        $files->shouldReceive('exists')->once()->with(__DIR__)->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php')->andReturn(false);
        $files->shouldReceive('replace')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3foo') . '.php', $compiled);

        $compiler->compile('foo');
    }

    public static function appendViewPathDataProvider(): array
    {
        return [
            'No PHP blocks' => [
                'Hello World',
                'Hello World<?php /**PATH foo ENDPATH**/ ?>',
            ],
            'Single PHP block without closing ?>' => [
                '<?php echo $path',
                '<?php echo $path ?><?php /**PATH foo ENDPATH**/ ?>',
            ],
            'Ending PHP block.' => [
                'Hello world<?php echo $path ?>',
                'Hello world<?php echo $path ?><?php /**PATH foo ENDPATH**/ ?>',
            ],
            'Ending PHP block without closing ?>' => [
                'Hello world<?php echo $path',
                'Hello world<?php echo $path ?><?php /**PATH foo ENDPATH**/ ?>',
            ],
            'PHP block between content.' => [
                'Hello world<?php echo $path ?>Hi There',
                'Hello world<?php echo $path ?>Hi There<?php /**PATH foo ENDPATH**/ ?>',
            ],
            'Multiple PHP blocks.' => [
                'Hello world<?php echo $path ?>Hi There<?php echo $path ?>Hello Again',
                'Hello world<?php echo $path ?>Hi There<?php echo $path ?>Hello Again<?php /**PATH foo ENDPATH**/ ?>',
            ],
            'Multiple PHP blocks without closing ?>' => [
                'Hello world<?php echo $path ?>Hi There<?php echo $path',
                'Hello world<?php echo $path ?>Hi There<?php echo $path ?><?php /**PATH foo ENDPATH**/ ?>',
            ],
            'Short open echo tag' => [
                'Hello world<?= echo $path',
                'Hello world<?= echo $path ?><?php /**PATH foo ENDPATH**/ ?>',
            ],
            'Echo XML declaration' => [
                '<?php echo \'<?xml version="1.0" encoding="UTF-8"?>\';',
                '<?php echo \'<?xml version="1.0" encoding="UTF-8"?>\'; ?><?php /**PATH foo ENDPATH**/ ?>',
            ],
        ];
    }

    public function testDontIncludeEmptyPath(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('get')->once()->with('')->andReturn('Hello World');
        $files->shouldReceive('exists')->once()->with(__DIR__)->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3') . '.php')->andReturn(false);
        $files->shouldReceive('replace')->once()->with(__DIR__ . '/' . hash('xxh128', 'v3') . '.php', 'Hello World');
        $compiler->setPath('');
        $compiler->compile();
    }

    public function testIncludesZeroPath(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('get')->once()->with('0')->andReturn('Hello World');
        $files->shouldReceive('exists')->once()->with(__DIR__)->andReturn(true);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . hash('xxh128', 'v30') . '.php')->andReturn(false);
        $files->shouldReceive('replace')->once()->with(__DIR__ . '/' . hash('xxh128', 'v30') . '.php', 'Hello World<?php /**PATH 0 ENDPATH**/ ?>');
        $compiler->compile('0');
        $this->assertSame('0', $compiler->getPath());
    }

    public function testShouldStartFromStrictTypesDeclaration(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $strictTypeDecl = "<?php\ndeclare(strict_types = 1);";
        $this->assertSame(substr(
            $compiler->compileString("<?php\ndeclare(strict_types = 1);\nHello World"),
            0,
            strlen($strictTypeDecl)
        ), $strictTypeDecl);
    }

    public function testComponentAliasesCanBeConventionallyDetermined(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);

        $compiler->component('App\Foo\Bar');
        $this->assertEquals(['bar' => 'App\Foo\Bar'], $compiler->getClassComponentAliases());

        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);

        $compiler->component('App\Foo\Bar', null, 'prefix');
        $this->assertEquals(['prefix-bar' => 'App\Foo\Bar'], $compiler->getClassComponentAliases());

        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);

        $compiler->component('App\View\Components\Forms\Input');
        $this->assertEquals(['forms:input' => 'App\View\Components\Forms\Input'], $compiler->getClassComponentAliases());

        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);

        $compiler->component('App\View\Components\Forms\Input', null, 'prefix');
        $this->assertEquals(['prefix-forms:input' => 'App\View\Components\Forms\Input'], $compiler->getClassComponentAliases());
    }

    public function testComponentAliasesUseAliasFirstOrder(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);

        $compiler->component('package-alert', 'App\View\Components\Alert');
        $this->assertEquals(['package-alert' => 'App\View\Components\Alert'], $compiler->getClassComponentAliases());

        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);

        $compiler->component('App\View\Components\Alert', 'package-alert');
        $this->assertArrayNotHasKey('package-alert', $compiler->getClassComponentAliases());
        $this->assertEquals(['App\View\Components\Alert' => 'package-alert'], $compiler->getClassComponentAliases());
    }

    public function testAnonymousComponentNamespacesCanBeStored(): void
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);

        $compiler->anonymousComponentNamespace(' public/frontend ', 'frontend');
        $this->assertEquals(['frontend' => 'public.frontend'], $compiler->getAnonymousComponentNamespaces());

        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);

        $compiler->anonymousComponentNamespace('public/frontend/', 'frontend');
        $this->assertEquals(['frontend' => 'public.frontend'], $compiler->getAnonymousComponentNamespaces());

        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);

        $compiler->anonymousComponentNamespace('/admin/components', 'admin');
        $this->assertEquals(['admin' => 'admin.components'], $compiler->getAnonymousComponentNamespaces());

        // Test directory is automatically inferred from the prefix if not given.
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);

        $compiler->anonymousComponentNamespace('frontend');
        $this->assertEquals(['frontend' => 'frontend'], $compiler->getAnonymousComponentNamespaces());

        // Test that the prefix can also contain dots.
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);

        $compiler->anonymousComponentNamespace('frontend/auth', 'frontend.auth');
        $this->assertEquals(['frontend.auth' => 'frontend.auth'], $compiler->getAnonymousComponentNamespaces());
    }

    protected function getFiles(): Filesystem
    {
        return m::mock(Filesystem::class);
    }
}

class TestableBladeCompiler extends BladeCompiler
{
    public function addTestFooters(string $result): string
    {
        return $this->addFooters($result);
    }

    public function pushTestFooter(string $footer): void
    {
        $this->pushFooter($footer);
    }

    public function hasBalancedParentheses(string $expression): bool
    {
        return $this->hasEvenNumberOfParentheses($expression);
    }

    protected function compileEndsession(?string $expression): string
    {
        return 'session:' . $expression;
    }

    protected function compileEnderror(?string $expression): string
    {
        return 'error:' . $expression;
    }

    protected function compileEndcontext(?string $expression): string
    {
        return 'context:' . $expression;
    }
}
