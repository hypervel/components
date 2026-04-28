<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Mail\Markdown;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\Engines\EngineResolver;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class MailMarkdownTest extends TestCase
{
    public function testRenderFunctionReturnsHtml()
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')->twice()->andReturn('<html></html>', 'body {}');

        $viewFactory = m::mock(ViewFactory::class);
        $engineResolver = m::mock(EngineResolver::class);
        $bladeCompiler = m::mock(BladeCompiler::class);
        $viewFactory->shouldReceive('getEngineResolver')->andReturn($engineResolver);
        $engineResolver->shouldReceive('resolve->getCompiler')->andReturn($bladeCompiler);
        $bladeCompiler->shouldReceive('usingEchoFormat')
            ->with('new \Hypervel\Support\EncodedHtmlString(%s)', m::type('Closure'))
            ->andReturnUsing(fn ($echoFormat, $callback) => $callback());

        $markdown = new Markdown($viewFactory);
        $viewFactory->shouldReceive('flushFinderCache')->once();
        $viewFactory->shouldReceive('replaceNamespace')->once()->with('mail', $markdown->htmlComponentPaths())->andReturnSelf();
        $viewFactory->shouldReceive('exists')->with('mail.default')->andReturn(false);
        $viewFactory->shouldReceive('make')->with('view', [])->andReturn($viewInterface);
        $viewFactory->shouldReceive('make')->with('mail::themes.default', [])->andReturn($viewInterface);

        $result = $markdown->render('view', [])->toHtml();

        $this->assertStringContainsString('<html></html>', $result);
    }

    public function testRenderFunctionReturnsHtmlWithCustomTheme()
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')->twice()->andReturn('<html></html>', 'body {}');

        $viewFactory = m::mock(ViewFactory::class);
        $engineResolver = m::mock(EngineResolver::class);
        $bladeCompiler = m::mock(BladeCompiler::class);
        $viewFactory->shouldReceive('getEngineResolver')->andReturn($engineResolver);
        $engineResolver->shouldReceive('resolve->getCompiler')->andReturn($bladeCompiler);
        $bladeCompiler->shouldReceive('usingEchoFormat')
            ->with('new \Hypervel\Support\EncodedHtmlString(%s)', m::type('Closure'))
            ->andReturnUsing(fn ($echoFormat, $callback) => $callback());

        $markdown = new Markdown($viewFactory);
        $markdown->theme('yaz');
        $viewFactory->shouldReceive('flushFinderCache')->once();
        $viewFactory->shouldReceive('replaceNamespace')->once()->with('mail', $markdown->htmlComponentPaths())->andReturnSelf();
        $viewFactory->shouldReceive('exists')->with('mail.yaz')->andReturn(true);
        $viewFactory->shouldReceive('make')->with('view', [])->andReturn($viewInterface);
        $viewFactory->shouldReceive('make')->with('mail.yaz', [])->andReturn($viewInterface);

        $result = $markdown->render('view', [])->toHtml();

        $this->assertStringContainsString('<html></html>', $result);
    }

    public function testRenderFunctionReturnsHtmlWithCustomThemeWithMailPrefix()
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')->twice()->andReturn('<html></html>', 'body {}');

        $viewFactory = m::mock(ViewFactory::class);
        $engineResolver = m::mock(EngineResolver::class);
        $bladeCompiler = m::mock(BladeCompiler::class);
        $viewFactory->shouldReceive('getEngineResolver')->andReturn($engineResolver);
        $engineResolver->shouldReceive('resolve->getCompiler')->andReturn($bladeCompiler);
        $bladeCompiler->shouldReceive('usingEchoFormat')
            ->with('new \Hypervel\Support\EncodedHtmlString(%s)', m::type('Closure'))
            ->andReturnUsing(fn ($echoFormat, $callback) => $callback());

        $markdown = new Markdown($viewFactory);
        $markdown->theme('mail.yaz');
        $viewFactory->shouldReceive('flushFinderCache')->once();
        $viewFactory->shouldReceive('replaceNamespace')->once()->with('mail', $markdown->htmlComponentPaths())->andReturnSelf();
        $viewFactory->shouldReceive('exists')->with('mail.yaz')->andReturn(true);
        $viewFactory->shouldReceive('make')->with('view', [])->andReturn($viewInterface);
        $viewFactory->shouldReceive('make')->with('mail.yaz', [])->andReturn($viewInterface);

        $result = $markdown->render('view', [])->toHtml();

        $this->assertStringContainsString('<html></html>', $result);
    }

    public function testRenderTextReturnsText()
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')->andReturn('text');

        $viewFactory = m::mock(ViewFactory::class);
        $markdown = new Markdown($viewFactory);
        $viewFactory->shouldReceive('flushFinderCache')->once();
        $viewFactory->shouldReceive('replaceNamespace')->once()->with('mail', $markdown->textComponentPaths())->andReturnSelf();
        $viewFactory->shouldReceive('make')->with('view', [])->andReturn($viewInterface);

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
