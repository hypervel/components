<?php

namespace Hypervel\View\Compilers\Concerns;

use Hypervel\Contracts\View\ViewCompilationException;

trait CompilesLoops
{
    /**
     * Counter to keep track of nested forelse statements.
     *
     * @var int
     */
    protected int $forElseCounter = 0;

    /**
     * Compile the for-else statements into valid PHP.
     *
     * @param  string|null  $expression
     * @return string
     *
     * @throws \Hypervel\Contracts\View\ViewCompilationException
     */
    protected function compileForelse(?string $expression): string
    {
        $empty = '$__empty_'.++$this->forElseCounter;

        preg_match('/\( *(.+) +as +(.+)\)$/is', $expression ?? '', $matches);

        if (count($matches) === 0) {
            throw new ViewCompilationException('Malformed @forelse statement.');
        }

        $iteratee = trim($matches[1]);

        $iteration = trim($matches[2]);

        $initLoop = "\$__currentLoopData = {$iteratee}; \$__env->addLoop(\$__currentLoopData);";

        $iterateLoop = '$__env->incrementLoopIndices(); $loop = $__env->getLastLoop();';

        return "<?php {$empty} = true; {$initLoop} foreach(\$__currentLoopData as {$iteration}): {$iterateLoop} {$empty} = false; ?>";
    }

    /**
     * Compile the for-else-empty and empty statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileEmpty(?string $expression): string
    {
        if ($expression) {
            return "<?php if(empty{$expression}): ?>";
        }

        $empty = '$__empty_'.$this->forElseCounter--;

        return "<?php endforeach; \$__env->popLoop(); \$loop = \$__env->getLastLoop(); if ({$empty}): ?>";
    }

    /**
     * Compile the end-for-else statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndforelse(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the end-empty statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndEmpty(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the for statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileFor(string $expression): string
    {
        return "<?php for{$expression}: ?>";
    }

    /**
     * Compile the for-each statements into valid PHP.
     *
     * @param  string|null  $expression
     * @return string
     *
     * @throws \Hypervel\Contracts\View\ViewCompilationException
     */
    protected function compileForeach(?string $expression): string
    {
        preg_match('/\( *(.+) +as +(.*)\)$/is', $expression ?? '', $matches);

        if (count($matches) === 0) {
            throw new ViewCompilationException('Malformed @foreach statement.');
        }

        $iteratee = trim($matches[1]);

        $iteration = trim($matches[2]);

        $initLoop = "\$__currentLoopData = {$iteratee}; \$__env->addLoop(\$__currentLoopData);";

        $iterateLoop = '$__env->incrementLoopIndices(); $loop = $__env->getLastLoop();';

        return "<?php {$initLoop} foreach(\$__currentLoopData as {$iteration}): {$iterateLoop} ?>";
    }

    /**
     * Compile the break statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileBreak(?string $expression): string
    {
        if ($expression) {
            preg_match('/\(\s*(-?\d+)\s*\)$/', $expression, $matches);

            return $matches ? '<?php break '.max(1, $matches[1]).'; ?>' : "<?php if{$expression} break; ?>";
        }

        return '<?php break; ?>';
    }

    /**
     * Compile the continue statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileContinue(?string $expression): string
    {
        if ($expression) {
            preg_match('/\(\s*(-?\d+)\s*\)$/', $expression, $matches);

            return $matches ? '<?php continue '.max(1, $matches[1]).'; ?>' : "<?php if{$expression} continue; ?>";
        }

        return '<?php continue; ?>';
    }

    /**
     * Compile the end-for statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndfor(): string
    {
        return '<?php endfor; ?>';
    }

    /**
     * Compile the end-for-each statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndforeach(): string
    {
        return '<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>';
    }

    /**
     * Compile the while statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileWhile(string $expression): string
    {
        return "<?php while{$expression}: ?>";
    }

    /**
     * Compile the end-while statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndwhile(): string
    {
        return '<?php endwhile; ?>';
    }
}
