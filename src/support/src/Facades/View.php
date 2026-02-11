<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hyperf\ViewEngine\Contract\FactoryInterface;

/**
 * @method static \Hyperf\ViewEngine\Contract\ViewInterface file(string $path, array|\Hypervel\Contracts\Support\Arrayable $data = [], array $mergeData = [])
 * @method static \Hyperf\ViewEngine\Contract\ViewInterface make(string $view, array|\Hypervel\Contracts\Support\Arrayable $data = [], array $mergeData = [])
 * @method static \Hyperf\ViewEngine\Contract\ViewInterface first(array $views, \Hypervel\Contracts\Support\Arrayable|array $data = [], array $mergeData = [])
 * @method static string renderWhen(bool $condition, string $view, \Hypervel\Contracts\Support\Arrayable|array $data = [], array $mergeData = [])
 * @method static string renderUnless(bool $condition, string $view, \Hypervel\Contracts\Support\Arrayable|array $data = [], array $mergeData = [])
 * @method static string renderEach(string $view, array $data, string $iterator, string $empty = 'raw|')
 * @method static bool exists(string $view)
 * @method static \Hyperf\ViewEngine\Contract\EngineInterface getEngineFromPath(string $path)
 * @method static mixed share(array|string $key, null|mixed $value = null)
 * @method static void incrementRender()
 * @method static void decrementRender()
 * @method static bool doneRendering()
 * @method static bool hasRenderedOnce(string $id)
 * @method static void markAsRenderedOnce(string $id)
 * @method static void addLocation(string $location)
 * @method static \Hyperf\ViewEngine\Factory addNamespace(string $namespace, array|string $hints)
 * @method static \Hyperf\ViewEngine\Factory prependNamespace(string $namespace, array|string $hints)
 * @method static \Hyperf\ViewEngine\Factory replaceNamespace(string $namespace, array|string $hints)
 * @method static void addExtension(string $extension, string $engine, \Closure|null $resolver = null)
 * @method static void flushState()
 * @method static void flushStateIfDoneRendering()
 * @method static array getExtensions()
 * @method static \Hyperf\ViewEngine\Contract\EngineResolverInterface getEngineResolver()
 * @method static \Hyperf\ViewEngine\Contract\FinderInterface getFinder()
 * @method static void setFinder(\Hyperf\ViewEngine\Contract\FinderInterface $finder)
 * @method static void flushFinderCache()
 * @method static \Psr\EventDispatcher\EventDispatcherInterface getDispatcher()
 * @method static void setDispatcher(\Psr\EventDispatcher\EventDispatcherInterface $events)
 * @method static \Hypervel\Contracts\Container\Container getContainer()
 * @method static void setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static mixed shared(string $key, mixed $default = null)
 * @method static array getShared()
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void startComponent(\Closure|\Hyperf\ViewEngine\Contract\Htmlable|string|\Hyperf\ViewEngine\View $view, array $data = [])
 * @method static void startComponentFirst(array $names, array $data = [])
 * @method static string renderComponent()
 * @method static void slot(string $name, null|string $content = null)
 * @method static void endSlot()
 * @method static array creator(array|string $views, \Closure|string $callback)
 * @method static array composers(array $composers)
 * @method static array composer(array|string $views, \Closure|string $callback)
 * @method static void callComposer(\Hyperf\ViewEngine\Contract\ViewInterface $view)
 * @method static void callCreator(\Hyperf\ViewEngine\Contract\ViewInterface $view)
 * @method static void startSection(string $section, null|string|\Hyperf\ViewEngine\Contract\ViewInterface $content = null)
 * @method static void inject(string $section, string $content)
 * @method static string yieldSection()
 * @method static string stopSection(bool $overwrite = false)
 * @method static string appendSection()
 * @method static string yieldContent(string $section, \Hyperf\ViewEngine\Contract\ViewInterface|string $default = '')
 * @method static string parentPlaceholder(string $section = '')
 * @method static bool hasSection(string $name)
 * @method static bool sectionMissing(string $name)
 * @method static mixed getSection(string $name, null|string $default = null)
 * @method static array getSections()
 * @method static void flushSections()
 * @method static void addLoop(null|array|\Countable $data)
 * @method static void incrementLoopIndices()
 * @method static void popLoop()
 * @method static null|\stdClass|void getLastLoop()
 * @method static array getLoopStack()
 * @method static void startPush(string $section, string $content = '')
 * @method static string stopPush()
 * @method static void startPrepend(string $section, string $content = '')
 * @method static string stopPrepend()
 * @method static string yieldPushContent(string $section, string $default = '')
 * @method static void flushStacks()
 * @method static void startTranslation(array $replacements = [])
 * @method static string renderTranslation()
 *
 * @see \Hyperf\ViewEngine\Factory
 */
class View extends Facade
{
    protected static function getFacadeAccessor()
    {
        return FactoryInterface::class;
    }
}
