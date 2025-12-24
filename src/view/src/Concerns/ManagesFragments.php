<?php

declare(strict_types=1);

namespace Hypervel\View\Concerns;

use Hypervel\Context\Context;
use InvalidArgumentException;

trait ManagesFragments
{
    /**
     * All of the captured, rendered fragments.
     */
    protected const FRAGMENTS_CONTEXT_KEY = 'fragments';

    /**
     * The stack of in-progress fragment renders.
     */
    protected const FRAGMENT_STACK_CONTEXT_KEY = 'fragment_stack';

    /**
     * Start injecting content into a fragment.
     */
    public function startFragment(string $fragment): void
    {
        if (ob_start()) {
            $this->pushFragmentStack($fragment);
        }
    }

    protected function pushFragmentStack(string $fragment): void
    {
        Context::override(self::FRAGMENT_STACK_CONTEXT_KEY, function (?array $stack) use ($fragment) {
            $stack = $stack ?? [];
            $stack[] = $fragment;
            return $stack;
        });
    }

    /**
     * Stop injecting content into a fragment.
     *
     * @throws InvalidArgumentException
     */
    public function stopFragment(): string
    {
        $fragmentStack = Context::get(self::FRAGMENT_STACK_CONTEXT_KEY);

        if (empty($fragmentStack)) {
            throw new InvalidArgumentException('Cannot end a fragment without first starting one.');
        }

        $last = array_pop($fragmentStack);
        Context::set(self::FRAGMENT_STACK_CONTEXT_KEY, $fragmentStack);

        $fragments = Context::get(self::FRAGMENTS_CONTEXT_KEY, []);
        $fragments[$last] = ob_get_clean();
        Context::set(self::FRAGMENTS_CONTEXT_KEY, $fragments);

        return $fragments[$last];
    }

    /**
     * Get the contents of a fragment.
     */
    public function getFragment(string $name, ?string $default = null): mixed
    {
        return $this->getFragments()[$name] ?? $default;
    }

    /**
     * Get the entire array of rendered fragments.
     */
    public function getFragments(): array
    {
        return Context::get(self::FRAGMENTS_CONTEXT_KEY, []);
    }

    /**
     * Flush all of the fragments.
     */
    public function flushFragments(): void
    {
        Context::set(self::FRAGMENTS_CONTEXT_KEY, []);
        Context::set(self::FRAGMENT_STACK_CONTEXT_KEY, []);
    }
}
