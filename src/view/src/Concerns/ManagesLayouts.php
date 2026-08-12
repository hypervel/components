<?php

declare(strict_types=1);

namespace Hypervel\View\Concerns;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\View\View;
use Hypervel\Support\Str;
use InvalidArgumentException;

trait ManagesLayouts
{
    /**
     * Context key for finished, captured sections.
     */
    protected const SECTIONS_CONTEXT_KEY = '__view.sections';

    /**
     * Context key for the stack of in-progress sections.
     */
    protected const SECTION_STACK_CONTEXT_KEY = '__view.section_stack';

    /**
     * The parent placeholder salt for the worker.
     */
    protected static ?string $parentPlaceholderSalt = null;

    /**
     * Start injecting content into a section.
     */
    public function startSection(string $section, string|View|null $content = null): void
    {
        if ($content === null) {
            if (ob_start()) {
                $sectionStack = CoroutineContext::get(static::SECTION_STACK_CONTEXT_KEY, []);
                $sectionStack[] = $section;
                CoroutineContext::set(static::SECTION_STACK_CONTEXT_KEY, $sectionStack);
            }
        } else {
            // Coroutine contexts copy object references, so store immutable section content.
            $this->extendSection($section, $content instanceof View ? $content->render() : e($content));
        }
    }

    /**
     * Inject inline content into a section.
     */
    public function inject(string $section, string $content): void
    {
        $this->startSection($section, $content);
    }

    /**
     * Stop injecting content into a section and return its contents.
     */
    public function yieldSection(): string
    {
        $sectionStack = CoroutineContext::get(static::SECTION_STACK_CONTEXT_KEY, []);

        if (empty($sectionStack)) {
            return '';
        }

        return $this->yieldContent($this->stopSection());
    }

    /**
     * Stop injecting content into a section.
     *
     * @throws InvalidArgumentException
     */
    public function stopSection(bool $overwrite = false): string
    {
        $sectionStack = CoroutineContext::get(static::SECTION_STACK_CONTEXT_KEY, []);

        if (empty($sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($sectionStack);
        CoroutineContext::set(static::SECTION_STACK_CONTEXT_KEY, $sectionStack);

        if ($overwrite) {
            $sections = CoroutineContext::get(static::SECTIONS_CONTEXT_KEY, []);
            $sections[$last] = ob_get_clean();
            CoroutineContext::set(static::SECTIONS_CONTEXT_KEY, $sections);
        } else {
            $this->extendSection($last, ob_get_clean());
        }

        return $last;
    }

    /**
     * Stop injecting content into a section and append it.
     *
     * @throws InvalidArgumentException
     */
    public function appendSection(): string
    {
        $sectionStack = CoroutineContext::get(static::SECTION_STACK_CONTEXT_KEY, []);

        if (empty($sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($sectionStack);
        CoroutineContext::set(static::SECTION_STACK_CONTEXT_KEY, $sectionStack);

        $sections = CoroutineContext::get(static::SECTIONS_CONTEXT_KEY, []);
        if (isset($sections[$last])) {
            $sections[$last] .= ob_get_clean();
        } else {
            $sections[$last] = ob_get_clean();
        }
        CoroutineContext::set(static::SECTIONS_CONTEXT_KEY, $sections);

        return $last;
    }

    /**
     * Append content to a given section.
     */
    protected function extendSection(string $section, string $content): void
    {
        $sections = CoroutineContext::get(static::SECTIONS_CONTEXT_KEY, []);

        if (isset($sections[$section])) {
            $content = str_replace(static::parentPlaceholder($section), $content, $sections[$section]);
        }

        $sections[$section] = $content;
        CoroutineContext::set(static::SECTIONS_CONTEXT_KEY, $sections);
    }

    /**
     * Get the string contents of a section.
     */
    public function yieldContent(string $section, string|View $default = ''): string
    {
        $sections = CoroutineContext::get(static::SECTIONS_CONTEXT_KEY, []);
        $sectionContent = isset($sections[$section])
            ? $sections[$section]
            : ($default instanceof View ? $default->render() : e($default));

        $sectionContent = str_replace('@@parent', '--parent--holder--', $sectionContent);

        return str_replace(
            '--parent--holder--',
            '@parent',
            str_replace(static::parentPlaceholder($section), '', $sectionContent)
        );
    }

    /**
     * Get the parent placeholder for the given section.
     */
    public static function parentPlaceholder(string $section = ''): string
    {
        // This pure value is cheaper to recompute than to retain in a worker-lived map.
        return '##parent-placeholder-' . hash('xxh128', static::parentPlaceholderSalt() . $section) . '##';
    }

    /**
     * Get the parent placeholder salt.
     */
    protected static function parentPlaceholderSalt(): string
    {
        return static::$parentPlaceholderSalt ??= Str::random(40);
    }

    /**
     * Check if section exists.
     */
    public function hasSection(string $name): bool
    {
        $sections = CoroutineContext::get(static::SECTIONS_CONTEXT_KEY, []);
        return array_key_exists($name, $sections);
    }

    /**
     * Check if section does not exist.
     */
    public function sectionMissing(string $name): bool
    {
        return ! $this->hasSection($name);
    }

    /**
     * Get the contents of a section.
     */
    public function getSection(string $name, ?string $default = null): mixed
    {
        $sections = CoroutineContext::get(static::SECTIONS_CONTEXT_KEY, []);
        return $sections[$name] ?? $default;
    }

    /**
     * Get the entire array of sections.
     */
    public function getSections(): array
    {
        return CoroutineContext::get(static::SECTIONS_CONTEXT_KEY, []);
    }

    /**
     * Flush all of the sections.
     */
    public function flushSections(): void
    {
        CoroutineContext::set(static::SECTIONS_CONTEXT_KEY, []);
        CoroutineContext::set(static::SECTION_STACK_CONTEXT_KEY, []);
    }
}
