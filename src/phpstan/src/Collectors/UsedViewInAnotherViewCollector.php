<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\Support\BladeScanner;
use Hypervel\PHPStan\Support\ViewFileHelper;
use Hypervel\PHPStan\Support\ViewParser;
use Hypervel\View\ViewName;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\InlineHTML;
use PhpParser\NodeFinder;
use PHPStan\DependencyInjection\AutowiredService;

use function array_map;
use function array_merge;
use function preg_match_all;
use function str_starts_with;
use function strtolower;

use const PREG_SET_ORDER;

#[AutowiredService]
class UsedViewInAnotherViewCollector
{
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
     * Anonymous Blade component tags — `<x-alert>`, `<x-forms.input />`, `</x-card>` — which resolve to the
     * `components.<name>` view (or its `.index` variant). `<x-slot …>` is a slot, not a component.
     *
     * @see https://hypervel.org/docs/blade#anonymous-components
     */
    private const string COMPONENT_TAG_REGEX = '/<\/?x-([a-zA-Z0-9][a-zA-Z0-9._-]*)/';

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

        foreach ((new NodeFinder)->findInstanceOf($this->viewParser->parseCode($source), InlineHTML::class) as $node) {
            foreach ($this->bladeScanner->directives($node->value) as [$name, $arguments]) {
                $parameters = self::VIEW_DIRECTIVES[strtolower($name)] ?? null;

                if ($parameters !== null) {
                    $usedViews = array_merge($usedViews, $this->directiveViews($arguments, $parameters));
                }
            }

            $usedViews = array_merge($usedViews, $this->componentViews($node->value));
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
     * Get the views referenced through anonymous Blade component tags in the given HTML.
     *
     * @return list<string>
     */
    private function componentViews(string $html): array
    {
        preg_match_all(self::COMPONENT_TAG_REGEX, $html, $matches, PREG_SET_ORDER, 0);

        $usedViews = [];

        foreach ($matches as $match) {
            $name = $match[1];

            if ($name === 'slot') {
                continue;
            }

            // The `resources/views/components` directory is commonly registered as its own view path, where
            // an anonymous component is named by its short path (`alert`); the same file is also reachable
            // as `components.alert` under `resources/views`. A component can additionally be an index file
            // (`alert/index.blade.php`). Emit every form so whichever naming the project uses is matched.
            foreach ([$name, 'components.' . $name] as $view) {
                $usedViews[] = $view;
                $usedViews[] = $view . '.index';
            }
        }

        return $usedViews;
    }
}
