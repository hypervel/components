<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use ErrorException;
use Exception;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Exceptions\FileNotFoundException;
use Hypervel\HttpMessage\Exceptions\HttpException;
use Hypervel\View\Compilers\CompilerInterface;
use Hypervel\View\Engines\CompilerEngine;
use Hypervel\View\ViewException;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ViewCompilerEngineTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testViewsMayBeRecompiledAndRendered()
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->shouldReceive('getCompiledPath')->with(__DIR__ . '/fixtures/foo.php')->andReturn(__DIR__ . '/fixtures/basic.php');
        $engine->getCompiler()->shouldReceive('isExpired')->once()->with(__DIR__ . '/fixtures/foo.php')->andReturn(true);
        $engine->getCompiler()->shouldReceive('compile')->once()->with(__DIR__ . '/fixtures/foo.php');
        $results = $engine->get(__DIR__ . '/fixtures/foo.php');

        $this->assertSame('Hello World
', $results);
    }

    public function testViewsAreNotRecompiledIfTheyAreNotExpired()
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->shouldReceive('getCompiledPath')->with(__DIR__ . '/fixtures/foo.php')->andReturn(__DIR__ . '/fixtures/basic.php');
        $engine->getCompiler()->shouldReceive('isExpired')->once()->andReturn(false);
        $engine->getCompiler()->shouldReceive('compile')->never();
        $results = $engine->get(__DIR__ . '/fixtures/foo.php');

        $this->assertSame('Hello World
', $results);
    }

    public function testRegularExceptionsAreReThrownAsViewExceptions()
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->shouldReceive('getCompiledPath')->with(__DIR__ . '/fixtures/foo.php')->andReturn(__DIR__ . '/fixtures/regular-exception.php');
        $engine->getCompiler()->shouldReceive('isExpired')->once()->andReturn(false);

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('regular exception message');

        $engine->get(__DIR__ . '/fixtures/foo.php');
    }

    public function testHttpExceptionsAreNotReThrownAsViewExceptions()
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->shouldReceive('getCompiledPath')->with(__DIR__ . '/fixtures/foo.php')->andReturn(__DIR__ . '/fixtures/http-exception.php');
        $engine->getCompiler()->shouldReceive('isExpired')->once()->andReturn(false);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('http exception message');

        $engine->get(__DIR__ . '/fixtures/foo.php');
    }

    public function testThatViewsAreNotAskTwiceIfTheyAreExpired()
    {
        $engine = $this->getEngine();
        $engine->getCompiler()->shouldReceive('getCompiledPath')->with(__DIR__ . '/fixtures/foo.php')->andReturn(__DIR__ . '/fixtures/basic.php');
        $engine->getCompiler()->shouldReceive('isExpired')->twice()->andReturn(false);
        $engine->getCompiler()->shouldReceive('compile')->never();

        $engine->get(__DIR__ . '/fixtures/foo.php');
        $engine->get(__DIR__ . '/fixtures/foo.php');
    }

    public function testViewsAreRecompiledWhenCompiledViewIsMissingViaFileNotFoundException()
    {
        $compiled = __DIR__ . '/fixtures/basic.php';
        $path = __DIR__ . '/fixtures/foo.php';

        $files = m::mock(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->shouldReceive('getRequire')
            ->once()
            ->with($compiled, [])
            ->andReturn('compiled-content');

        $files->shouldReceive('getRequire')
            ->once()
            ->with($compiled, [])
            ->andThrow(new FileNotFoundException(
                "File does not exist at path {$path}."
            ));

        $files->shouldReceive('getRequire')
            ->once()
            ->with($compiled, [])
            ->andReturn('compiled-content');

        $engine->getCompiler()
            ->shouldReceive('getCompiledPath')
            ->times(3)
            ->with($path)
            ->andReturn($compiled);

        $engine->getCompiler()
            ->shouldReceive('isExpired')
            ->twice()
            ->andReturn(true);

        $engine->getCompiler()
            ->shouldReceive('compile')
            ->times(3)
            ->with($path);

        $engine->get($path);
        $engine->get($path);
    }

    public function testViewsAreRecompiledWhenCompiledViewIsMissingViaRequireException()
    {
        $compiled = __DIR__ . '/fixtures/basic.php';
        $path = __DIR__ . '/fixtures/foo.php';

        $files = m::mock(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->shouldReceive('getRequire')
            ->once()
            ->with($compiled, [])
            ->andReturn('compiled-content');

        $files->shouldReceive('getRequire')
            ->once()
            ->with($compiled, [])
            ->andThrow(new ErrorException(
                "require({$path}): Failed to open stream: No such file or directory",
            ));

        $files->shouldReceive('getRequire')
            ->once()
            ->with($compiled, [])
            ->andReturn('compiled-content');

        $engine->getCompiler()
            ->shouldReceive('getCompiledPath')
            ->times(3)
            ->with($path)
            ->andReturn($compiled);

        $engine->getCompiler()
            ->shouldReceive('isExpired')
            ->twice()
            ->andReturn(true);

        $engine->getCompiler()
            ->shouldReceive('compile')
            ->times(3)
            ->with($path);

        $engine->get($path);
        $engine->get($path);
    }

    public function testViewsAreRecompiledJustOnceWhenCompiledViewIsMissing()
    {
        $compiled = __DIR__ . '/fixtures/basic.php';
        $path = __DIR__ . '/fixtures/foo.php';

        $files = m::mock(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->shouldReceive('getRequire')
            ->once()
            ->with($compiled, [])
            ->andReturn('compiled-content');

        $files->shouldReceive('getRequire')
            ->once()
            ->with($compiled, [])
            ->andThrow(new FileNotFoundException(
                "File does not exist at path {$path}."
            ));

        $files->shouldReceive('getRequire')
            ->once()
            ->with($compiled, [])
            ->andThrow(new FileNotFoundException(
                "File does not exist at path {$path}."
            ));

        $engine->getCompiler()
            ->shouldReceive('getCompiledPath')
            ->times(3)
            ->with($path)
            ->andReturn($compiled);

        $engine->getCompiler()
            ->shouldReceive('isExpired')
            ->twice()
            ->andReturn(true);

        $engine->getCompiler()
            ->shouldReceive('compile')
            ->times(3)
            ->with($path);

        $engine->get($path);

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage("File does not exist at path {$path}.");
        $engine->get($path);
    }

    public function testViewsAreNotRecompiledOnRegularViewException()
    {
        $compiled = __DIR__ . '/fixtures/basic.php';
        $path = __DIR__ . '/fixtures/foo.php';

        $files = m::mock(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->shouldReceive('getRequire')
            ->once()
            ->with($compiled, [])
            ->andThrow(new Exception(
                'Just an regular error...'
            ));

        $engine->getCompiler()
            ->shouldReceive('isExpired')
            ->once()
            ->andReturn(false);

        $engine->getCompiler()
            ->shouldReceive('compile')
            ->never();

        $engine->getCompiler()
            ->shouldReceive('getCompiledPath')
            ->once()
            ->with($path)
            ->andReturn($compiled);

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('Just an regular error...');
        $engine->get($path);
    }

    public function testViewsAreNotRecompiledIfTheyWereJustCompiled()
    {
        $compiled = __DIR__ . '/fixtures/basic.php';
        $path = __DIR__ . '/fixtures/foo.php';

        $files = m::mock(Filesystem::class);
        $engine = $this->getEngine($files);

        $files->shouldReceive('getRequire')
            ->twice()
            ->with($compiled, [])
            ->andThrow(new FileNotFoundException(
                "File does not exist at path {$path}."
            ));

        $engine->getCompiler()
            ->shouldReceive('isExpired')
            ->once()
            ->andReturn(true);

        $engine->getCompiler()
            ->shouldReceive('compile')
            ->twice()
            ->with($path);

        $engine->getCompiler()
            ->shouldReceive('getCompiledPath')
            ->twice()
            ->with($path)
            ->andReturn($compiled);

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage("File does not exist at path {$path}.");
        $engine->get($path);
    }

    protected function getEngine($filesystem = null)
    {
        return new CompilerEngine(m::mock(CompilerInterface::class), $filesystem ?: new Filesystem());
    }
}
