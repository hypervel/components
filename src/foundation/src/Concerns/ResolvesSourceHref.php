<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Concerns;

use Hypervel\Support\Str;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Resolve source links for consumers that provide a $basePath property.
 */
trait ResolvesSourceHref
{
    /**
     * All of the href formats for common editors.
     *
     * @var array<string, string>
     */
    protected array $editorHrefs = [
        'antigravity' => 'antigravity://file/{file}:{line}',
        'atom' => 'atom://core/open/file?filename={file}&line={line}',
        'cursor' => 'cursor://file/{file}:{line}',
        'emacs' => 'emacs://open?url=file://{file}&line={line}',
        'fleet' => 'fleet://open?file={file}&line={line}',
        'idea' => 'idea://open?file={file}&line={line}',
        'kiro' => 'kiro://file/{file}:{line}',
        'macvim' => 'mvim://open/?url=file://{file}&line={line}',
        'neovim' => 'nvim://open?url=file://{file}&line={line}',
        'netbeans' => 'netbeans://open/?f={file}:{line}',
        'nova' => 'nova://core/open/file?filename={file}&line={line}',
        'phpstorm' => 'phpstorm://open?file={file}&line={line}',
        'sublime' => 'subl://open?url=file://{file}&line={line}',
        'textmate' => 'txmt://open?url=file://{file}&line={line}',
        'trae' => 'trae://file/{file}:{line}',
        'vscode' => 'vscode://file/{file}:{line}',
        'vscode-insiders' => 'vscode-insiders://file/{file}:{line}',
        'vscode-insiders-remote' => 'vscode-insiders://vscode-remote/{file}:{line}',
        'vscode-remote' => 'vscode://vscode-remote/{file}:{line}',
        'vscodium' => 'vscodium://file/{file}:{line}',
        'windsurf' => 'windsurf://file/{file}:{line}',
        'xdebug' => 'xdebug://{file}@{line}',
        'zed' => 'zed://file/{file}:{line}',
    ];

    /**
     * Resolve the source href, if possible.
     */
    protected function resolveSourceHref(string $file, ?int $line): ?string
    {
        try {
            $editor = config('app.editor');
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            // ..
        }

        if (! isset($editor)) {
            return null;
        }

        $href = is_array($editor) && isset($editor['href'])
            ? $editor['href']
            : ($this->editorHrefs[$editor['name'] ?? $editor] ?? sprintf('%s://open?file={file}&line={line}', $editor['name'] ?? $editor));

        $basePath = $editor['base_path'] ?? false;

        if ($basePath !== false) {
            $file = Str::replaceStart($this->basePath, $basePath, $file);
        }

        return str_replace(
            ['{file}', '{line}'],
            [$file, (string) ($line ?? 1)],
            $href,
        );
    }
}
