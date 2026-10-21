<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use ErrorException;
use Exception;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Hypervel\View\Compilers\CompilerInterface;
use Hypervel\View\Engines\CompilerEngine;
use Hypervel\View\ViewException;
use Mockery as m;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ViewCompilerEngineTest extends TestCase
{
    public function testViewsMayBeRecompiledAndRendered(): void
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->expects('getCompiledPath')->with(__DIR__ . '/Fixtures/foo.php')->andReturn(__DIR__ . '/Fixtures/basic.php');
        $engine->getCompiler()->expects('isExpired')->with(__DIR__ . '/Fixtures/foo.php')->andReturn(true);
        $engine->getCompiler()->expects('compile')->with(__DIR__ . '/Fixtures/foo.php');
        $results = $engine->get(__DIR__ . '/Fixtures/foo.php');

        $this->assertSame('Hello World
', $results);
    }

    public function testViewsAreNotRecompiledIfTheyAreNotExpired(): void
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->expects('getCompiledPath')->with(__DIR__ . '/Fixtures/foo.php')->andReturn(__DIR__ . '/Fixtures/basic.php');
        $engine->getCompiler()->expects('isExpired')->andReturn(false);
        $engine->getCompiler()->shouldReceive('compile')->never();
        $results = $engine->get(__DIR__ . '/Fixtures/foo.php');

        $this->assertSame('Hello World
', $results);
    }

    public function testRegularExceptionsAreReThrownAsViewExceptions(): void
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->expects('getCompiledPath')->with(__DIR__ . '/Fixtures/foo.php')->andReturn(__DIR__ . '/Fixtures/regular-exception.php');
        $engine->getCompiler()->expects('isExpired')->andReturn(false);

        $this->expectExceptionObject(new ViewException('regular exception message'));

        $engine->get(__DIR__ . '/Fixtures/foo.php');
    }

    public function testCancellationIsNotWrappedAndCompiledPathIsPopped(): void
    {
        $path = __DIR__ . '/Fixtures/foo.php';
        $compiled = __DIR__ . '/Fixtures/basic.php';
        $cancellation = new CanceledException;
        $files = m::mock(Filesystem::class);
        $files->expects('getRequire')->with($compiled, [])->andThrow($cancellation);

        $engine = $this->getEngine($files);
        $engine->getCompiler()->expects('isExpired')->with($path)->andReturn(false);
        $engine->getCompiler()->expects('getCompiledPath')->with($path)->andReturn($compiled);

        try {
            $engine->get($path);
            $this->fail('Expected cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([], CoroutineContext::get(CompilerEngine::COMPILED_PATH_CONTEXT_KEY, []));
    }

    public function testHttpExceptionsAreNotReThrownAsViewExceptions(): void
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->expects('getCompiledPath')->with(__DIR__ . '/Fixtures/foo.php')->andReturn(__DIR__ . '/Fixtures/http-exception.php');
        $engine->getCompiler()->expects('isExpired')->andReturn(false);

        $this->expectExceptionObject(new HttpException(403, 'http exception message'));

        $engine->get(__DIR__ . '/Fixtures/foo.php');
    }

    public function testThatViewsAreNotAskTwiceIfTheyAreExpired(): void
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->expects('getCompiledPath')->times(5)->with(__DIR__ . '/Fixtures/foo.php')->andReturn(__DIR__ . '/Fixtures/basic.php');
        $engine->getCompiler()->expects('isExpired')->times(3)->andReturn(false);
        $engine->getCompiler()->shouldReceive('compile')->never();

        $engine->get(__DIR__ . '/Fixtures/foo.php');
        $engine->get(__DIR__ . '/Fixtures/foo.php');
        $engine->get(__DIR__ . '/Fixtures/foo.php');

        CompilerEngine::forgetCompiledOrNotExpired();

        $engine->get(__DIR__ . '/Fixtures/foo.php');

        CompilerEngine::flushState();

        $engine->get(__DIR__ . '/Fixtures/foo.php');
    }

    public function testViewsAreRecompiledWhenCompiledViewIsMissingViaFileNotFoundException(): void
    {
        $compiled = __DIR__ . '/Fixtures/basic.php';
        $path = __DIR__ . '/Fixtures/foo.php';

        $files = m::mock(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->expects('getRequire')
            ->with($compiled, [])
            ->andReturn('compiled-content');

        $files->expects('getRequire')
            ->with($compiled, [])
            ->andThrow(new FileNotFoundException(
                "File does not exist at path {$path}."
            ));

        $files->expects('getRequire')
            ->with($compiled, [])
            ->andReturn('compiled-content');

        $engine->getCompiler()
            ->expects('getCompiledPath')
            ->times(3)
            ->with($path)
            ->andReturn($compiled);

        $engine->getCompiler()
            ->expects('isExpired')
            ->andReturn(false);

        $engine->getCompiler()
            ->expects('compile')
            ->with($path);

        $engine->get($path);
        $engine->get($path);
    }

    public function testViewsAreRecompiledWhenCompiledViewIsMissingViaRequireException(): void
    {
        $compiled = __DIR__ . '/Fixtures/basic.php';
        $path = __DIR__ . '/Fixtures/foo.php';

        $files = m::mock(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->expects('getRequire')
            ->with($compiled, [])
            ->andReturn('compiled-content');

        $files->expects('getRequire')
            ->with($compiled, [])
            ->andThrow(new ErrorException(
                "require({$path}): Failed to open stream: No such file or directory",
            ));

        $files->expects('getRequire')
            ->with($compiled, [])
            ->andReturn('compiled-content');

        $engine->getCompiler()
            ->expects('getCompiledPath')
            ->times(3)
            ->with($path)
            ->andReturn($compiled);

        $engine->getCompiler()
            ->expects('isExpired')
            ->andReturn(false);

        $engine->getCompiler()
            ->expects('compile')
            ->with($path);

        $engine->get($path);
        $engine->get($path);
    }

    public function testViewsAreRecompiledJustOnceWhenCompiledViewIsMissing(): void
    {
        $compiled = __DIR__ . '/Fixtures/basic.php';
        $path = __DIR__ . '/Fixtures/foo.php';

        $files = m::mock(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->expects('getRequire')
            ->with($compiled, [])
            ->andReturn('compiled-content');

        $files->expects('getRequire')
            ->with($compiled, [])
            ->andThrow(new FileNotFoundException(
                "File does not exist at path {$path}."
            ));

        $files->expects('getRequire')
            ->with($compiled, [])
            ->andThrow(new FileNotFoundException(
                "File does not exist at path {$path}."
            ));

        $engine->getCompiler()
            ->expects('getCompiledPath')
            ->times(3)
            ->with($path)
            ->andReturn($compiled);

        $engine->getCompiler()
            ->expects('isExpired')
            ->andReturn(false);

        $engine->getCompiler()
            ->expects('compile')
            ->with($path);

        $engine->get($path);

        $this->expectExceptionObject(new ViewException("File does not exist at path {$path}."));
        $engine->get($path);
    }

    public function testViewsAreNotRecompiledOnRegularViewException(): void
    {
        $compiled = __DIR__ . '/Fixtures/basic.php';
        $path = __DIR__ . '/Fixtures/foo.php';

        $files = m::mock(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->expects('getRequire')
            ->with($compiled, [])
            ->andThrow(new Exception(
                'Just an regular error...'
            ));

        $engine->getCompiler()
            ->expects('isExpired')
            ->andReturn(false);

        $engine->getCompiler()
            ->shouldReceive('compile')
            ->never();

        $engine->getCompiler()
            ->expects('getCompiledPath')
            ->with($path)
            ->andReturn($compiled);

        $this->expectExceptionObject(new ViewException('Just an regular error...'));
        $engine->get($path);
    }

    public function testViewsAreNotRecompiledIfTheyWereJustCompiled(): void
    {
        $compiled = __DIR__ . '/Fixtures/basic.php';
        $path = __DIR__ . '/Fixtures/foo.php';

        $files = m::mock(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->expects('getRequire')
            ->with($compiled, [])
            ->andThrow(new FileNotFoundException(
                "File does not exist at path {$path}."
            ));

        $engine->getCompiler()
            ->expects('isExpired')
            ->andReturn(true);

        $engine->getCompiler()
            ->expects('compile')
            ->with($path);

        $engine->getCompiler()
            ->expects('getCompiledPath')
            ->with($path)
            ->andReturn($compiled);

        $this->expectExceptionObject(new ViewException("File does not exist at path {$path}."));
        $engine->get($path);
    }

    public function testExpiredViewsAreCheckedAndCompiledOnEveryRender(): void
    {
        $path = __DIR__ . '/Fixtures/foo.php';
        $compiled = __DIR__ . '/Fixtures/basic.php';
        $engine = $this->getEngine();

        $engine->getCompiler()->expects('isExpired')->twice()->with($path)->andReturn(true);
        $engine->getCompiler()->expects('compile')->twice()->with($path);
        $engine->getCompiler()->expects('getCompiledPath')->twice()->with($path)->andReturn($compiled);

        $engine->get($path);
        $engine->get($path);
    }

    public function testCompiledPathIsPoppedAfterSuccessfulRender(): void
    {
        $path = __DIR__ . '/Fixtures/foo.php';
        $engine = $this->getEngine();

        $engine->getCompiler()->expects('isExpired')->with($path)->andReturn(false);
        $engine->getCompiler()->expects('getCompiledPath')->with($path)->andReturn(__DIR__ . '/Fixtures/basic.php');

        $engine->get($path);

        $this->assertSame([], CoroutineContext::get(CompilerEngine::COMPILED_PATH_CONTEXT_KEY, []));
    }

    public function testCompiledPathIsPoppedAfterCaughtRenderFailure(): void
    {
        $path = __DIR__ . '/Fixtures/foo.php';
        $engine = $this->getEngine();

        $engine->getCompiler()->expects('isExpired')->with($path)->andReturn(false);
        $engine->getCompiler()->expects('getCompiledPath')->with($path)->andReturn(__DIR__ . '/Fixtures/regular-exception.php');

        try {
            $engine->get($path);
            $this->fail('The view should have failed to render.');
        } catch (ViewException $exception) {
            $this->assertStringContainsString('regular exception message', $exception->getMessage());
        }

        $this->assertSame([], CoroutineContext::get(CompilerEngine::COMPILED_PATH_CONTEXT_KEY, []));
    }

    /**
     * Create a compiler engine with a mocked compiler.
     */
    protected function getEngine(?Filesystem $filesystem = null): CompilerEngine
    {
        return new CompilerEngine(m::mock(CompilerInterface::class), $filesystem ?: new Filesystem);
    }
}
