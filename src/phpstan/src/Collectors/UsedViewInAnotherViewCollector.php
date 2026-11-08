<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Support\ViewFileHelper;
use Hypervel\PHPStan\Support\ViewParser;
use Hypervel\View\ViewName;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\InlineHTML;
use PHPStan\DependencyInjection\AutowiredService;

use function array_merge;
use function preg_match_all;

use const PREG_SET_ORDER;

#[AutowiredService]
class UsedViewInAnotherViewCollector
{
    /** @see https://regex101.com/r/OyHHCY/1 */
    private const string VIEW_NAME_REGEX = '/@(extends|include(If|Unless|When|First)?)(\(.*?([\'"])(.*?)([\'"])([),]))/m';

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
    public function __construct(private ViewParser $viewParser, private ViewFileHelper $viewFileHelper)
    {
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
            $parserNodes = $this->viewParser->getNodes($viewFile);

            $usedViews = array_merge($usedViews, $this->processNodes($parserNodes));
        }

        return $usedViews;
    }

    /**
     * Get the views referenced by the given view nodes.
     *
     * @param Stmt[] $nodes
     *
     * @return list<string>
     */
    private function processNodes(array $nodes): array
    {
        $usedViews = [];

        foreach ($nodes as $node) {
            if (! $node instanceof InlineHTML) {
                continue;
            }

            preg_match_all(self::VIEW_NAME_REGEX, $node->value, $matches, PREG_SET_ORDER, 0);

            foreach ($matches as $match) {
                $usedViews[] = ViewName::normalize($match[5]);
            }

            $usedViews = array_merge($usedViews, $this->componentViews($node->value));
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
