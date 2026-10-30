<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Exceptions;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Exceptions\Renderer\Mappers\BladeMapper;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\Engines\CompilerEngine;
use Hypervel\View\Engines\EngineResolver;
use Hypervel\View\ViewException;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionMethod;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Throwable;

use function Hypervel\Testbench\after_resolving;
use function Hypervel\Testbench\package_path;

#[WithConfig('app.debug', true)]
class RenderBladeFilesTest extends TestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('view.compiled_extension', 'cache');

        after_resolving($app, 'view.engine.resolver', function (EngineResolver $resolver): void {
            $resolver->resolve('blade')->getCompiler()->withoutComponentTags();
        });
    }

    public function testFormattedSourceTooltipRendersMultilineSafely(): void
    {
        $frame = new class {
            /**
             * Get the frame's class.
             */
            public function class(): null
            {
                return null;
            }

            /**
             * Get the frame's call operator.
             */
            public function operator(): string
            {
                return '';
            }

            /**
             * Get the frame's callable.
             */
            public function callable(): string
            {
                return 'throw';
            }

            /**
             * Get the frame's source code.
             */
            public function source(): string
            {
                return "Foo::bar(1)\nAnother line";
            }
        };

        $path = package_path('src/foundation/resources/exceptions/renderer/components/formatted-source.blade.php');

        $html = (string) $this->app->make('view')->file($path, ['frame' => $frame])->render();

        $this->assertStringContainsString('data-tippy-content="', $html);
        $this->assertStringNotContainsString('<br', $html);
    }

    public function testQueryTooltipRendersMultilineSafely(): void
    {
        $sql = "SELECT * FROM tests\nWHERE id = 1";
        $queries = [['connectionName' => 'mysql', 'sql' => $sql, 'time' => 1.23]];

        $path = package_path('src/foundation/resources/exceptions/renderer/components/query.blade.php');

        $html = (string) $this->app->make('view')->file($path, ['queries' => $queries])->render();

        $this->assertStringContainsString('data-tippy-content="' . $sql . '"', $html);
        $this->assertStringNotContainsString('&lt;br', $html);
    }

    public function testRequestHeaderTooltipRendersMultilineSafely(): void
    {
        $headers = ['X-Test' => "A\nB<script>bad()</script>"];

        $path = package_path('src/foundation/resources/exceptions/renderer/components/request-header.blade.php');

        $html = (string) $this->app->make('view')->file($path, ['headers' => $headers])->render();

        $this->assertStringContainsString('data-tippy-content="', $html);
        $this->assertStringNotContainsString('<br', $html);
        $this->assertStringContainsString('&lt;script&gt;bad()&lt;/script&gt;', $html);
    }

    public function testRoutingTooltipRendersMultilineSafely(): void
    {
        $routing = ['URI' => "users/1\nedit"];

        $path = package_path('src/foundation/resources/exceptions/renderer/components/routing.blade.php');

        $html = (string) $this->app->make('view')->file($path, ['routing' => $routing])->render();

        $this->assertStringContainsString('data-tippy-content="', $html);
        $this->assertStringNotContainsString('<br', $html);
    }

    public function testBladeMapperSkipsMissingOriginalAndCompiledPaths(): void
    {
        $compiler = $this->app->make('blade.compiler');
        $path = ParallelTesting::tempDir('MissingBladeSource') . '/missing.blade.php';
        $compiled = $compiler->getCompiledPath($path);
        $files = new Filesystem;
        $files->delete($compiled);
        $mapper = $this->app->make(BladeMapper::class);

        $this->assertNull($mapper->findCompiledView($compiled));

        try {
            $files->ensureDirectoryExists(dirname($compiled));
            $files->put($compiled, '/**PATH ' . $path . ' ENDPATH**/');
            $this->assertNull($mapper->findCompiledView($compiled));
        } finally {
            $files->delete($compiled);
        }
    }

    #[TestWith([false, false], 'default echo format')]
    #[TestWith([true, false], 'custom echo format')]
    #[TestWith([true, true], 'custom echo format with symlinked cache')]
    public function testBladeMapperFindsTheSourceAfterRenderingHasUnwound(bool $customFormat, bool $symlinkedCache): void
    {
        $files = new Filesystem;
        $directory = ParallelTesting::tempDir('RenderBladeFilesTest');
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $path = $directory . '/failing.blade.php';
        $files->put($path, "First line\n{{ missing_blade_mapping_test_function() }}");

        try {
            if ($symlinkedCache) {
                $files->ensureDirectoryExists($directory . '/compiled');
                symlink($directory . '/compiled', $directory . '/linked');
                config(['view.compiled' => $directory . '/linked']);
                $this->app->forgetInstance('blade.compiler');
                $this->app->make('view.engine.resolver')->forget('blade');
            }

            $compiler = $this->app->make('blade.compiler');
            $render = fn (): string => $this->app->make('view')->file($path)->render();

            try {
                $customFormat
                    ? $compiler->usingEchoFormat('strtoupper(e(%s))', $render)
                    : $render();
                $this->fail('The view did not fail.');
            } catch (ViewException $exception) {
                $this->assertSame([], CoroutineContext::get(CompilerEngine::COMPILED_PATH_CONTEXT_KEY));
                $mapped = $this->app->make(BladeMapper::class)->map(FlattenException::createFromThrowable($exception));
                $frames = array_values(array_filter($mapped->getTrace(), static fn (array $frame): bool => ($frame['file'] ?? null) === $path));
                $this->assertNotEmpty($frames);
                $this->assertSame(2, $frames[0]['line']);
            }
        } finally {
            $files->deleteDirectory($directory);
        }
    }

    public function testBladeMapperPreservesCompiledLineWhenOriginalDisappears(): void
    {
        $this->assertSame(
            37,
            $this->app->make(BladeMapper::class)->detectLineNumber(
                ParallelTesting::tempDir('MissingBladeSource') . '/missing.blade.php',
                37,
            ),
        );
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testBladeMapperPreservesCancellationDuringSourceMapping(bool $throwingCleanup): void
    {
        $cancellation = new CanceledException('canceled');
        $cancellation = $throwingCleanup ? new RuntimeException('cleanup failed', 0, $cancellation) : $cancellation;
        $compiler = m::mock(BladeCompiler::class);
        $compiler->shouldReceive('compileString')->once()->andThrow($cancellation);
        $method = new ReflectionMethod(BladeMapper::class, 'compileSourcemap');

        try {
            $method->invoke(new BladeMapper($compiler), 'plain text');

            $this->fail('The cancellation was not preserved.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }
}
