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

use function array_diff;
use function array_filter;
use function array_unique;
use function collect;
use function iterator_to_array;
use function view;

/** @implements Rule<CollectedDataNode> */
#[Conditional(attribute: new AutowiredService, if: '%checkUnusedViews%')]
final class UnusedViewsRule implements Rule
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

        $allViews = iterator_to_array($this->viewFileHelper->getAllViewNames());

        $usedViews = self::filterExistingViews($factory, $usedViews);
        $allViews = self::filterExistingViews($factory, $allViews);

        $unusedViews = array_unique(array_diff($allViews, $usedViews));

        $errors = [];

        foreach ($unusedViews as $file) {
            $path = $finder->find($file);

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
     * Get the given views that the view factory can find.
     *
     * @param string[] $views
     *
     * @return string[]
     */
    private static function filterExistingViews(Factory $factory, array $views): array
    {
        return array_filter($views, static function (string $view) use ($factory): bool {
            return $factory->exists($view);
        });
    }
}
