<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions\Renderer\Mappers;

use Hypervel\Context\Context;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\Engines\CompilerEngine;
use Hypervel\View\ViewException;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Throwable;

/*
 * This file contains parts of https://github.com/spatie/laravel-ignition.
 *
 * (c) Spatie <info@spatie.be>
 *
 * For the full copyright and license information, please review its LICENSE:
 *
 * The MIT License (MIT)
 *
 * Copyright (c) Spatie <info@spatie.be>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

class BladeMapper
{
    /**
     * Create a new Blade mapper instance.
     */
    public function __construct(
        protected BladeCompiler $bladeCompiler,
    ) {
    }

    /**
     * Map cached view paths to their original paths.
     */
    public function map(FlattenException $exception): FlattenException
    {
        while ($exception->getClass() === ViewException::class) {
            if (($previous = $exception->getPrevious()) === null) {
                break;
            }

            $exception = $previous;
        }

        $trace = (new Collection($exception->getTrace()))
            ->map(function ($frame) {
                if ($originalPath = $this->findCompiledView((string) Arr::get($frame, 'file', ''))) {
                    $frame['file'] = $originalPath;
                    $frame['line'] = $this->detectLineNumber($frame['file'], $frame['line']);
                }

                return $frame;
            })->toArray();

        return tap($exception, fn () => (fn () => $this->trace = $trace)->call($exception));
    }

    /**
     * Find the compiled view file for the given compiled path.
     */
    protected function findCompiledView(string $compiledPath): ?string
    {
        return once(fn () => $this->getKnownPaths())[$compiledPath] ?? null;
    }

    /**
     * Get the list of known paths from the compiler engine.
     *
     * In Hypervel, compiled paths are stored in coroutine Context (not an instance
     * property) because the CompilerEngine is a process-global singleton in Swoole.
     *
     * @return array<string, string>
     */
    protected function getKnownPaths(): array
    {
        $lastCompiled = Context::get(CompilerEngine::COMPILED_PATH_CONTEXT_KEY, []);

        $knownPaths = [];
        foreach ($lastCompiled as $lastCompiledPath) {
            $compiledPath = $this->bladeCompiler->getCompiledPath($lastCompiledPath);

            $knownPaths[realpath($compiledPath)] = realpath($lastCompiledPath);
        }

        return $knownPaths;
    }

    /**
     * Filter out the view data that should not be shown in the exception report.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function filterViewData(array $data): array
    {
        return array_filter($data, function ($value, $key) {
            if ($key === 'app') {
                return ! $value instanceof Application;
            }

            return $key !== '__env';
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Detect the line number in the original blade file.
     */
    protected function detectLineNumber(string $filename, int $compiledLineNumber): int
    {
        $map = $this->compileSourcemap((string) file_get_contents($filename));

        return $this->findClosestLineNumberMapping($map, $compiledLineNumber);
    }

    /**
     * Compile the source map for the given blade file.
     */
    protected function compileSourcemap(string $value): string
    {
        try {
            $value = $this->addEchoLineNumbers($value);
            $value = $this->addStatementLineNumbers($value);
            $value = $this->addBladeComponentLineNumbers($value);

            $value = $this->bladeCompiler->compileString($value);

            return $this->trimEmptyLines($value);
        } catch (Throwable $e) {
            report($e);

            return $value;
        }
    }

    /**
     * Add line numbers to echo statements.
     */
    protected function addEchoLineNumbers(string $value): string
    {
        $echoPairs = [['{{', '}}'], ['{{{', '}}}'], ['{!!', '!!}']];

        foreach ($echoPairs as $pair) {
            // Matches {{ $value }}, {!! $value !!} and  {{{ $value }}} depending on $pair
            $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $pair[0], $pair[1]);

            if (preg_match_all($pattern, $value, $matches, PREG_OFFSET_CAPTURE)) {
                foreach (array_reverse($matches[0]) as $match) {
                    $position = mb_strlen(substr($value, 0, $match[1]));

                    $value = $this->insertLineNumberAtPosition($position, $value);
                }
            }
        }

        return $value;
    }

    /**
     * Add line numbers to blade statements.
     */
    protected function addStatementLineNumbers(string $value): string
    {
        $shouldInsertLineNumbers = preg_match_all(
            '/\B@(@?\w+(?:::\w+)?)([ \t]*)(\( ( (?>[^()]+) | (?3) )* \))?/x',
            $value,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        if ($shouldInsertLineNumbers) {
            foreach (array_reverse($matches[0]) as $match) {
                $position = mb_strlen(substr($value, 0, $match[1]));

                $value = $this->insertLineNumberAtPosition($position, $value);
            }
        }

        return $value;
    }

    /**
     * Add line numbers to blade components.
     */
    protected function addBladeComponentLineNumbers(string $value): string
    {
        $shouldInsertLineNumbers = preg_match_all(
            '/<\s*x[-:]([\w\-:.]*)/mx',
            $value,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        if ($shouldInsertLineNumbers) {
            foreach (array_reverse($matches[0]) as $match) {
                $position = mb_strlen(substr($value, 0, $match[1]));

                $value = $this->insertLineNumberAtPosition($position, $value);
            }
        }

        return $value;
    }

    /**
     * Insert a line number at the given position.
     */
    protected function insertLineNumberAtPosition(int $position, string $value): string
    {
        $before = mb_substr($value, 0, $position);

        $lineNumber = count(explode("\n", $before));

        return mb_substr($value, 0, $position) . "|---LINE:{$lineNumber}---|" . mb_substr($value, $position);
    }

    /**
     * Trim empty lines from the given value.
     */
    protected function trimEmptyLines(string $value): string
    {
        $value = preg_replace('/^\|---LINE:([0-9]+)---\|$/m', '', $value);

        return ltrim((string) $value, PHP_EOL);
    }

    /**
     * Find the closest line number mapping in the given source map.
     */
    protected function findClosestLineNumberMapping(string $map, int $compiledLineNumber): int
    {
        $map = explode("\n", $map);

        $maxDistance = 20;

        $pattern = '/\|---LINE:(?P<line>[0-9]+)---\|/m';

        $lineNumberToCheck = $compiledLineNumber - 1;

        while (true) {
            if ($lineNumberToCheck < $compiledLineNumber - $maxDistance) {
                return min($compiledLineNumber, count($map));
            }

            if (preg_match($pattern, $map[$lineNumberToCheck] ?? '', $matches)) {
                return (int) $matches['line'];
            }

            --$lineNumberToCheck;
        }
    }
}
