<?php

declare(strict_types=1);

namespace Hypervel\View\Concerns;

use Hypervel\Context\Context;
use InvalidArgumentException;

trait ManagesStacks
{
    /**
     * Context key for finished, captured push sections.
     */
    protected const PUSHES_CONTEXT_KEY = 'pushes';

    /**
     * Context key for finished, captured prepend sections.
     */
    protected const PREPENDS_CONTEXT_KEY = 'prepends';

    /**
     * Context key for the stack of in-progress push sections.
     */
    protected const PUSH_STACK_CONTEXT_KEY = 'push_stack';

    /**
     * Start injecting content into a push section.
     */
    public function startPush(string $section, string $content = ''): void
    {
        if ($content === '') {
            if (ob_start()) {
                $this->pushStack($section);
            }
        } else {
            $this->extendPush($section, $content);
        }
    }

    protected function pushStack(string $section): void
    {
        $pushStack = Context::get(static::PUSH_STACK_CONTEXT_KEY, []);
        $pushStack[] = $section;
        Context::set(static::PUSH_STACK_CONTEXT_KEY, $pushStack);
    }

    private function popStack(): string
    {
        $pushStack = Context::get(static::PUSH_STACK_CONTEXT_KEY, []);
        $last = array_pop($pushStack);
        Context::set(static::PUSH_STACK_CONTEXT_KEY, $pushStack);

        return $last;
    }

    /**
     * Stop injecting content into a push section.
     *
     * @throws InvalidArgumentException
     */
    public function stopPush(): string
    {
        $last = $this->popStack();

        if (empty($last)) {
            throw new InvalidArgumentException('Cannot end a push stack without first starting one.');
        }

        return tap($last, function ($last) {
            $this->extendPush($last, ob_get_clean());
        });
    }

    /**
     * Append content to a given push section.
     */
    protected function extendPush(string $section, string $content): void
    {
        $pushes = Context::get(static::PUSHES_CONTEXT_KEY, []);

        if (! isset($pushes[$section])) {
            $pushes[$section] = [];
        }

        $renderCount = $this->getRenderCount();

        if (! isset($pushes[$section][$renderCount])) {
            $pushes[$section][$renderCount] = $content;
        } else {
            $pushes[$section][$renderCount] .= $content;
        }

        Context::set(static::PUSHES_CONTEXT_KEY, $pushes);
    }

    /**
     * Start prepending content into a push section.
     */
    public function startPrepend(string $section, string $content = ''): void
    {
        if ($content === '') {
            if (ob_start()) {
                $this->pushStack($section);
            }
        } else {
            $this->extendPrepend($section, $content);
        }
    }

    /**
     * Stop prepending content into a push section.
     *
     * @throws InvalidArgumentException
     */
    public function stopPrepend(): string
    {
        $last = $this->popStack();

        if (empty($last)) {
            throw new InvalidArgumentException('Cannot end a prepend operation without first starting one.');
        }

        return tap($last, function ($last) {
            $this->extendPrepend($last, ob_get_clean());
        });
    }

    /**
     * Prepend content to a given stack.
     */
    protected function extendPrepend(string $section, string $content): void
    {
        $prepends = Context::get(static::PREPENDS_CONTEXT_KEY, []);

        if (! isset($prepends[$section])) {
            $prepends[$section] = [];
        }

        $renderCount = $this->getRenderCount();

        if (! isset($prepends[$section][$renderCount])) {
            $prepends[$section][$renderCount] = $content;
        } else {
            $prepends[$section][$renderCount] = $content . $prepends[$section][$renderCount];
        }

        Context::set(static::PREPENDS_CONTEXT_KEY, $prepends);
    }

    /**
     * Get the string contents of a push section.
     */
    public function yieldPushContent(string $section, string $default = ''): string
    {
        $pushes = Context::get(static::PUSHES_CONTEXT_KEY, []);
        $prepends = Context::get(static::PREPENDS_CONTEXT_KEY, []);

        if (! isset($pushes[$section]) && ! isset($prepends[$section])) {
            return $default;
        }

        $output = '';

        if (isset($prepends[$section])) {
            $output .= implode(array_reverse($prepends[$section]));
        }

        if (isset($pushes[$section])) {
            $output .= implode($pushes[$section]);
        }

        return $output;
    }

    /**
     * Flush all of the stacks.
     */
    public function flushStacks(): void
    {
        Context::set(static::PUSHES_CONTEXT_KEY, []);
        Context::set(static::PREPENDS_CONTEXT_KEY, []);
        Context::set(static::PUSH_STACK_CONTEXT_KEY, []);
    }
}
