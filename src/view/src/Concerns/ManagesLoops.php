<?php

declare(strict_types=1);

namespace Hypervel\View\Concerns;

use Closure;
use Countable;
use Generator;
use Hypervel\Context\CoroutineContext;
use Hypervel\Support\LazyCollection;
use stdClass;

trait ManagesLoops
{
    /**
     * The context key for loops stack.
     */
    protected const LOOPS_STACK_CONTEXT_KEY = '__view.loops_stack';

    /**
     * Add new loop to the stack.
     */
    public function addLoop(Closure|Countable|array|Generator $data): void
    {
        $length = is_countable($data) && ! $data instanceof LazyCollection
                            ? count($data)
                            : null;

        $loopsStack = CoroutineContext::get(static::LOOPS_STACK_CONTEXT_KEY, []);
        $parent = array_last($loopsStack);

        $loopsStack[] = [
            'iteration' => 0,
            'index' => 0,
            'remaining' => $length ?? null,
            'count' => $length,
            'first' => true,
            'last' => isset($length) ? $length == 1 : null,
            'odd' => false,
            'even' => true,
            'depth' => count($loopsStack) + 1,
            'parent' => $parent ? (object) $parent : null,
        ];

        CoroutineContext::set(static::LOOPS_STACK_CONTEXT_KEY, $loopsStack);
    }

    /**
     * Increment the top loop's indices.
     */
    public function incrementLoopIndices(): void
    {
        $loopsStack = CoroutineContext::get(static::LOOPS_STACK_CONTEXT_KEY, []);
        $loop = $loopsStack[$index = count($loopsStack) - 1];

        $loopsStack[$index] = array_merge($loopsStack[$index], [
            'iteration' => $loop['iteration'] + 1,
            'index' => $loop['iteration'],
            'first' => $loop['iteration'] == 0,
            'odd' => ! $loop['odd'],
            'even' => ! $loop['even'],
            'remaining' => isset($loop['count']) ? $loop['remaining'] - 1 : null,
            'last' => isset($loop['count']) ? $loop['iteration'] == $loop['count'] - 1 : null,
        ]);

        CoroutineContext::set(static::LOOPS_STACK_CONTEXT_KEY, $loopsStack);
    }

    /**
     * Pop a loop from the top of the loop stack.
     */
    public function popLoop(): void
    {
        $loopsStack = CoroutineContext::get(static::LOOPS_STACK_CONTEXT_KEY, []);
        array_pop($loopsStack);
        CoroutineContext::set(static::LOOPS_STACK_CONTEXT_KEY, $loopsStack);
    }

    /**
     * Get an instance of the last loop in the stack.
     */
    public function getLastLoop(): ?stdClass
    {
        $loopsStack = CoroutineContext::get(static::LOOPS_STACK_CONTEXT_KEY, []);

        return ! empty($loopsStack)
            ? (object) end($loopsStack)
            : null;
    }

    /**
     * Get the entire loop stack.
     */
    public function getLoopStack(): array
    {
        return CoroutineContext::get(static::LOOPS_STACK_CONTEXT_KEY, []);
    }
}
