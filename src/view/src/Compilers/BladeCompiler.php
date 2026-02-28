<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use Hypervel\Support\Traits\ReflectsClosures;
use Hypervel\View\Component;
use Hypervel\View\Contracts\Factory as ViewFactory;
use Hypervel\View\Contracts\View;
use InvalidArgumentException;

class BladeCompiler extends Compiler implements CompilerInterface
{
    use Concerns\CompilesAuthorizations;
    use Concerns\CompilesClasses;
    use Concerns\CompilesComments;
    use Concerns\CompilesComponents;
    use Concerns\CompilesConditionals;
    use Concerns\CompilesEchos;
    use Concerns\CompilesErrors;
    use Concerns\CompilesFragments;
    use Concerns\CompilesHelpers;
    use Concerns\CompilesIncludes;
    use Concerns\CompilesInjections;
    use Concerns\CompilesJson;
    use Concerns\CompilesJs;
    use Concerns\CompilesLayouts;
    use Concerns\CompilesLoops;
    use Concerns\CompilesRawPhp;
    use Concerns\CompilesSessions;
    use Concerns\CompilesStacks;
    use Concerns\CompilesStyles;
    use Concerns\CompilesTranslations;
    use Concerns\CompilesUseStatements;
    use ReflectsClosures;

    /*
     * Temporarily store the raw blocks found in the template.
     */
    protected const RAW_BLOCKS_CONTEXT_KEY = 'raw_blocks';

    /*
     * Footer lines to be added to the template.
     */
    protected const FOOTER_CONTEXT_KEY = 'footer';

    /**
     * The "regular" / legacy echo string format.
     */
    protected const ECHO_FORMAT_CONTEXT_KEY = 'echo_format';

    /**
     * All of the registered extensions.
     */
    protected array $extensions = [];

    /**
     * All custom "directive" handlers.
     */
    protected array $customDirectives = [];

    /**
     * All custom "condition" handlers.
     */
    protected array $conditions = [];

    /**
     * The registered string preparation callbacks.
     */
    protected array $prepareStringsForCompilationUsing = [];

    /**
     * All of the registered precompilers.
     */
    protected array $precompilers = [];

    /**
     * The file currently being compiled.
     */
    protected string $path;

    /**
     * All of the available compiler functions.
     */
    protected array $compilers = [
        'Extensions',
        'Statements',
        'Echos',
    ];

    /**
     * Array of opening and closing tags for raw echos.
     */
    protected array $rawTags = ['{!!', '!!}'];

    /**
     * Array of opening and closing tags for regular echos.
     */
    protected array $contentTags = ['{{', '}}'];

    /**
     * Array of opening and closing tags for escaped echos.
     */
    protected array $escapedTags = ['{{{', '}}}'];

    /**
     * Array of footer lines to be added to the template.
     */
    protected array $footer = [];

    /**
     * The array of anonymous component paths to search for components in.
     */
    protected array $anonymousComponentPaths = [];

    /**
     * The array of anonymous component namespaces to autoload from.
     */
    protected array $anonymousComponentNamespaces = [];

    /**
     * The array of class component aliases and their class names.
     */
    protected array $classComponentAliases = [];

    /**
     * The array of class component namespaces to autoload from.
     */
    protected array $classComponentNamespaces = [];

    /**
     * Indicates if component tags should be compiled.
     */
    protected bool $compilesComponentTags = true;

    /**
     * The component tag compiler instance.
     */
    protected ComponentTagCompiler $componentTagCompiler;

    /**
     * Compile the view at the given path.
     */
    public function compile(string $path): void
    {
        $contents = $this->compileString($this->files->get($path));

        $contents = $this->appendFilePath($contents, $path);

        $this->ensureCompiledDirectoryExists(
            $compiledPath = $this->getCompiledPath($path)
        );

        $this->files->put($compiledPath, $contents);
    }

    /**
     * Append the file path to the compiled string.
     */
    protected function appendFilePath(string $contents, string $path): string
    {
        $tokens = $this->getOpenAndClosingPhpTokens($contents);

        if ($tokens->isNotEmpty() && $tokens->last() !== T_CLOSE_TAG) {
            $contents .= ' ?>';
        }

        return $contents . "<?php /**PATH {$path} ENDPATH**/ ?>";
    }

    /**
     * Get the open and closing PHP tag tokens from the given string.
     */
    protected function getOpenAndClosingPhpTokens(string $contents): Collection
    {
        return (new Collection(token_get_all($contents)))
            ->pluck('0')
            ->filter(function ($token) {
                return in_array($token, [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG]);
            });
    }

    /**
     * Compile the given Blade template contents.
     */
    public function compileString(string $value): string
    {
        Context::set(static::FOOTER_CONTEXT_KEY, []);
        $result = '';

        foreach ($this->prepareStringsForCompilationUsing as $callback) {
            $value = $callback($value);
        }

        $value = $this->storeUncompiledBlocks($value);

        // First we will compile the Blade component tags. This is a precompile style
        // step which compiles the component Blade tags into @component directives
        // that may be used by Blade. Then we should call any other precompilers.
        $value = $this->compileComponentTags(
            $this->compileComments($value)
        );

        foreach ($this->precompilers as $precompiler) {
            $value = $precompiler($value);
        }

        // Here we will loop through all of the tokens returned by the Zend lexer and
        // parse each one into the corresponding valid PHP. We will then have this
        // template as the correctly rendered PHP that can be rendered natively.
        foreach (token_get_all($value) as $token) {
            $result .= is_array($token) ? $this->parseToken($token) : $token;
        }

        if (! empty(Context::get(static::RAW_BLOCKS_CONTEXT_KEY))) {
            $result = $this->restoreRawContent($result);
        }

        // If there are any footer lines that need to get added to a template we will
        // add them here at the end of the template. This gets used mainly for the
        // template inheritance via the extends keyword that should be appended.
        $footer = Context::get(static::FOOTER_CONTEXT_KEY, []);
        if (count($footer) > 0) {
            $result = $this->addFooters($result, $footer);
        }

        if (! empty($this->echoHandlers)) {
            $result = $this->addBladeCompilerVariable($result);
        }

        return str_replace(
            ['##BEGIN-COMPONENT-CLASS##', '##END-COMPONENT-CLASS##'],
            '',
            $result
        );
    }

    /**
     * Evaluate and render a Blade string to HTML.
     */
    public static function render(string $string, array $data = [], bool $deleteCachedView = false): string
    {
        $component = new class($string) extends Component {
            protected $template;

            public function __construct($template)
            {
                $this->template = $template;
            }

            public function render(): View|Htmlable|Closure|string
            {
                return $this->template;
            }
        };

        $view = Container::getInstance()
            ->make(ViewFactory::class)
            ->make($component->resolveView(), $data);

        return tap($view->render(), function () use ($view, $deleteCachedView) {
            if ($deleteCachedView) {
                @unlink($view->getPath());
            }
        });
    }

    /**
     * Render a component instance to HTML.
     */
    public static function renderComponent(Component $component): string
    {
        $data = $component->data();

        $view = value($component->resolveView(), $data);

        if ($view instanceof View) {
            return $view->with($data)->render();
        }
        if ($view instanceof Htmlable) {
            return $view->toHtml();
        }
        return Container::getInstance()
            ->make(ViewFactory::class)
            ->make($view, $data)
            ->render();
    }

    /**
     * Store the blocks that do not receive compilation.
     */
    protected function storeUncompiledBlocks(string $value): string
    {
        if (str_contains($value, '@verbatim')) {
            $value = $this->storeVerbatimBlocks($value);
        }

        if (str_contains($value, '@php')) {
            $value = $this->storePhpBlocks($value);
        }

        return $value;
    }

    /**
     * Store the verbatim blocks and replace them with a temporary placeholder.
     */
    protected function storeVerbatimBlocks(string $value): string
    {
        return preg_replace_callback('/(?<!@)@verbatim(\s*)(.*?)@endverbatim/s', function ($matches) {
            return $matches[1] . $this->storeRawBlock($matches[2]);
        }, $value);
    }

    /**
     * Store the PHP blocks and replace them with a temporary placeholder.
     */
    protected function storePhpBlocks(string $value): string
    {
        return preg_replace_callback('/(?<!@)@php(.*?)@endphp/s', function ($matches) {
            return $this->storeRawBlock("<?php{$matches[1]}?>");
        }, $value);
    }

    /**
     * Store a raw block and return a unique raw placeholder.
     */
    protected function storeRawBlock(string $value): string
    {
        return $this->getRawPlaceholder(
            $this->pushRawBlock($value) - 1
        );
    }

    /**
     * Temporarily store the raw block found in the template.
     *
     * @return int the number of raw blocks in the stack after pushing the new one
     */
    protected function pushRawBlock(string $value): int
    {
        $stack = Context::get(static::RAW_BLOCKS_CONTEXT_KEY, []);
        $stack[] = $value;
        Context::set(static::RAW_BLOCKS_CONTEXT_KEY, $stack);

        return count($stack);
    }

    /**
     * Compile the component tags.
     */
    protected function compileComponentTags(string $value): string
    {
        if (! $this->compilesComponentTags) {
            return $value;
        }

        return $this->getComponentTagCompiler()->compile($value);
    }

    protected function getComponentTagCompiler(): ComponentTagCompiler
    {
        if (isset($this->componentTagCompiler)) {
            return $this->componentTagCompiler;
        }

        return $this->componentTagCompiler = new ComponentTagCompiler(
            $this->classComponentAliases,
            $this->classComponentNamespaces,
            $this
        );
    }

    /**
     * Replace the raw placeholders with the original code stored in the raw blocks.
     */
    protected function restoreRawContent(string $result): string
    {
        $rawBlocks = Context::get(static::RAW_BLOCKS_CONTEXT_KEY);

        $result = preg_replace_callback('/' . $this->getRawPlaceholder('(\d+)') . '/', function ($matches) use ($rawBlocks) {
            return $rawBlocks[$matches[1]];
        }, $result);

        $rawBlocks = Context::set(static::RAW_BLOCKS_CONTEXT_KEY, []);

        return $result;
    }

    /**
     * Get a placeholder to temporarily mark the position of raw blocks.
     */
    protected function getRawPlaceholder(int|string $replace): string
    {
        return str_replace('#', (string) $replace, '@__raw_block_#__@');
    }

    /**
     * Add the stored footers onto the given content.
     */
    protected function addFooters(string $result, array $footer): string
    {
        return ltrim($result, "\n")
                . "\n" . implode("\n", array_reverse($footer));
    }

    /**
     * Parse the tokens from the template.
     */
    protected function parseToken(array $token): string
    {
        [$id, $content] = $token;

        if ($id == T_INLINE_HTML) {
            foreach ($this->compilers as $type) {
                $content = $this->{"compile{$type}"}($content);
            }
        }

        return $content;
    }

    /**
     * Execute the user defined extensions.
     */
    protected function compileExtensions(string $value): string
    {
        foreach ($this->extensions as $compiler) {
            $value = $compiler($value, $this);
        }

        return $value;
    }

    /**
     * Compile Blade statements that start with "@".
     */
    protected function compileStatements(string $template): string
    {
        preg_match_all('/\B@(@?\w+(?:::\w+)?)([ \t]*)(\( ( [\S\s]*? ) \))?/x', $template, $matches);

        $offset = 0;

        for ($i = 0; isset($matches[0][$i]); ++$i) {
            $match = [
                $matches[0][$i],
                $matches[1][$i],
                $matches[2][$i],
                $matches[3][$i] ?: null,
                $matches[4][$i] ?: null,
            ];

            // Here we check to see if we have properly found the closing parenthesis by
            // regex pattern or not, and will recursively continue on to the next ")"
            // then check again until the tokenizer confirms we find the right one.
            while (isset($match[4])
                   && Str::endsWith($match[0], ')')
                   && ! $this->hasEvenNumberOfParentheses($match[0])) {
                if (($after = Str::after($template, $match[0])) === $template) {
                    break;
                }

                $rest = Str::before($after, ')');

                if (isset($matches[0][$i + 1]) && Str::contains($rest . ')', $matches[0][$i + 1])) {
                    unset($matches[0][$i + 1]);
                    ++$i;
                }

                $match[0] = $match[0] . $rest . ')';
                $match[3] = $match[3] . $rest . ')';
                $match[4] = $match[4] . $rest;
            }

            [$template, $offset] = $this->replaceFirstStatement(
                $match[0],
                $this->compileStatement($match),
                $template,
                $offset
            );
        }

        return $template;
    }

    /**
     * Replace the first match for a statement compilation operation.
     */
    protected function replaceFirstStatement(string $search, string $replace, string $subject, int $offset): array
    {
        $search = (string) $search;

        if ($search === '') {
            return [$subject, 0];
        }

        $position = strpos($subject, $search, $offset);

        if ($position !== false) {
            return [
                substr_replace($subject, $replace, $position, strlen($search)),
                $position + strlen($replace),
            ];
        }

        return [$subject, 0];
    }

    /**
     * Determine if the given expression has the same number of opening and closing parentheses.
     */
    protected function hasEvenNumberOfParentheses(string $expression): bool
    {
        $tokens = token_get_all('<?php ' . $expression);

        if (Arr::last($tokens) !== ')') {
            return false;
        }

        $opening = 0;
        $closing = 0;

        foreach ($tokens as $token) {
            if ($token == ')') {
                ++$closing;
            } elseif ($token == '(') {
                ++$opening;
            }
        }

        return $opening === $closing;
    }

    /**
     * Compile a single Blade @ statement.
     */
    protected function compileStatement(array $match): string
    {
        if (str_contains($match[1], '@')) {
            $match[0] = isset($match[3]) ? $match[1] . $match[3] : $match[1];
        } elseif (isset($this->customDirectives[$match[1]])) {
            $match[0] = $this->callCustomDirective($match[1], Arr::get($match, 3));
        } elseif (method_exists($this, $method = 'compile' . ucfirst($match[1]))) {
            $match[0] = $this->{$method}(Arr::get($match, 3));
        } else {
            return $match[0];
        }

        return isset($match[3]) ? $match[0] : $match[0] . $match[2];
    }

    /**
     * Call the given directive with the given value.
     */
    protected function callCustomDirective(string $name, ?string $value): string
    {
        $value ??= '';

        if (str_starts_with($value, '(') && str_ends_with($value, ')')) {
            $value = Str::substr($value, 1, -1);
        }

        return call_user_func($this->customDirectives[$name], trim($value));
    }

    /**
     * Strip the parentheses from the given expression.
     */
    public function stripParentheses(string $expression): string
    {
        if (Str::startsWith($expression, '(')) {
            $expression = substr($expression, 1, -1);
        }

        return $expression;
    }

    /**
     * Register a custom Blade compiler.
     */
    public function extend(callable $compiler): void
    {
        $this->extensions[] = $compiler;
    }

    /**
     * Get the extensions used by the compiler.
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * Register an "if" statement directive.
     */
    public function if(string $name, callable $callback): void
    {
        $this->conditions[$name] = $callback;

        $this->directive($name, function ($expression) use ($name) {
            return $expression !== ''
                    ? "<?php if (\\Hypervel\\Support\\Facades\\Blade::check('{$name}', {$expression})): ?>"
                    : "<?php if (\\Hypervel\\Support\\Facades\\Blade::check('{$name}')): ?>";
        });

        $this->directive('unless' . $name, function ($expression) use ($name) {
            return $expression !== ''
                ? "<?php if (! \\Hypervel\\Support\\Facades\\Blade::check('{$name}', {$expression})): ?>"
                : "<?php if (! \\Hypervel\\Support\\Facades\\Blade::check('{$name}')): ?>";
        });

        $this->directive('else' . $name, function ($expression) use ($name) {
            return $expression !== ''
                ? "<?php elseif (\\Hypervel\\Support\\Facades\\Blade::check('{$name}', {$expression})): ?>"
                : "<?php elseif (\\Hypervel\\Support\\Facades\\Blade::check('{$name}')): ?>";
        });

        $this->directive('end' . $name, function () {
            return '<?php endif; ?>';
        });
    }

    /**
     * Check the result of a condition.
     */
    public function check(string $name, mixed ...$parameters): bool
    {
        return call_user_func($this->conditions[$name], ...$parameters);
    }

    /**
     * Register a class-based component alias directive.
     */
    public function component(string $class, ?string $alias = null, string $prefix = ''): void
    {
        if (! is_null($alias) && str_contains($alias, '\\')) {
            [$class, $alias] = [$alias, $class];
        }

        if (is_null($alias)) {
            $alias = str_contains($class, '\View\Components\\')
                            ? (new Collection(explode('\\', Str::after($class, '\View\Components\\'))))->map(function ($segment) {
                                return Str::kebab($segment);
                            })->implode(':')
                            : Str::kebab(class_basename($class));
        }

        if (! empty($prefix)) {
            $alias = $prefix . '-' . $alias;
        }

        $this->classComponentAliases[$alias] = $class;
    }

    /**
     * Register an array of class-based components.
     */
    public function components(array $components, string $prefix = ''): void
    {
        foreach ($components as $key => $value) {
            if (is_numeric($key)) {
                $this->component($value, null, $prefix);
            } else {
                $this->component($key, $value, $prefix);
            }
        }
    }

    /**
     * Get the registered class component aliases.
     */
    public function getClassComponentAliases(): array
    {
        return $this->classComponentAliases;
    }

    /**
     * Register a new anonymous component path.
     */
    public function anonymousComponentPath(string $path, ?string $prefix = null): void
    {
        $prefixHash = md5($prefix ?: $path);

        $this->anonymousComponentPaths[] = [
            'path' => $path,
            'prefix' => $prefix,
            'prefixHash' => $prefixHash,
        ];

        Container::getInstance()
            ->make(ViewFactory::class)
            ->addNamespace($prefixHash, $path);
    }

    /**
     * Register an anonymous component namespace.
     */
    public function anonymousComponentNamespace(string $directory, ?string $prefix = null): void
    {
        $prefix ??= $directory;

        $this->anonymousComponentNamespaces[$prefix] = (new Stringable($directory))
            ->replace('/', '.')
            ->trim('. ')
            ->toString();
    }

    /**
     * Register a class-based component namespace.
     */
    public function componentNamespace(string $namespace, string $prefix): void
    {
        $this->classComponentNamespaces[$prefix] = $namespace;
    }

    /**
     * Get the registered anonymous component paths.
     */
    public function getAnonymousComponentPaths(): array
    {
        return $this->anonymousComponentPaths;
    }

    /**
     * Get the registered anonymous component namespaces.
     */
    public function getAnonymousComponentNamespaces(): array
    {
        return $this->anonymousComponentNamespaces;
    }

    /**
     * Get the registered class component namespaces.
     */
    public function getClassComponentNamespaces(): array
    {
        return $this->classComponentNamespaces;
    }

    /**
     * Register a component alias directive.
     */
    public function aliasComponent(string $path, ?string $alias = null): void
    {
        $alias = $alias ?: Arr::last(explode('.', $path));

        $this->directive($alias, function ($expression) use ($path) {
            return $expression
                        ? "<?php \$__env->startComponent('{$path}', {$expression}); ?>"
                        : "<?php \$__env->startComponent('{$path}'); ?>";
        });

        $this->directive('end' . $alias, function ($expression) {
            return '<?php echo $__env->renderComponent(); ?>';
        });
    }

    /**
     * Register an include alias directive.
     */
    public function include(string $path, ?string $alias = null): void
    {
        $this->aliasInclude($path, $alias);
    }

    /**
     * Register an include alias directive.
     */
    public function aliasInclude(string $path, ?string $alias = null): void
    {
        $alias = $alias ?: Arr::last(explode('.', $path));

        $this->directive($alias, function ($expression) use ($path) {
            $expression = $this->stripParentheses($expression) ?: '[]';

            return "<?php echo \$__env->make('{$path}', {$expression}, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>";
        });
    }

    /**
     * Register a handler for custom directives, binding the handler to the compiler.
     *
     * @throws InvalidArgumentException
     */
    public function bindDirective(string $name, callable $handler): void
    {
        $this->directive($name, $handler, bind: true);
    }

    /**
     * Register a handler for custom directives.
     *
     * @throws InvalidArgumentException
     */
    public function directive(string $name, Closure $handler, bool $bind = false): void
    {
        if (! preg_match('/^\w+(?:::\w+)?$/x', $name)) {
            throw new InvalidArgumentException("The directive name [{$name}] is not valid. Directive names must only contain alphanumeric characters and underscores.");
        }

        $this->customDirectives[$name] = $bind ? $handler->bindTo($this, BladeCompiler::class) : $handler;
    }

    /**
     * Get the list of custom directives.
     */
    public function getCustomDirectives(): array
    {
        return $this->customDirectives;
    }

    /**
     * Indicate that the following callable should be used to prepare strings for compilation.
     */
    public function prepareStringsForCompilationUsing(callable $callback): static
    {
        $this->prepareStringsForCompilationUsing[] = $callback;

        return $this;
    }

    /**
     * Register a new precompiler.
     */
    public function precompiler(callable $precompiler): void
    {
        $this->precompilers[] = $precompiler;
    }

    /**
     * Execute the given callback using a custom echo format.
     */
    public function usingEchoFormat(string $format, callable $callback): string
    {
        $originalEchoFormat = $this->getEchoFormat();

        $this->setEchoFormat($format);

        try {
            $output = call_user_func($callback);
        } finally {
            $this->setEchoFormat($originalEchoFormat);
        }

        return $output;
    }

    /**
     * Set the echo format to be used by the compiler.
     */
    public function setEchoFormat(string $format): void
    {
        Context::set(static::ECHO_FORMAT_CONTEXT_KEY, $format);
    }

    /**
     * Get the echo format to be used by the compiler.
     */
    protected function getEchoFormat(): string
    {
        return Context::get(static::ECHO_FORMAT_CONTEXT_KEY, 'e(%s)');
    }

    /**
     * Set the "echo" format to double encode entities.
     */
    public function withDoubleEncoding(): void
    {
        $this->setEchoFormat('e(%s, true)');
    }

    /**
     * Set the "echo" format to not double encode entities.
     */
    public function withoutDoubleEncoding(): void
    {
        $this->setEchoFormat('e(%s, false)');
    }

    /**
     * Indicate that component tags should not be compiled.
     */
    public function withoutComponentTags(): void
    {
        $this->compilesComponentTags = false;
    }

    protected function pushFooter($footer)
    {
        $stack = Context::get(static::FOOTER_CONTEXT_KEY, []);
        $stack[] = $footer;
        Context::set(static::FOOTER_CONTEXT_KEY, $stack);
    }
}
