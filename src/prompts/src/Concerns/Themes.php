<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Concerns;

use Hypervel\Prompts\Clear;
use Hypervel\Prompts\ConfirmPrompt;
use Hypervel\Prompts\MultiSearchPrompt;
use Hypervel\Prompts\MultiSelectPrompt;
use Hypervel\Prompts\Note;
use Hypervel\Prompts\PasswordPrompt;
use Hypervel\Prompts\PausePrompt;
use Hypervel\Prompts\Progress;
use Hypervel\Prompts\SearchPrompt;
use Hypervel\Prompts\SelectPrompt;
use Hypervel\Prompts\Spinner;
use Hypervel\Prompts\SuggestPrompt;
use Hypervel\Prompts\Table;
use Hypervel\Prompts\TextareaPrompt;
use Hypervel\Prompts\TextPrompt;
use Hypervel\Prompts\Themes\Default\ClearRenderer;
use Hypervel\Prompts\Themes\Default\ConfirmPromptRenderer;
use Hypervel\Prompts\Themes\Default\MultiSearchPromptRenderer;
use Hypervel\Prompts\Themes\Default\MultiSelectPromptRenderer;
use Hypervel\Prompts\Themes\Default\NoteRenderer;
use Hypervel\Prompts\Themes\Default\PasswordPromptRenderer;
use Hypervel\Prompts\Themes\Default\PausePromptRenderer;
use Hypervel\Prompts\Themes\Default\ProgressRenderer;
use Hypervel\Prompts\Themes\Default\SearchPromptRenderer;
use Hypervel\Prompts\Themes\Default\SelectPromptRenderer;
use Hypervel\Prompts\Themes\Default\SpinnerRenderer;
use Hypervel\Prompts\Themes\Default\SuggestPromptRenderer;
use Hypervel\Prompts\Themes\Default\TableRenderer;
use Hypervel\Prompts\Themes\Default\TextareaPromptRenderer;
use Hypervel\Prompts\Themes\Default\TextPromptRenderer;
use InvalidArgumentException;

trait Themes
{
    /**
     * The name of the active theme.
     */
    protected static string $theme = 'default';

    /**
     * The available themes.
     *
     * @var array<string, array<class-string<\Hypervel\Prompts\Prompt>, class-string<callable&object>>>
     */
    protected static array $themes = [
        'default' => [
            TextPrompt::class => TextPromptRenderer::class,
            TextareaPrompt::class => TextareaPromptRenderer::class,
            PasswordPrompt::class => PasswordPromptRenderer::class,
            SelectPrompt::class => SelectPromptRenderer::class,
            MultiSelectPrompt::class => MultiSelectPromptRenderer::class,
            ConfirmPrompt::class => ConfirmPromptRenderer::class,
            PausePrompt::class => PausePromptRenderer::class,
            SearchPrompt::class => SearchPromptRenderer::class,
            MultiSearchPrompt::class => MultiSearchPromptRenderer::class,
            SuggestPrompt::class => SuggestPromptRenderer::class,
            Spinner::class => SpinnerRenderer::class,
            Note::class => NoteRenderer::class,
            Table::class => TableRenderer::class,
            Progress::class => ProgressRenderer::class,
            Clear::class => ClearRenderer::class,
        ],
    ];

    /**
     * Get or set the active theme.
     *
     * @throws InvalidArgumentException
     */
    public static function theme(?string $name = null): string
    {
        if ($name === null) {
            return static::$theme;
        }

        if (! isset(static::$themes[$name])) {
            throw new InvalidArgumentException("Prompt theme [{$name}] not found.");
        }

        return static::$theme = $name;
    }

    /**
     * Add a new theme.
     *
     * @param array<class-string<\Hypervel\Prompts\Prompt>, class-string<callable&object>> $renderers
     */
    public static function addTheme(string $name, array $renderers): void
    {
        if ($name === 'default') {
            throw new InvalidArgumentException('The default theme cannot be overridden.');
        }

        static::$themes[$name] = $renderers;
    }

    /**
     * Get the renderer for the current prompt.
     */
    protected function getRenderer(): callable
    {
        $class = get_class($this);

        return new (static::$themes[static::$theme][$class] ?? static::$themes['default'][$class])($this);
    }

    /**
     * Render the prompt using the active theme.
     */
    protected function renderTheme(): string
    {
        $renderer = $this->getRenderer();

        return $renderer($this);
    }
}
