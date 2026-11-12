<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Support\BladeScanner;
use Hypervel\PHPStan\Support\ViewFileHelper;
use Hypervel\PHPStan\Support\ViewParser;
use Hypervel\Support\Str;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\ViewName;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PHPStan\DependencyInjection\AutowiredService;

use function array_map;
use function array_merge;
use function array_unique;
use function preg_match_all;
use function str_contains;
use function str_starts_with;
use function strtolower;

#[AutowiredService]
class UsedViewInAnotherViewCollector
{
    use HasContainer;

    /**
     * The directives that render views, with the view parameters of the factory method each one compiles to, by name and position.
     *
     * Upstream matches the first quoted string after @extends and the @include directives,
     * so @includeWhen collected part of its condition, @includeFirst only its first view,
     * and @each none of its views.
     *
     * @var array<string, array<string, int>>
     */
    private const array VIEW_DIRECTIVES = [
        'component' => ['view' => 0],
        'componentfirst' => ['names' => 0],
        'each' => ['view' => 0, 'empty' => 3],
        'extends' => ['view' => 0],
        'extendsfirst' => ['views' => 0],
        'include' => ['view' => 0],
        'includefirst' => ['views' => 0],
        'includeif' => ['view' => 0],
        'includeisolated' => ['view' => 0],
        'includeunless' => ['view' => 1],
        'includewhen' => ['view' => 1],
    ];

    /**
     * The names of opening Blade component tags, such as `<x-alert>`, `<x-forms.input />` and
     * `<x-dashboard::panel>`, spelled as ComponentTagCompiler matches them.
     *
     * Upstream matches only the `x-` prefix and stops at `::`, so namespaced components lost their name.
     */
    private const string COMPONENT_TAG_REGEX = '/<\s*x[-:]([\w\-:.]+)/';

    /**
     * Create a new collector instance.
     */
    public function __construct(
        private ViewParser $viewParser,
        private BladeScanner $bladeScanner,
        private ViewFileHelper $viewFileHelper,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Get the views referenced from other views.
     *
     * @return list<string>
     */
    public function getUsedViews(): array
    {
        $usedViews = [];

        foreach ($this->viewFileHelper->getAllViewFilePaths() as $viewFile) {
            $usedViews = array_merge($usedViews, $this->processView($this->filesystem->get($viewFile)));
        }

        return $usedViews;
    }

    /**
     * Get the views referenced by the given view source.
     *
     * Only the text Blade compiles is read, following Blade's own passes, so escaped,
     * commented and verbatim directives and tags are not usages.
     *
     * @return list<string>
     */
    private function processView(string $source): array
    {
        $this->bladeScanner->setAside($source);

        $usedViews = [];

        foreach ($this->bladeScanner->text($source) as [$html]) {
            foreach ($this->bladeScanner->directives($html) as [$name, $arguments]) {
                $parameters = self::VIEW_DIRECTIVES[strtolower($name)] ?? null;

                if ($parameters !== null) {
                    $usedViews = array_merge($usedViews, $this->directiveViews($arguments, $parameters));
                }
            }

            $usedViews = array_merge($usedViews, $this->componentViews($html));
        }

        return $usedViews;
    }

    /**
     * Get the views named by literals in a view directive's arguments.
     *
     * @param array<string, int> $parameters
     * @return list<string>
     */
    private function directiveViews(string $arguments, array $parameters): array
    {
        $statement = $this->viewParser->parseCode("<?php f{$arguments};")[0] ?? null;

        if (! $statement instanceof Expression || ! $statement->expr instanceof FuncCall) {
            return [];
        }

        $usedViews = [];

        foreach ($parameters as $parameter => $position) {
            $argument = $statement->expr->getArg($parameter, $position)?->value;

            $names = $argument instanceof Array_
                ? array_map(static fn (ArrayItem $item): Expr => $item->value, $argument->items)
                : [$argument];

            foreach ($names as $name) {
                // renderEach() shows an empty value starting with raw| as text.
                if ($name instanceof String_ && ! str_starts_with($name->value, 'raw|')) {
                    $usedViews[] = ViewName::normalize($name->value);
                }
            }
        }

        return $usedViews;
    }

    /**
     * Get the views that Blade component tags in the given HTML may render.
     *
     * These are the views ComponentTagCompiler::componentClass() tries: a component alias naming
     * a view, then the anonymous component views in the components directory, the registered
     * anonymous component namespaces and the registered anonymous component paths. Each one
     * is collected, since which of them exists is decided by the rule. Upstream only collects
     * the components directory's view, so registered components were reported as unused.
     *
     * @return list<string>
     */
    private function componentViews(string $html): array
    {
        preg_match_all(self::COMPONENT_TAG_REGEX, $html, $matches);

        if ($matches[1] === []) {
            return [];
        }

        /** @var BladeCompiler $compiler */
        $compiler = $this->resolve('blade.compiler');
        $aliases = $compiler->getClassComponentAliases();
        $namespaces = $compiler->getAnonymousComponentNamespaces();
        $paths = $compiler->getAnonymousComponentPaths();
        $usedViews = [];

        foreach (array_unique($matches[1]) as $name) {
            // Blade compiles slots before components.
            if ($name === 'slot' || str_starts_with($name, 'slot:')) {
                continue;
            }

            if (isset($aliases[$name])) {
                $usedViews[] = $aliases[$name];
            }

            $candidates = [['components', $name]];

            foreach ($namespaces as $prefix => $directory) {
                if (str_starts_with($name, $prefix . '::')) {
                    $candidates[] = [$directory, Str::after($name, $prefix . '::')];
                }
            }

            foreach ($candidates as [$directory, $componentName]) {
                $usedViews = array_merge($usedViews, $this->anonymousViews($this->guessViewName($componentName, $directory), $componentName));
            }

            foreach ($paths as $path) {
                $prefixed = str_starts_with($name, $path['prefix'] . '::');

                if (! $prefixed && str_contains($name, '::')) {
                    continue;
                }

                $componentName = $prefixed ? Str::after($name, '::') : $name;
                $usedViews = array_merge($usedViews, $this->anonymousViews($path['prefixHash'] . '::' . $componentName, $componentName));
            }
        }

        return $usedViews;
    }

    /**
     * Get the views an anonymous component's view name may refer to: the view itself, its index view, or the view named like its directory.
     *
     * @return list<string>
     */
    private function anonymousViews(string $view, string $componentName): array
    {
        return [$view, $view . '.index', $view . '.' . Str::afterLast(Str::afterLast($componentName, '.'), ':')];
    }

    /**
     * Get the view name of an anonymous component in the given directory, as ComponentTagCompiler::guessViewName() does.
     */
    private function guessViewName(string $name, string $directory): string
    {
        $prefix = Str::finish($directory, '.');

        return str_contains($name, '::')
            ? Str::replaceFirst('::', '::' . $prefix, $name)
            : $prefix . $name;
    }
}
