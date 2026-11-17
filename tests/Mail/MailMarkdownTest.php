<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\View\Factory as ViewFactoryContract;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Mail\Markdown;
use Hypervel\Tests\TestCase;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\Engines\CompilerEngine;
use Hypervel\View\Engines\EngineResolver;
use Hypervel\View\Factory as ViewFactory;
use Hypervel\View\FileViewFinder;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class MailMarkdownTest extends TestCase
{
    use VerifiesDoubles;

    public function testRenderFunctionReturnsHtml(): void
    {
        $viewInterface = Double::for(ViewContract::class)->strict();
        $viewInterface->expects('render')->times(2)->returns('<html></html>', 'body {}');

        $bladeCompiler = Double::for(BladeCompiler::class)->strict();
        $viewFactory = $this->viewFactory($bladeCompiler);
        $bladeCompiler->allows('usingEchoFormat')
            ->with('new \Hypervel\Support\EncodedHtmlString(%s)', Argument::type('Closure'))
            ->resolves(fn (string $echoFormat, Closure $callback): mixed => $callback());

        $markdown = new Markdown($viewFactory);
        $viewFactory->expects('replaceNamespace')->with('mail', $markdown->htmlComponentPaths());
        $viewFactory->allows('exists')->with('mail.default')->returns(false);
        $viewFactory->allows('make')->with('view', [])->returns($viewInterface);
        $viewFactory->allows('make')->with('mail::themes.default', [])->returns($viewInterface);

        $result = $markdown->render('view', [])->toHtml();

        $this->assertStringContainsString('<html></html>', $result);
    }

    public function testRenderFunctionReturnsHtmlWithCustomTheme(): void
    {
        $viewInterface = Double::for(ViewContract::class)->strict();
        $viewInterface->expects('render')->times(2)->returns('<html></html>', 'body {}');

        $bladeCompiler = Double::for(BladeCompiler::class)->strict();
        $viewFactory = $this->viewFactory($bladeCompiler);
        $bladeCompiler->allows('usingEchoFormat')
            ->with('new \Hypervel\Support\EncodedHtmlString(%s)', Argument::type('Closure'))
            ->resolves(fn (string $echoFormat, Closure $callback): mixed => $callback());

        $markdown = new Markdown($viewFactory);
        $markdown->theme('yaz');
        $viewFactory->expects('replaceNamespace')->with('mail', $markdown->htmlComponentPaths());
        $viewFactory->allows('exists')->with('mail.yaz')->returns(true);
        $viewFactory->allows('make')->with('view', [])->returns($viewInterface);
        $viewFactory->allows('make')->with('mail.yaz', [])->returns($viewInterface);

        $result = $markdown->render('view', [])->toHtml();

        $this->assertStringContainsString('<html></html>', $result);
    }

    public function testRenderFunctionReturnsHtmlWithCustomThemeWithMailPrefix(): void
    {
        $viewInterface = Double::for(ViewContract::class)->strict();
        $viewInterface->expects('render')->times(2)->returns('<html></html>', 'body {}');

        $bladeCompiler = Double::for(BladeCompiler::class)->strict();
        $viewFactory = $this->viewFactory($bladeCompiler);
        $bladeCompiler->allows('usingEchoFormat')
            ->with('new \Hypervel\Support\EncodedHtmlString(%s)', Argument::type('Closure'))
            ->resolves(fn (string $echoFormat, Closure $callback): mixed => $callback());

        $markdown = new Markdown($viewFactory);
        $markdown->theme('mail.yaz');
        $viewFactory->expects('replaceNamespace')->with('mail', $markdown->htmlComponentPaths());
        $viewFactory->allows('exists')->with('mail.yaz')->returns(true);
        $viewFactory->allows('make')->with('view', [])->returns($viewInterface);
        $viewFactory->allows('make')->with('mail.yaz', [])->returns($viewInterface);

        $result = $markdown->render('view', [])->toHtml();

        $this->assertStringContainsString('<html></html>', $result);
    }

    public function testRenderTextReturnsText(): void
    {
        $viewInterface = Double::for(ViewContract::class)->strict();
        $viewInterface->allows('render')->returns('text');

        $viewFactory = Double::for(ViewFactoryContract::class)->strict();
        $markdown = new Markdown($viewFactory);
        $viewFactory->expects('replaceNamespace')->with('mail', $markdown->textComponentPaths());
        $viewFactory->allows('make')->with('view', [])->returns($viewInterface);

        $result = $markdown->renderText('view', [])->toHtml();

        $this->assertSame('text', $result);
    }

    public function testParseReturnsParsedMarkdown(): void
    {
        $result = Markdown::parse('# Something')->toHtml();

        $this->assertSame("<h1>Something</h1>\n", $result);
    }

    /**
     * Create a view factory whose render-local clones retain real finder and engine state.
     */
    private function viewFactory(BladeCompiler $bladeCompiler): ViewFactory&DoubleInterface
    {
        $engines = new EngineResolver;
        $engines->register('blade', fn (): CompilerEngine => new CompilerEngine($bladeCompiler));

        return Double::for(new ViewFactory(
            $engines,
            new FileViewFinder(new Filesystem, []),
            new Dispatcher(new Container),
        ))->passthru();
    }
}
