<?php

declare(strict_types=1);

namespace Hypervel\View\Concerns;

use Hypervel\Context\Context;
use Hypervel\Support\Str;
use Hypervel\View\Contracts\View;
use InvalidArgumentException;

trait ManagesLayouts
{
    /**
     * Context key for finished, captured sections.
     */
    protected const SECTIONS_CONTEXT_KEY = 'sections';

    /**
     * Context key for the stack of in-progress sections.
     */
    protected const SECTION_STACK_CONTEXT_KEY = 'section_stack';

    /**
     * Context key for the parent placeholder.
     */
    protected const PARENT_PLACEHOLDER_CONTEXT_KEY = 'parent_placeholder';

    /**
     * Start injecting content into a section.
     */
    public function startSection(string $section, string|View|null $content = null): void
    {
        if ($content === null) {
            if (ob_start()) {
                $sectionStack = Context::get(static::SECTION_STACK_CONTEXT_KEY, []);
                $sectionStack[] = $section;
                Context::set(static::SECTION_STACK_CONTEXT_KEY, $sectionStack);
            }
        } else {
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
        $sectionStack = Context::get(static::SECTION_STACK_CONTEXT_KEY, []);

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
        $sectionStack = Context::get(static::SECTION_STACK_CONTEXT_KEY, []);

        if (empty($sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($sectionStack);
        Context::set(static::SECTION_STACK_CONTEXT_KEY, $sectionStack);

        if ($overwrite) {
            $sections = Context::get(static::SECTIONS_CONTEXT_KEY, []);
            $sections[$last] = ob_get_clean();
            Context::set(static::SECTIONS_CONTEXT_KEY, $sections);
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
        $sectionStack = Context::get(static::SECTION_STACK_CONTEXT_KEY, []);

        if (empty($sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($sectionStack);
        Context::set(static::SECTION_STACK_CONTEXT_KEY, $sectionStack);

        $sections = Context::get(static::SECTIONS_CONTEXT_KEY, []);
        if (isset($sections[$last])) {
            $sections[$last] .= ob_get_clean();
        } else {
            $sections[$last] = ob_get_clean();
        }
        Context::set(static::SECTIONS_CONTEXT_KEY, $sections);

        return $last;
    }

    /**
     * Append content to a given section.
     */
    protected function extendSection(string $section, string $content): void
    {
        $sections = Context::get(static::SECTIONS_CONTEXT_KEY, []);

        if (isset($sections[$section])) {
            $content = str_replace($this->getParentPlaceholder($section), $content, $sections[$section]);
        }

        $sections[$section] = $content;
        Context::set(static::SECTIONS_CONTEXT_KEY, $sections);
    }

    /**
     * Get the string contents of a section.
     */
    public function yieldContent(string $section, string|View $default = ''): string
    {
        $sectionContent = $default instanceof View ? $default->render() : e($default);

        $sections = Context::get(static::SECTIONS_CONTEXT_KEY, []);
        if (isset($sections[$section])) {
            $sectionContent = $sections[$section];
        }

        $sectionContent = str_replace('@@parent', '--parent--holder--', $sectionContent);

        return str_replace(
            '--parent--holder--',
            '@parent',
            str_replace($this->getParentPlaceholder($section), '', $sectionContent)
        );
    }

    /**
     * Get the parent placeholder for the current request.
     */
    public function getParentPlaceholder(string $section = ''): string
    {
        $parentPlaceholder = Context::get(static::PARENT_PLACEHOLDER_CONTEXT_KEY, []);

        if (! isset($parentPlaceholder[$section])) {
            $salt = Str::random(40);
            $parentPlaceholder[$section] = '##parent-placeholder-' . hash('xxh128', $salt . $section) . '##';
            Context::set(static::PARENT_PLACEHOLDER_CONTEXT_KEY, $parentPlaceholder);
        }

        return $parentPlaceholder[$section];
    }

    /**
     * Check if section exists.
     */
    public function hasSection(string $name): bool
    {
        $sections = Context::get(static::SECTIONS_CONTEXT_KEY, []);
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
        $sections = Context::get(static::SECTIONS_CONTEXT_KEY, []);
        return $sections[$name] ?? $default;
    }

    /**
     * Get the entire array of sections.
     */
    public function getSections(): array
    {
        return Context::get(static::SECTIONS_CONTEXT_KEY, []);
    }

    /**
     * Flush all of the sections.
     */
    public function flushSections(): void
    {
        Context::set(static::SECTIONS_CONTEXT_KEY, []);
        Context::set(static::SECTION_STACK_CONTEXT_KEY, []);
    }
}
