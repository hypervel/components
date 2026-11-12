<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use Generator;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\View\Factory as ViewFactory;
use Hypervel\View\FileViewFinder;
use Hypervel\View\ViewFinderInterface;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\File\FileHelper;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

use function array_map;
use function array_merge;
use function array_values;
use function is_dir;
use function iterator_to_array;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

#[AutowiredService]
class ViewFileHelper
{
    use HasContainer;

    /**
     * The directories scanned for view usages.
     *
     * @var list<string>
     */
    private array $viewDirectories;

    /**
     * The directories whose views can be reported as unused, named relative to the directory.
     *
     * @var list<string>
     */
    private array $namedViewDirectories;

    /**
     * Create a new view file helper instance.
     *
     * Configured directories replace the booted finder's paths and namespace hints for
     * the unused-view rule; the missing-translation rule always scans the finder's paths,
     * as upstream does. Upstream also names the views in namespace hint directories as
     * though they were root views. Those views are only scanned for usages here: framework
     * and package views, such as pagination::tailwind, are rendered by names that no
     * literal call shows.
     *
     * @param list<non-empty-string> $viewDirectories
     */
    public function __construct(
        #[AutowiredParameter]
        array $viewDirectories,
        private FileHelper $fileHelper,
        private RuntimeInputs $runtimeInputs,
    ) {
        $hintDirectories = [];

        if ($viewDirectories === []) {
            $finder = $this->finder();
            $viewDirectories = $finder->getPaths();

            // Hypervel's finder contract has no namespace hints; the standard finder does.
            if ($finder instanceof FileViewFinder) {
                $hintDirectories = array_merge(...array_values($finder->getHints()));
            }
        }

        // View names are derived from these directories, so a relative directory is
        // resolved once to match the paths of the files found beneath it.
        $this->namedViewDirectories = array_map($this->fileHelper->absolutizePath(...), $viewDirectories);
        $this->viewDirectories = [
            ...$this->namedViewDirectories,
            ...array_map($this->fileHelper->absolutizePath(...), $hintDirectories),
        ];
    }

    /**
     * Track the root view directories.
     *
     * The result cache runs the rule again when a view file in the root view directories changes.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function trackRootViewDirectories(Scope $scope): void
    {
        $this->runtimeInputs->track($scope);
        $this->runtimeInputs->trackLinkedDirectories($scope, array_map($this->fileHelper->absolutizePath(...), $this->finder()->getPaths()), '*.blade.php');
    }

    /**
     * Track every view directory.
     *
     * The result cache runs the rule again when a view file in any of the view directories changes.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function trackAllViewDirectories(Scope $scope): void
    {
        $this->runtimeInputs->track($scope);
        $this->runtimeInputs->trackLinkedDirectories($scope, $this->viewDirectories, '*.blade.php');
    }

    /**
     * Get the view files in the root view directories.
     *
     * @return Generator<int, string, void, void>
     */
    public function getRootViewFilePaths(): Generator
    {
        foreach ($this->finder()->getPaths() as $path) {
            foreach ($this->getViews($path) as $view) {
                yield $view->getPathname();
            }
        }
    }

    /**
     * Get the view files in every view directory.
     *
     * @return Generator<int, string, void, void>
     */
    public function getAllViewFilePaths(): Generator
    {
        foreach ($this->viewDirectories as $viewDirectory) {
            foreach ($this->getViews($viewDirectory) as $view) {
                yield $view->getPathname();
            }
        }
    }

    /**
     * Get the names of the views that can be reported as unused.
     *
     * @return Generator<int, string, void, void>
     */
    public function getAllViewNames(): Generator
    {
        foreach ($this->namedViewDirectories as $viewDirectory) {
            $prefix = rtrim($viewDirectory, '/\\') . DIRECTORY_SEPARATOR;

            foreach ($this->getViews($viewDirectory) as $view) {
                $relativePath = substr($view->getPathname(), strlen($prefix));

                // Vendor overrides and error pages are rendered through their view namespaces.
                // Upstream matches these directory names anywhere in the absolute path.
                if (str_starts_with($relativePath, 'vendor' . DIRECTORY_SEPARATOR) || str_starts_with($relativePath, 'errors' . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                yield str_replace([DIRECTORY_SEPARATOR, '.blade.php'], ['.', ''], $relativePath);
            }
        }
    }

    /**
     * Get the Blade view files beneath the given directory.
     *
     * @return SplFileInfo[]
     */
    protected function getViews(string $path): array
    {
        $absolutePath = $this->fileHelper->absolutizePath($path);

        if (! is_dir($absolutePath)) {
            return [];
        }

        // The view finder finds views through symlinked directories too.
        return iterator_to_array(
            new RegexIterator(
                new RecursiveIteratorIterator(RecursiveDirectoryIterator::followingLinks($absolutePath)),
                '/\.blade\.php$/i',
            ),
        );
    }

    /**
     * Get the booted application's view finder.
     */
    protected function finder(): ViewFinderInterface
    {
        /** @var ViewFactory $factory The canonical view binding is the standard factory. */
        $factory = $this->resolve('view');

        return $factory->getFinder();
    }
}
