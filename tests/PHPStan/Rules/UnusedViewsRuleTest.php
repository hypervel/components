<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\Collectors\UsedEmailAlternativeSyntaxViewCollector;
use Hypervel\PHPStan\Collectors\UsedEmailSendViewCollector;
use Hypervel\PHPStan\Collectors\UsedEmailViewCollector;
use Hypervel\PHPStan\Collectors\UsedRouteFacadeViewCollector;
use Hypervel\PHPStan\Collectors\UsedViewFacadeMakeCollector;
use Hypervel\PHPStan\Collectors\UsedViewFunctionCollector;
use Hypervel\PHPStan\Collectors\UsedViewInAnotherViewCollector;
use Hypervel\PHPStan\Collectors\UsedViewMakeCollector;
use Hypervel\PHPStan\Rules\UnusedViewsRule;
use Hypervel\PHPStan\RuntimeInputsResultCacheValueExtension;
use Hypervel\PHPStan\Support\BladeScanner;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\PHPStan\Support\ViewFileHelper;
use Hypervel\PHPStan\Support\ViewParser;
use Hypervel\Testing\ParallelTesting;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Error;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\File\FileHelper;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function array_map;
use function dirname;
use function file_put_contents;
use function mkdir;
use function resource_path;
use function sort;
use function str_replace;
use function symlink;
use function touch;
use function view;

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
        $viewFileHelper = new ViewFileHelper($this->viewDirectories, $fileHelper, self::getContainer()->getByType(RuntimeInputs::class));

        return new UnusedViewsRule(new UsedViewInAnotherViewCollector($viewParser, new BladeScanner, $viewFileHelper, new Filesystem), $viewFileHelper);
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
        // `components/card/card.blade.php` is used the same way, through `<x-card>`.
        // The translation rule's `translation-lookups.blade.php` fixture is referenced nowhere either, and
        // `blade-directives/index.blade.php` names three views only in escaped, commented or verbatim text.
        $viewsPath = resource_path('views') . '/';

        // Hypervel: also compares the reported files, which upstream's analyse() does not, in the same analysis.
        $reports = array_map(
            static fn (Error $error): array => [str_replace($viewsPath, '', $error->getFile()), $error->getMessage(), $error->getLine(), $error->getTip()],
            $this->gatherAnalyserErrors([__DIR__ . '/data/FooController.php']),
        );

        sort($reports);

        $this->assertSame(array_map(static fn (string $view): array => [$view, ...self::UNUSED_VIEW_ERROR], [
            'blade-directives/commented.blade.php',
            'blade-directives/escaped.blade.php',
            'blade-directives/verbatim.blade.php',
            'components/orphan.blade.php',
            'translation-lookups.blade.php',
            'unused.blade.php',
        ]), $reports);
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
            self::UNUSED_VIEW_ERROR,
            self::UNUSED_VIEW_ERROR,
            self::UNUSED_VIEW_ERROR,
        ]);
    }

    public function testTracksViewDirectories(): void
    {
        $tracked = [];
        $scope = $this->createStubForIntersectionOfInterfaces([Scope::class, DependencyTracker::class]);
        $scope->method('trackValueDependency')->willReturnCallback(static function (string $extension, string $key) use (&$tracked): void {
            $tracked[] = [$extension, $key];
        });

        $this->getRule()->processNode(new CollectedDataNode([], false), $scope);

        $this->assertContains([
            RuntimeInputsResultCacheValueExtension::class,
            self::getContainer()->getByType(RuntimeInputsResultCacheValueExtension::class)->createKey(linkedDirectories: [
                __DIR__ . '/../application/resources/views' => '*.blade.php',
                __DIR__ . '/../../../src/foundation/src/Exceptions/views' => '*.blade.php',
            ]),
        ], $tracked);
    }

    public function testViewsInSymlinkedDirectories(): void
    {
        $root = ParallelTesting::tempDir('PHPStanUnusedViewsRule');
        $finder = view()->getFinder();
        $paths = $finder->getPaths();

        (new Filesystem)->deleteDirectory($root);
        mkdir($root . '/views', 0777, true);
        mkdir($root . '/shared');
        file_put_contents($root . '/shared/card.blade.php', "@include('shared.badge')\n");
        touch($root . '/shared/badge.blade.php');
        touch($root . '/shared/orphan.blade.php');

        // The finder finds shared.card through the link, which links back to the views it is inside.
        symlink($root . '/shared', $root . '/views/shared');
        symlink($root . '/views', $root . '/shared/loop');

        $this->viewDirectories = [$root . '/views'];
        $finder->addLocation($root . '/views');

        try {
            $reports = array_map(
                static fn (Error $error): string => str_replace($root . '/views/', '', $error->getFile()),
                $this->gatherAnalyserErrors([__DIR__ . '/data/FooController.php']),
            );

            sort($reports);

            $this->assertSame(['shared/card.blade.php', 'shared/orphan.blade.php'], $reports);
        } finally {
            $finder->setPaths($paths);
            $finder->flush();
            (new Filesystem)->deleteDirectory($root);
        }
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../../src/phpstan/extension.neon',
        ];
    }
}
