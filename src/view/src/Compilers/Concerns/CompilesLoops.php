<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

use Hypervel\Context\Context;
use Hypervel\View\Contracts\ViewCompilationException;

trait CompilesLoops
{
    /**
     * Counter to keep track of nested forelse statements.
     */
    protected const FOR_ELSE_COUNTER_CONTEXT_KEY = 'for_else_counter';

    protected function incrementForElseCounter(): int
    {
        return Context::override(self::FOR_ELSE_COUNTER_CONTEXT_KEY, function ($value) {
            return is_null($value) ? 1 : $value + 1;
        });
    }

    protected function decrementForElseCounter(): int
    {
        return Context::override(self::FOR_ELSE_COUNTER_CONTEXT_KEY, function ($value) {
            return is_null($value) ? 0 : max(0, $value - 1);
        });
    }

    protected function getForElseCounter(): int
    {
        return Context::get(self::FOR_ELSE_COUNTER_CONTEXT_KEY, 0);
    }

    /**
     * Compile the for-else statements into valid PHP.
     *
     * @throws ViewCompilationException
     */
    protected function compileForelse(?string $expression): string
    {
        $this->incrementForElseCounter();
        $empty = '$__empty_' . $this->getForElseCounter();

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
     */
    protected function compileEmpty(?string $expression): string
    {
        if ($expression) {
            return "<?php if(empty{$expression}): ?>";
        }

        $empty = '$__empty_' . $this->getForElseCounter();
        $this->decrementForElseCounter();

        return "<?php endforeach; \$__env->popLoop(); \$loop = \$__env->getLastLoop(); if ({$empty}): ?>";
    }

    /**
     * Compile the end-for-else statements into valid PHP.
     */
    protected function compileEndforelse(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the end-empty statements into valid PHP.
     */
    protected function compileEndEmpty(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the for statements into valid PHP.
     */
    protected function compileFor(string $expression): string
    {
        return "<?php for{$expression}: ?>";
    }

    /**
     * Compile the for-each statements into valid PHP.
     *
     * @throws ViewCompilationException
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
     */
    protected function compileBreak(?string $expression = null): string
    {
        if ($expression) {
            preg_match('/\(\s*(-?\d+)\s*\)$/', $expression, $matches);

            return $matches ? '<?php break ' . max(1, $matches[1]) . '; ?>' : "<?php if{$expression} break; ?>";
        }

        return '<?php break; ?>';
    }

    /**
     * Compile the continue statements into valid PHP.
     */
    protected function compileContinue(?string $expression): string
    {
        if ($expression) {
            preg_match('/\(\s*(-?\d+)\s*\)$/', $expression, $matches);

            return $matches ? '<?php continue ' . max(1, $matches[1]) . '; ?>' : "<?php if{$expression} continue; ?>";
        }

        return '<?php continue; ?>';
    }

    /**
     * Compile the end-for statements into valid PHP.
     */
    protected function compileEndfor(): string
    {
        return '<?php endfor; ?>';
    }

    /**
     * Compile the end-for-each statements into valid PHP.
     */
    protected function compileEndforeach(): string
    {
        return '<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>';
    }

    /**
     * Compile the while statements into valid PHP.
     */
    protected function compileWhile(string $expression): string
    {
        return "<?php while{$expression}: ?>";
    }

    /**
     * Compile the end-while statements into valid PHP.
     */
    protected function compileEndwhile(): string
    {
        return '<?php endwhile; ?>';
    }
}
