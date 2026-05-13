<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Closure;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Mail\Factory as MailFactory;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\Markdown;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Messages\MailMessage as NotificationMailMessage;
use Hypervel\Support\EncodedHtmlString;
use Hypervel\Support\HtmlString;
use Hypervel\Tests\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

class MarkdownCoroutineSafetyTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        Markdown::flushState();
        EncodedHtmlString::flushState();

        parent::tearDown();
    }

    public function testRenderDoesNotClearBootEncoder(): void
    {
        EncodedHtmlString::encodeUsing(fn ($value) => "boot:{$value}");

        $markdown = new Markdown(new MarkdownTestViewFactory(
            fn () => (new EncodedHtmlString('rendered'))->toHtml()
        ));

        $this->assertSame('boot:rendered', $markdown->render('view', inliner: new MarkdownPassthroughInliner)->toHtml());
        $this->assertSame('boot:after', (new EncodedHtmlString('after'))->toHtml());
    }

    public function testEncodedParseDoesNotClearBootEncoder(): void
    {
        EncodedHtmlString::encodeUsing(fn ($value) => "boot:{$value}");

        $html = Markdown::parse(new EncodedHtmlString('Visit <span>https://hypervel.org/docs</span>'), encoded: true);

        $this->assertStringContainsString('Visit &lt;span&gt;https://hypervel.org/docs&lt;/span&gt;', $html->toHtml());
        $this->assertSame('boot:after', (new EncodedHtmlString('after'))->toHtml());
    }

    public function testSecuredRenderEncodingIsIsolatedBetweenCoroutines(): void
    {
        Markdown::withSecuredEncoding();

        $results = parallel([
            'a' => fn () => $this->renderEncodingProbe(),
            'b' => fn () => $this->renderEncodingProbe(),
        ]);

        $this->assertSame('scoped:scoped', $results['a']);
        $this->assertSame('scoped:scoped', $results['b']);
    }

    public function testRenderUsesPerCallThemeWithoutMutatingDefaultTheme(): void
    {
        $markdown = new Markdown(new MarkdownTestViewFactory(
            fn () => 'content',
            fn (string $view) => $view
        ));
        $markdown->theme('default-a');

        $this->assertSame('content|theme:mail::themes.theme-b', $markdown->render(
            'view',
            inliner: new MarkdownPassthroughInliner,
            theme: 'theme-b'
        )->toHtml());
        $this->assertSame('default-a', $markdown->getTheme());
    }

    public function testRenderDoesNotFlushUnrelatedFinderCache(): void
    {
        $factory = new MarkdownTestViewFactory(fn () => 'content');
        $factory->cacheView('unrelated', '/cached/unrelated.php');

        $markdown = new Markdown($factory);

        $markdown->render('view', inliner: new MarkdownPassthroughInliner);

        $this->assertSame('/cached/unrelated.php', $factory->cachedView('unrelated'));
    }

    public function testRenderAndRenderTextUseIsolatedMailNamespaces(): void
    {
        $factory = null;
        $factory = new MarkdownTestViewFactory(function () use (&$factory) {
            $before = basename($factory->currentMailNamespacePath());
            usleep(5000);

            return $before . ':' . basename($factory->currentMailNamespacePath());
        });

        $markdown = new Markdown($factory);

        $results = parallel([
            'html' => fn () => $markdown->render('view', inliner: new MarkdownPassthroughInliner)->toHtml(),
            'text' => fn () => $markdown->renderText('view')->toHtml(),
        ]);

        $this->assertSame('html:html', $results['html']);
        $this->assertSame('text:text', $results['text']);
    }

    public function testConcurrentMailablesUseTheirOwnThemes(): void
    {
        $markdown = $this->swapThemeProbeMarkdownIntoContainer();

        $results = parallel([
            'a' => fn () => (new ThemeProbeMailable('theme-a'))->renderProbe(),
            'b' => fn () => (new ThemeProbeMailable('theme-b'))->renderProbe(),
        ]);

        $this->assertSame('theme-a:theme-a', $results['a']);
        $this->assertSame('theme-b:theme-b', $results['b']);
        $this->assertSame('default', $markdown->getTheme());
    }

    public function testMailMessageRenderUsesThemeWithoutMutatingMarkdownSingleton(): void
    {
        $markdown = $this->swapThemeProbeMarkdownIntoContainer();

        $message = (new NotificationMailMessage)->theme('theme-a');

        $this->assertSame('theme-a:theme-a', $message->render());
        $this->assertSame('default', $markdown->getTheme());
    }

    public function testMailChannelUsesNotificationThemeWithoutMutatingMarkdownSingleton(): void
    {
        $markdown = $this->swapThemeProbeMarkdownIntoContainer();
        $channel = new ThemeProbeMailChannel(m::mock(MailFactory::class), $markdown);
        $message = (new NotificationMailMessage)->theme('theme-a');

        $this->assertSame('theme-a:theme-a', $channel->renderHtml($message));
        $this->assertSame('default', $markdown->getTheme());
    }

    protected function swapThemeProbeMarkdownIntoContainer(): ThemeProbeMarkdown
    {
        $container = new Container;
        $markdown = new ThemeProbeMarkdown;

        $container->instance('config', new Repository([
            'mail' => [
                'markdown' => [
                    'theme' => 'default',
                ],
            ],
        ]));
        $container->instance(Markdown::class, $markdown);

        Container::setInstance($container);

        return $markdown;
    }

    protected function renderEncodingProbe(): string
    {
        $markdown = new Markdown(new MarkdownTestViewFactory(function () {
            $before = $this->encodingMarker();
            usleep(5000);

            return $before . ':' . $this->encodingMarker();
        }));

        return $markdown->render('view', inliner: new MarkdownPassthroughInliner)->toHtml();
    }

    protected function encodingMarker(): string
    {
        return (new EncodedHtmlString('['))->toHtml() === '\['
            ? 'scoped'
            : 'default';
    }
}

class MarkdownTestViewFactory implements ViewFactory
{
    protected const NAMESPACE_CONTEXT_KEY = '__tests.markdown.mail_namespace';

    protected array $cachedViews = [];

    public function __construct(
        protected Closure $renderer,
        protected ?Closure $themeRenderer = null
    ) {
    }

    public function scopedNamespace(string $namespace, string|array $hints, Closure $callback): mixed
    {
        $overrides = CoroutineContext::get(self::NAMESPACE_CONTEXT_KEY, []);
        $hadPreviousHints = array_key_exists($namespace, $overrides);
        $previousHints = $overrides[$namespace] ?? null;

        $overrides[$namespace] = (array) $hints;
        CoroutineContext::set(self::NAMESPACE_CONTEXT_KEY, $overrides);

        try {
            return $callback();
        } finally {
            $overrides = CoroutineContext::get(self::NAMESPACE_CONTEXT_KEY, []);

            if ($hadPreviousHints) {
                $overrides[$namespace] = $previousHints;
            } else {
                unset($overrides[$namespace]);
            }

            if ($overrides === []) {
                CoroutineContext::forget(self::NAMESPACE_CONTEXT_KEY);
            } else {
                CoroutineContext::set(self::NAMESPACE_CONTEXT_KEY, $overrides);
            }
        }
    }

    public function exists(string $view): bool
    {
        return false;
    }

    public function file(string $path, Arrayable|array $data = [], array $mergeData = []): ViewContract
    {
        return $this->make($path, $data, $mergeData);
    }

    public function make(string $view, Arrayable|array $data = [], array $mergeData = []): ViewContract
    {
        if (str_starts_with($view, 'mail::themes.')) {
            return new MarkdownTestView(fn () => is_null($this->themeRenderer)
                ? ''
                : call_user_func($this->themeRenderer, $view));
        }

        return new MarkdownTestView($this->renderer);
    }

    public function first(array $views, Arrayable|array $data = [], array $mergeData = []): ViewContract
    {
        return $this->make($views[0], $data, $mergeData);
    }

    public function share(array|string $key, mixed $value = null): mixed
    {
        return null;
    }

    public function composer(array|string $views, Closure|string $callback): array
    {
        return [];
    }

    public function creator(array|string $views, Closure|string $callback): array
    {
        return [];
    }

    public function addNamespace(string $namespace, string|array $hints): static
    {
        return $this;
    }

    public function replaceNamespace(string $namespace, string|array $hints): static
    {
        return $this;
    }

    public function flushFinderCache(): void
    {
        $this->cachedViews = [];
    }

    public function getEngineResolver(): MarkdownTestEngineResolver
    {
        return new MarkdownTestEngineResolver;
    }

    public function cacheView(string $name, string $path): void
    {
        $this->cachedViews[$name] = $path;
    }

    public function cachedView(string $name): ?string
    {
        return $this->cachedViews[$name] ?? null;
    }

    public function currentMailNamespacePath(): string
    {
        $overrides = CoroutineContext::get(self::NAMESPACE_CONTEXT_KEY, []);

        return $overrides['mail'][0] ?? '';
    }
}

class MarkdownTestEngineResolver
{
    public function resolve(string $engine): MarkdownTestCompilerEngine
    {
        return new MarkdownTestCompilerEngine;
    }
}

class MarkdownTestCompilerEngine
{
    public function getCompiler(): MarkdownTestBladeCompiler
    {
        return new MarkdownTestBladeCompiler;
    }
}

class MarkdownTestBladeCompiler
{
    public function usingEchoFormat(string $format, callable $callback): string
    {
        return $callback();
    }
}

class MarkdownTestView implements ViewContract
{
    public function __construct(protected Closure $renderer)
    {
    }

    public function render(): string
    {
        return call_user_func($this->renderer);
    }

    public function name(): string
    {
        return 'view';
    }

    public function with(string|array $key, mixed $value = null): static
    {
        return $this;
    }

    public function getData(): array
    {
        return [];
    }

    public function getPath(): string
    {
        return __FILE__;
    }
}

class MarkdownPassthroughInliner
{
    public function convert(string $html, string $css): string
    {
        return $css === '' ? $html : "{$html}|theme:{$css}";
    }
}

class ThemeProbeMarkdown extends Markdown
{
    public function __construct()
    {
    }

    public function render(string $view, array $data = [], mixed $inliner = null, ?string $theme = null): HtmlString
    {
        $before = $theme ?? $this->getTheme();
        usleep(5000);

        return new HtmlString($before . ':' . ($theme ?? $this->getTheme()));
    }

    public function renderText(string $view, array $data = []): HtmlString
    {
        return new HtmlString('text');
    }
}

class ThemeProbeMailable extends Mailable
{
    public string $markdown = 'message';

    public function __construct(string $theme)
    {
        $this->theme = $theme;
    }

    public function renderProbe(): string
    {
        return $this->buildMarkdownHtml([])([])->toHtml();
    }
}

class ThemeProbeMailChannel extends MailChannel
{
    public function renderHtml(NotificationMailMessage $message): string
    {
        return $this->buildMarkdownHtml($message)([])->toHtml();
    }
}
