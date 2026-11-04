<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Collectors\UsedEmailAlternativeSyntaxViewCollector;
use Hypervel\PHPStan\Collectors\UsedEmailSendViewCollector;
use Hypervel\PHPStan\Collectors\UsedEmailViewCollector;
use Hypervel\PHPStan\Collectors\UsedRouteFacadeViewCollector;
use Hypervel\PHPStan\Collectors\UsedViewFacadeMakeCollector;
use Hypervel\PHPStan\Collectors\UsedViewFunctionCollector;
use Hypervel\PHPStan\Collectors\UsedViewInAnotherViewCollector;
use Hypervel\PHPStan\Collectors\UsedViewMakeCollector;
use Hypervel\PHPStan\Rules\UnusedViewsRule;
use Hypervel\PHPStan\Support\ViewFileHelper;
use Hypervel\PHPStan\Support\ViewParser;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\File\FileHelper;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function dirname;

/** @extends RuleTestCase<UnusedViewsRule> */
class UnusedViewsRuleTest extends RuleTestCase
{
    /** @var array{string, int, string} */
    private const array UNUSED_VIEW_ERROR = [
        'No usage of this view was found.',
        0,
        'Views rendered with a dynamic name, or only by code outside the analyzed paths, are not detected.',
    ];

    /** @var list<non-empty-string> */
    private array $viewDirectories = [
        __DIR__ . '/../application/resources/views/',
        __DIR__ . '/../../../src/foundation/src/Exceptions/views',
    ];

    private ?string $workingDirectory = null;

    protected function getRule(): Rule
    {
        $viewParser = new ViewParser($this->getContainer()->getService('currentPhpVersionSimpleDirectParser'));
        $fileHelper = $this->workingDirectory === null ? $this->getFileHelper() : new FileHelper($this->workingDirectory);
        $viewFileHelper = new ViewFileHelper($this->viewDirectories, $fileHelper);

        return new UnusedViewsRule(new UsedViewInAnotherViewCollector($viewParser, $viewFileHelper), $viewFileHelper);
    }

    /** @return array<Collector<Node, mixed>> */
    protected function getCollectors(): array
    {
        return [
            new UsedViewFunctionCollector($this->createReflectionProvider()),
            new UsedEmailViewCollector,
            new UsedEmailAlternativeSyntaxViewCollector,
            new UsedEmailSendViewCollector,
            new UsedViewMakeCollector,
            new UsedViewFacadeMakeCollector,
            new UsedRouteFacadeViewCollector,
        ];
    }

    public function testRule(): void
    {
        // `unused.blade.php` and `components/orphan.blade.php` are referenced nowhere. The
        // `components/alert.blade.php` view IS used, but only through the `<x-alert>` Blade component tag
        // in `index.blade.php` — so it must not be reported (regression guard for component-tag detection).
        // The translation rule's `translation-lookups.blade.php` fixture is referenced nowhere either.
        $this->analyse([__DIR__ . '/data/FooController.php'], [
            self::UNUSED_VIEW_ERROR,
            self::UNUSED_VIEW_ERROR,
            self::UNUSED_VIEW_ERROR,
        ]);
    }

    public function testNamespacedViewFunctionIsNotHypervelView(): void
    {
        $this->analyse([
            __DIR__ . '/data/FooController.php',
            __DIR__ . '/data/NamespacedViewFunction.php',
        ], [
            self::UNUSED_VIEW_ERROR,
            self::UNUSED_VIEW_ERROR,
            self::UNUSED_VIEW_ERROR,
        ]);
    }

    public function testDiscoveredViewDirectories(): void
    {
        // The booted finder's namespace hints, such as pagination's, are scanned for usages but not reported.
        $this->viewDirectories = [];

        $this->analyse([__DIR__ . '/data/FooController.php'], [
            self::UNUSED_VIEW_ERROR,
            self::UNUSED_VIEW_ERROR,
            self::UNUSED_VIEW_ERROR,
        ]);
    }

    public function testRelativeViewDirectories(): void
    {
        // The test container's own working directory is PHPStan's package root.
        $this->workingDirectory = dirname(__DIR__, 3);
        $this->viewDirectories = ['tests/PHPStan/application/resources/views'];

        $this->analyse([__DIR__ . '/data/FooController.php'], [
            self::UNUSED_VIEW_ERROR,
            self::UNUSED_VIEW_ERROR,
            self::UNUSED_VIEW_ERROR,
        ]);
    }

    public function testTracksViewDirectories(): void
    {
        $tracked = [];
        $scope = $this->createStubForIntersectionOfInterfaces([Scope::class, DependencyTracker::class]);
        $scope->method('trackDirectoryDependency')->willReturnCallback(static function (string $directory, string $pattern) use (&$tracked): void {
            $tracked[] = [$directory, $pattern];
        });

        $this->getRule()->processNode(new CollectedDataNode([], false), $scope);

        $this->assertSame([
            [__DIR__ . '/../application/resources/views/', '*.blade.php'],
            [__DIR__ . '/../../../src/foundation/src/Exceptions/views', '*.blade.php'],
        ], $tracked);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../../src/phpstan/extension.neon',
        ];
    }
}
