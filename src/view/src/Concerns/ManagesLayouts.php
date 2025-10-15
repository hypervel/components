<?php

declare(strict_types=1);

namespace Hypervel\View\Concerns;

use Hypervel\Contracts\View\View;
use Hypervel\Support\Str;
use InvalidArgumentException;

trait ManagesLayouts
{
    /**
     * All of the finished, captured sections.
     *
     * @var array
     */
    protected array $sections = [];

    /**
     * The stack of in-progress sections.
     *
     * @var array
     */
    protected array $sectionStack = [];

    /**
     * The parent placeholder for the request.
     *
     * @var mixed
     */
    protected static array $parentPlaceholder = [];

    /**
     * The parent placeholder salt for the request.
     *
     * @var string
     */
    protected static ?string $parentPlaceholderSalt = null;

    /**
     * Start injecting content into a section.
     *
     * @param  string  $section
     * @param  string|null  $content
     * @return void
     */
    public function startSection(string $section, mixed $content = null): void
    {
        if ($content === null) {
            if (ob_start()) {
                $this->sectionStack[] = $section;
            }
        } else {
            $this->extendSection($section, $content instanceof View ? $content : e($content));
        }
    }

    /**
     * Inject inline content into a section.
     *
     * @param  string  $section
     * @param  string  $content
     * @return void
     */
    public function inject(string $section, string $content): void
    {
        $this->startSection($section, $content);
    }

    /**
     * Stop injecting content into a section and return its contents.
     *
     * @return string
     */
    public function yieldSection(): string
    {
        if (empty($this->sectionStack)) {
            return '';
        }

        return $this->yieldContent($this->stopSection());
    }

    /**
     * Stop injecting content into a section.
     *
     * @param  bool  $overwrite
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public function stopSection(bool $overwrite = false): string
    {
        if (empty($this->sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($this->sectionStack);

        if ($overwrite) {
            $this->sections[$last] = ob_get_clean();
        } else {
            $this->extendSection($last, ob_get_clean());
        }

        return $last;
    }

    /**
     * Stop injecting content into a section and append it.
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public function appendSection(): string
    {
        if (empty($this->sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($this->sectionStack);

        if (isset($this->sections[$last])) {
            $this->sections[$last] .= ob_get_clean();
        } else {
            $this->sections[$last] = ob_get_clean();
        }

        return $last;
    }

    /**
     * Append content to a given section.
     *
     * @param  string  $section
     * @param  string  $content
     * @return void
     */
    protected function extendSection(string $section, string $content): void
    {
        if (isset($this->sections[$section])) {
            $content = str_replace(static::parentPlaceholder($section), $content, $this->sections[$section]);
        }

        $this->sections[$section] = $content;
    }

    /**
     * Get the string contents of a section.
     *
     * @param  string  $section
     * @param  string  $default
     * @return string
     */
    public function yieldContent(string $section, string $default = ''): string
    {
        $sectionContent = $default instanceof View ? $default : e($default);

        if (isset($this->sections[$section])) {
            $sectionContent = $this->sections[$section];
        }

        $sectionContent = str_replace('@@parent', '--parent--holder--', $sectionContent);

        return str_replace(
            '--parent--holder--', '@parent', str_replace(static::parentPlaceholder($section), '', $sectionContent)
        );
    }

    /**
     * Get the parent placeholder for the current request.
     *
     * @param  string  $section
     * @return string
     */
    public static function parentPlaceholder(string $section = ''): string
    {
        if (! isset(static::$parentPlaceholder[$section])) {
            $salt = static::parentPlaceholderSalt();

            static::$parentPlaceholder[$section] = '##parent-placeholder-'.hash('xxh128', $salt.$section).'##';
        }

        return static::$parentPlaceholder[$section];
    }

    /**
     * Get the parent placeholder salt.
     *
     * @return string
     */
    protected static function parentPlaceholderSalt(): string
    {
        if (! static::$parentPlaceholderSalt) {
            return static::$parentPlaceholderSalt = Str::random(40);
        }

        return static::$parentPlaceholderSalt;
    }

    /**
     * Check if section exists.
     *
     * @param  string  $name
     * @return bool
     */
    public function hasSection(string $name): bool
    {
        return array_key_exists($name, $this->sections);
    }

    /**
     * Check if section does not exist.
     *
     * @param  string  $name
     * @return bool
     */
    public function sectionMissing(string $name): bool
    {
        return ! $this->hasSection($name);
    }

    /**
     * Get the contents of a section.
     *
     * @param  string  $name
     * @param  string|null  $default
     * @return mixed
     */
    public function getSection(string $name, ?string $default = null): mixed
    {
        return $this->getSections()[$name] ?? $default;
    }

    /**
     * Get the entire array of sections.
     *
     * @return array
     */
    public function getSections(): array
    {
        return $this->sections;
    }

    /**
     * Flush all of the sections.
     *
     * @return void
     */
    public function flushSections(): void
    {
        $this->sections = [];
        $this->sectionStack = [];
    }
}
