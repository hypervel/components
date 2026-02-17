<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Mail\Markdown;
use Hypervel\View\Contracts\Factory as ViewFactory;
use Hypervel\View\Contracts\View as ViewContract;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MailMarkdownTest extends TestCase
{
    public function testRenderFunctionReturnsHtml()
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')->twice()->andReturn('<html></html>', 'body {}');

        $viewFactory = m::mock(ViewFactory::class);
        $viewFactory->shouldReceive('make')->with('view', [])->andReturn($viewInterface);
        $viewFactory->shouldReceive('make')->with('mail::themes.default', [])->andReturn($viewInterface);
        $viewFactory->shouldReceive('exists')->with('mail.default')->andReturn(false);

        $markdown = new Markdown($viewFactory);
        $viewFactory->shouldReceive('replaceNamespace')->once()->with('mail', $markdown->htmlComponentPaths())->andReturnSelf();

        $result = $markdown->render('view', [])->toHtml();

        $this->assertStringContainsString('<html></html>', $result);
    }

    public function testRenderFunctionReturnsHtmlWithCustomTheme()
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')->twice()->andReturn('<html></html>', 'body {}');

        $viewFactory = m::mock(ViewFactory::class);
        $viewFactory->shouldReceive('make')->with('view', [])->andReturn($viewInterface);
        $viewFactory->shouldReceive('make')->with('mail.yaz', [])->andReturn($viewInterface);
        $viewFactory->shouldReceive('exists')->with('mail.yaz')->andReturn(true);

        $markdown = new Markdown($viewFactory);
        $markdown->theme('yaz');
        $viewFactory->shouldReceive('replaceNamespace')->once()->with('mail', $markdown->htmlComponentPaths())->andReturnSelf();

        $result = $markdown->render('view', [])->toHtml();

        $this->assertStringContainsString('<html></html>', $result);
    }

    public function testRenderFunctionReturnsHtmlWithCustomThemeWithMailPrefix()
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')->twice()->andReturn('<html></html>', 'body {}');

        $viewFactory = m::mock(ViewFactory::class);
        $viewFactory->shouldReceive('make')->with('view', [])->andReturn($viewInterface);
        $viewFactory->shouldReceive('make')->with('mail.yaz', [])->andReturn($viewInterface);
        $viewFactory->shouldReceive('exists')->with('mail.yaz')->andReturn(true);

        $markdown = new Markdown($viewFactory);
        $markdown->theme('mail.yaz');
        $viewFactory->shouldReceive('replaceNamespace')->once()->with('mail', $markdown->htmlComponentPaths())->andReturnSelf();

        $result = $markdown->render('view', [])->toHtml();

        $this->assertStringContainsString('<html></html>', $result);
    }

    public function testRenderTextReturnsText()
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')->andReturn('text');

        $viewFactory = m::mock(ViewFactory::class);
        $viewFactory->shouldReceive('make')->with('view', [])->andReturn($viewInterface);
        $viewFactory->shouldReceive('exists')->with('mail.yaz')->andReturn(true);

        $markdown = new Markdown($viewFactory);
        $viewFactory->shouldReceive('replaceNamespace')->once()->with('mail', $markdown->textComponentPaths())->andReturnSelf();

        $result = $markdown->renderText('view', [])->toHtml();

        $this->assertSame('text', $result);
    }

    public function testParseReturnsParsedMarkdown()
    {
        $viewFactory = m::mock(ViewFactory::class);
        $markdown = new Markdown($viewFactory);

        $result = $markdown->parse('# Something')->toHtml();

        $this->assertSame("<h1>Something</h1>\n", $result);
    }
}
