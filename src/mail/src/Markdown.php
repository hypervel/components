<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Support\EncodedHtmlString;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Stringable;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

class Markdown
{
    /**
     * The current theme being used when generating emails.
     */
    protected string $theme = 'default';

    /**
     * The registered component paths.
     */
    protected array $componentPaths = [];

    /**
     * Indicates if secure encoding should be enabled.
     */
    protected static bool $withSecuredEncoding = false;

    /**
     * Create a new Markdown renderer instance.
     */
    public function __construct(
        protected ViewFactory $view,
        array $options = []
    ) {
        $this->theme = $options['theme'] ?? 'default';
        $this->loadComponentsFrom($options['paths'] ?? []);
    }

    /**
     * Render the Markdown template into HTML.
     */
    public function render(string $view, array $data = [], mixed $inliner = null, ?string $theme = null): HtmlString
    {
        // The message body and CSS theme can both resolve mail:: views, so keep
        // the namespace scoped across the whole HTML render.
        return $this->view->scopedNamespace('mail', $this->htmlComponentPaths(), function () use ($view, $data, $inliner, $theme) {
            $bladeCompiler = $this->view
                ->getEngineResolver() // @phpstan-ignore method.notFound
                ->resolve('blade')
                ->getCompiler();

            $contents = $bladeCompiler->usingEchoFormat(
                'new \Hypervel\Support\EncodedHtmlString(%s)',
                function () use ($view, $data) {
                    $render = fn () => $this->view->make($view, $data)->render();

                    if (static::$withSecuredEncoding === false) {
                        return $render();
                    }

                    return EncodedHtmlString::withEncoding(
                        function ($value) {
                            $replacements = [
                                '[' => '\[',
                                '<' => '&lt;',
                                '>' => '&gt;',
                            ];

                            return str_replace(array_keys($replacements), array_values($replacements), $value);
                        },
                        $render
                    );
                }
            );

            $theme ??= $this->theme;

            if ($this->view->exists($customTheme = Str::start($theme, 'mail.'))) {
                $theme = $customTheme;
            } else {
                $theme = str_contains($theme, '::')
                    ? $theme
                    : 'mail::themes.' . $theme;
            }

            return new HtmlString(($inliner ?: new CssToInlineStyles)->convert(
                str_replace('\[', '[', $contents),
                $this->view->make($theme, $data)->render()
            ));
        });
    }

    /**
     * Render the Markdown template into text.
     */
    public function renderText(string $view, array $data = []): HtmlString
    {
        $contents = $this->view->scopedNamespace(
            'mail',
            $this->textComponentPaths(),
            fn () => $this->view->make($view, $data)->render()
        );

        return new HtmlString(
            html_entity_decode(preg_replace("/[\r\n]{2,}/", "\n\n", $contents), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Parse the given Markdown text into HTML.
     */
    public static function parse(Stringable|string $text, bool $encoded = false): HtmlString
    {
        if ($encoded === false) {
            return new HtmlString(static::converter()->convert((string) $text)->getContent());
        }

        return new HtmlString(EncodedHtmlString::withEncoding(
            function ($value) {
                $replacements = [
                    '[' => '\[',
                    '<' => '\<',
                ];

                $html = str_replace(array_keys($replacements), array_values($replacements), $value);

                return static::converter([
                    'html_input' => 'escape',
                ])->convert($html)->getContent();
            },
            fn () => static::converter()->convert((string) $text)->getContent()
        ));
    }

    /**
     * Get a Markdown converter instance.
     *
     * @internal
     *
     * @param array<string, mixed> $config
     */
    public static function converter(array $config = []): MarkdownConverter
    {
        $environment = new Environment(array_merge([
            'allow_unsafe_links' => false,
        ], $config));

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        return new MarkdownConverter($environment);
    }

    /**
     * Get the HTML component paths.
     */
    public function htmlComponentPaths(): array
    {
        return array_map(function ($path) {
            return $path . '/html';
        }, $this->componentPaths());
    }

    /**
     * Get the text component paths.
     */
    public function textComponentPaths(): array
    {
        return array_map(function ($path) {
            return $path . '/text';
        }, $this->componentPaths());
    }

    /**
     * Get the component paths.
     */
    protected function componentPaths(): array
    {
        return array_unique(array_merge($this->componentPaths, [
            __DIR__ . '/../resources/views',
        ]));
    }

    /**
     * Register new mail component paths.
     *
     * Boot-only. Mutates the shared Markdown renderer's component paths; use
     * scoped view namespaces for per-render component path changes.
     */
    public function loadComponentsFrom(array $paths = []): void
    {
        $this->componentPaths = $paths;
    }

    /**
     * Set the default theme to be used.
     *
     * Boot-only. Mutates the shared Markdown renderer's default theme; pass a
     * per-render theme to render() for message-specific themes.
     */
    public function theme(string $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    /**
     * Get the theme currently being used by the renderer.
     */
    public function getTheme(): string
    {
        return $this->theme;
    }

    /**
     * Enable secured encoding when parsing Markdown.
     *
     * Boot-only. The flag persists in a static property for the worker lifetime
     * and applies to every subsequent Markdown render across all coroutines.
     */
    public static function withSecuredEncoding(): void
    {
        static::$withSecuredEncoding = true;
    }

    /**
     * Disable secured encoding when parsing Markdown.
     *
     * Boot-only. The flag persists in a static property for the worker lifetime
     * and applies to every subsequent Markdown render across all coroutines.
     */
    public static function withoutSecuredEncoding(): void
    {
        static::$withSecuredEncoding = false;
    }

    /**
     * Flush the class's global state.
     *
     * Boot or tests only. Clears worker-wide Markdown flags; concurrent renders
     * may switch encoding behavior depending on timing.
     */
    public static function flushState(): void
    {
        static::$withSecuredEncoding = false;
    }
}
