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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ViewCompilerEngineTest extends TestCase
{
    use VerifiesDoubles;

    public function testViewsMayBeRecompiledAndRendered(): void
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->expects('getCompiledPath')->with(__DIR__ . '/Fixtures/foo.php')->returns(__DIR__ . '/Fixtures/basic.php');
        $engine->getCompiler()->expects('isExpired')->with(__DIR__ . '/Fixtures/foo.php')->returns(true);
        $engine->getCompiler()->expects('compile')->with(__DIR__ . '/Fixtures/foo.php');
        $results = $engine->get(__DIR__ . '/Fixtures/foo.php');

        $this->assertSame('Hello World
', $results);
    }

    public function testViewsAreNotRecompiledIfTheyAreNotExpired(): void
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->expects('getCompiledPath')->with(__DIR__ . '/Fixtures/foo.php')->returns(__DIR__ . '/Fixtures/basic.php');
        $engine->getCompiler()->expects('isExpired')->returns(false);
        $engine->getCompiler()->expects('compile')->never();
        $results = $engine->get(__DIR__ . '/Fixtures/foo.php');

        $this->assertSame('Hello World
', $results);
    }

    public function testRegularExceptionsAreReThrownAsViewExceptions(): void
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->expects('getCompiledPath')->with(__DIR__ . '/Fixtures/foo.php')->returns(__DIR__ . '/Fixtures/regular-exception.php');
        $engine->getCompiler()->expects('isExpired')->returns(false);

        $this->expectExceptionObject(new ViewException('regular exception message'));

        $engine->get(__DIR__ . '/Fixtures/foo.php');
    }

    public function testCancellationIsNotWrappedAndCompiledPathIsPopped(): void
    {
        $path = __DIR__ . '/Fixtures/foo.php';
        $compiled = __DIR__ . '/Fixtures/basic.php';
        $cancellation = new CanceledException;
        $files = Double::for(Filesystem::class);
        $files->expects('getRequire')->with($compiled, [])->throws($cancellation);

        $engine = $this->getEngine($files);
        $engine->getCompiler()->expects('isExpired')->with($path)->returns(false);
        $engine->getCompiler()->expects('getCompiledPath')->with($path)->returns($compiled);

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
        $engine->getCompiler()->expects('getCompiledPath')->with(__DIR__ . '/Fixtures/foo.php')->returns(__DIR__ . '/Fixtures/http-exception.php');
        $engine->getCompiler()->expects('isExpired')->returns(false);

        $this->expectExceptionObject(new HttpException(403, 'http exception message'));

        $engine->get(__DIR__ . '/Fixtures/foo.php');
    }

    public function testThatViewsAreNotAskTwiceIfTheyAreExpired(): void
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->expects('getCompiledPath')->times(5)->with(__DIR__ . '/Fixtures/foo.php')->returns(__DIR__ . '/Fixtures/basic.php');
        $engine->getCompiler()->expects('isExpired')->times(3)->returns(false);
        $engine->getCompiler()->expects('compile')->never();

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

        $files = Double::for(Filesystem::class);
        $engine = $this->getEngine($files);

        $calls = 0;
        $files->expects('getRequire')->with($compiled, [])->times(3)->resolves(function () use (&$calls, $path): string {
            if (++$calls === 2) {
                throw new FileNotFoundException(
                    "File does not exist at path {$path}."
                );
            }

            return 'compiled-content';
        });

        $engine->getCompiler()
            ->expects('getCompiledPath')
            ->times(2)
            ->with($path)
            ->returns($compiled);

        $engine->getCompiler()
            ->expects('isExpired')
            ->returns(false);

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

        $files = Double::for(Filesystem::class);
        $engine = $this->getEngine($files);

        $calls = 0;
        $files->expects('getRequire')->with($compiled, [])->times(3)->resolves(function () use (&$calls, $path): string {
            if (++$calls === 2) {
                throw new ErrorException(
                    "require({$path}): Failed to open stream: No such file or directory",
                );
            }

            return 'compiled-content';
        });

        $engine->getCompiler()
            ->expects('getCompiledPath')
            ->times(2)
            ->with($path)
            ->returns($compiled);

        $engine->getCompiler()
            ->expects('isExpired')
            ->returns(false);

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

        $files = Double::for(Filesystem::class);
        $engine = $this->getEngine($files);

        $requires = 0;
        $files->expects('getRequire')->times(3)->with($compiled, [])->resolves(function () use (&$requires, $path): string {
            if ($requires++ === 0) {
                return 'compiled-content';
            }

            throw new FileNotFoundException("File does not exist at path {$path}.");
        });

        $engine->getCompiler()
            ->expects('getCompiledPath')
            ->times(2)
            ->with($path)
            ->returns($compiled);

        $engine->getCompiler()
            ->expects('isExpired')
            ->returns(false);

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

        $files = Double::for(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->expects('getRequire')
            ->with($compiled, [])
            ->throws(new Exception(
                'Just an regular error...'
            ));

        $engine->getCompiler()
            ->expects('isExpired')
            ->returns(false);

        $engine->getCompiler()
            ->expects('compile')
            ->never();

        $engine->getCompiler()
            ->expects('getCompiledPath')
            ->with($path)
            ->returns($compiled);

        $this->expectExceptionObject(new ViewException('Just an regular error...'));
        $engine->get($path);
    }

    public function testViewsAreNotRecompiledIfTheyWereJustCompiled(): void
    {
        $compiled = __DIR__ . '/Fixtures/basic.php';
        $path = __DIR__ . '/Fixtures/foo.php';

        $files = Double::for(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->expects('getRequire')
            ->with($compiled, [])
            ->throws(new FileNotFoundException(
                "File does not exist at path {$path}."
            ));

        $engine->getCompiler()
            ->expects('isExpired')
            ->returns(true);

        $engine->getCompiler()
            ->expects('compile')
            ->with($path);

        $engine->getCompiler()
            ->expects('getCompiledPath')
            ->with($path)
            ->returns($compiled);

        $this->expectExceptionObject(new ViewException("File does not exist at path {$path}."));
        $engine->get($path);
    }

    public function testExpiredViewsAreCheckedAndCompiledOnEveryRender(): void
    {
        $path = __DIR__ . '/Fixtures/foo.php';
        $compiled = __DIR__ . '/Fixtures/basic.php';
        $engine = $this->getEngine();

        $engine->getCompiler()->expects('isExpired')->times(2)->with($path)->returns(true);
        $engine->getCompiler()->expects('compile')->times(2)->with($path);
        $engine->getCompiler()->expects('getCompiledPath')->times(2)->with($path)->returns($compiled);

        $engine->get($path);
        $engine->get($path);
    }

    public function testEachCompiledVariantHasItsOwnFreshnessCheck(): void
    {
        $path = '/views/example.blade.php';
        $files = Double::for(Filesystem::class);
        $engine = $this->getEngine($files);

        $engine->getCompiler()->expects('getCompiledPath')->times(2)->with($path)
            ->returns('/compiled/default.php', '/compiled/custom.php');
        $engine->getCompiler()->expects('isExpired')->times(2)->with($path)->returns(false, true);
        $engine->getCompiler()->expects('compile')->with($path);
        $files->expects('getRequire')->with('/compiled/default.php', [])->resolves(static function (): void {
            echo 'default';
        });
        $files->expects('getRequire')->with('/compiled/custom.php', [])->resolves(static function (): void {
            echo 'custom';
        });

        $this->assertSame('default', $engine->get($path));
        $this->assertSame('custom', $engine->get($path));
    }

    public function testCompiledPathIsPoppedAfterSuccessfulRender(): void
    {
        $path = __DIR__ . '/Fixtures/foo.php';
        $engine = $this->getEngine();

        $engine->getCompiler()->expects('isExpired')->with($path)->returns(false);
        $engine->getCompiler()->expects('getCompiledPath')->with($path)->returns(__DIR__ . '/Fixtures/basic.php');

        $engine->get($path);

        $this->assertSame([], CoroutineContext::get(CompilerEngine::COMPILED_PATH_CONTEXT_KEY, []));
    }

    public function testCompiledPathIsPoppedAfterCaughtRenderFailure(): void
    {
        $path = __DIR__ . '/Fixtures/foo.php';
        $engine = $this->getEngine();

        $engine->getCompiler()->expects('isExpired')->with($path)->returns(false);
        $engine->getCompiler()->expects('getCompiledPath')->with($path)->returns(__DIR__ . '/Fixtures/regular-exception.php');

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
        return new CompilerEngine(Double::for(CompilerInterface::class), $filesystem ?: new Filesystem);
    }
}
