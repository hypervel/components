<?php

declare(strict_types=1);

namespace Hypervel\View\Concerns;

use Hypervel\Context\CoroutineContext;
use InvalidArgumentException;

trait ManagesFragments
{
    /**
     * All of the captured, rendered fragments.
     */
    protected const FRAGMENTS_CONTEXT_KEY = '__view.fragments';

    /**
     * The stack of in-progress fragment renders.
     */
    protected const FRAGMENT_STACK_CONTEXT_KEY = '__view.fragment_stack';

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
        CoroutineContext::override(self::FRAGMENT_STACK_CONTEXT_KEY, function (?array $stack) use ($fragment) {
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
        $fragmentStack = CoroutineContext::get(self::FRAGMENT_STACK_CONTEXT_KEY);

        if (empty($fragmentStack)) {
            throw new InvalidArgumentException('Cannot end a fragment without first starting one.');
        }

        $last = array_pop($fragmentStack);
        CoroutineContext::set(self::FRAGMENT_STACK_CONTEXT_KEY, $fragmentStack);

        $fragments = CoroutineContext::get(self::FRAGMENTS_CONTEXT_KEY, []);
        $fragments[$last] = ob_get_clean();
        CoroutineContext::set(self::FRAGMENTS_CONTEXT_KEY, $fragments);

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
        return CoroutineContext::get(self::FRAGMENTS_CONTEXT_KEY, []);
    }

    /**
     * Flush all of the fragments.
     */
    public function flushFragments(): void
    {
        CoroutineContext::set(self::FRAGMENTS_CONTEXT_KEY, []);
        CoroutineContext::set(self::FRAGMENT_STACK_CONTEXT_KEY, []);
    }
}
