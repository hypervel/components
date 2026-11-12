<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Collectors\UsedEmailAlternativeSyntaxViewCollector;
use Hypervel\PHPStan\Collectors\UsedEmailSendViewCollector;
use Hypervel\PHPStan\Collectors\UsedEmailViewCollector;
use Hypervel\PHPStan\Collectors\UsedRouteFacadeViewCollector;
use Hypervel\PHPStan\Collectors\UsedViewFacadeMakeCollector;
use Hypervel\PHPStan\Collectors\UsedViewFunctionCollector;
use Hypervel\PHPStan\Collectors\UsedViewInAnotherViewCollector;
use Hypervel\PHPStan\Collectors\UsedViewMakeCollector;
use Hypervel\PHPStan\Support\ViewFileHelper;
use Hypervel\View\Factory;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

use function array_unique;
use function collect;
use function iterator_to_array;
use function realpath;
use function view;

/** @implements Rule<CollectedDataNode> */
#[Conditional(attribute: new AutowiredService, if: '%checkUnusedViews%')]
class UnusedViewsRule implements Rule
{
    /** @var null|list<string> */
    private ?array $viewsUsedInOtherViews = null;

    /**
     * Create a new rule instance.
     */
    public function __construct(private UsedViewInAnotherViewCollector $usedViewInAnotherViewCollector, private ViewFileHelper $viewFileHelper)
    {
    }

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * Report views without a statically discovered usage.
     *
     * @param DependencyTracker&Scope $scope
     * @return RuleError[]
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // The rule reads the view files, so the result cache can reuse its errors only while they stay the same.
        $this->viewFileHelper->trackAllViewDirectories($scope);

        if ($this->viewsUsedInOtherViews === null) {
            $this->viewsUsedInOtherViews = $this->usedViewInAnotherViewCollector->getUsedViews();
        }

        $usedViews = collect([
            $node->get(UsedViewFunctionCollector::class),
            $node->get(UsedEmailViewCollector::class),
            $node->get(UsedEmailAlternativeSyntaxViewCollector::class),
            $node->get(UsedEmailSendViewCollector::class),
            $node->get(UsedViewMakeCollector::class),
            $node->get(UsedViewFacadeMakeCollector::class),
            $node->get(UsedRouteFacadeViewCollector::class),
            $this->viewsUsedInOtherViews,
        ])->flatten()->unique()->toArray();

        /** @var Factory $factory */
        $factory = view();
        $finder = $factory->getFinder();

        // Unlike upstream, views are compared by file: a view can be found under several names, such
        // as an anonymous component in a registered path, whose name holds the path's hashed prefix.
        $usedFiles = [];

        foreach ($usedViews as $view) {
            if ($factory->exists($view)) {
                $usedFiles[self::realPath($finder->find($view))] = true;
            }
        }

        $errors = [];

        foreach (array_unique(iterator_to_array($this->viewFileHelper->getAllViewNames(), false)) as $view) {
            if (! $factory->exists($view)) {
                continue;
            }

            $path = $finder->find($view);
            $file = self::realPath($path);

            if (isset($usedFiles[$file])) {
                continue;
            }

            // A file reached under another name is reported once.
            $usedFiles[$file] = true;

            // Upstream says the view is not used, but only usages with literal view names
            // in the analyzed code and views can be found.
            $errors[] = RuleErrorBuilder::message('No usage of this view was found.')
                ->tip('Views rendered with a dynamic name, or only by code outside the analyzed paths, are not detected.')
                ->file($path)
                ->line(0)
                ->identifier('hypervel.unusedViews')
                ->build();
        }

        return $errors;
    }

    /**
     * Get the file a found view path refers to, which a registered path can spell another way.
     */
    private static function realPath(string $path): string
    {
        return realpath($path) ?: $path;
    }
}
