<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Foundation\Exceptions\HtmlErrorRenderer;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @internal
 * @coversNothing
 */
class HtmlErrorRendererTest extends TestCase
{
    protected HtmlErrorRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new HtmlErrorRenderer();
    }

    public function testItEscapesHtmlInExceptionMessageForNonDebugHttpExceptions()
    {
        $exception = new HttpException(500, '<script>alert("xss")</script>');

        $html = $this->renderer->render($exception, debug: false);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testItEscapesHtmlInExceptionMessageForDebugMode()
    {
        $exception = new RuntimeException('<img src=x onerror=alert(1)>');

        $html = $this->renderer->render($exception, debug: true);

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }

    public function testDebugInfoContainsFileAndTrace()
    {
        $exception = new RuntimeException('test');

        $html = $this->renderer->render($exception, debug: true);

        $this->assertStringContainsString('Debug Information', $html);
        $this->assertStringContainsString(__FILE__, $html);
        $this->assertStringContainsString('Stack Trace', $html);
    }

    public function testItShowsStatusTextForNonDebugHttpExceptions()
    {
        $exception = new HttpException(404);

        $html = $this->renderer->render($exception, debug: false);

        $this->assertStringContainsString('Not Found', $html);
    }

    public function testItShowsGenericMessageForNonDebugNonHttpExceptions()
    {
        $exception = new RuntimeException('secret database password');

        $html = $this->renderer->render($exception, debug: false);

        $this->assertStringContainsString('Whoops, looks like something went wrong.', $html);
        $this->assertStringNotContainsString('secret database password', $html);
    }

    public function testItShowsExceptionClassInDebugMode()
    {
        $exception = new RuntimeException('Something broke');

        $html = $this->renderer->render($exception, debug: true);

        $this->assertStringContainsString('RuntimeException', $html);
        $this->assertStringContainsString('Something broke', $html);
    }
}
