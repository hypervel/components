<?php

namespace Hypervel\View\Concerns;

use InvalidArgumentException;

trait ManagesFragments
{
    /**
     * All of the captured, rendered fragments.
     *
     * @var array
     */
    protected array $fragments = [];

    /**
     * The stack of in-progress fragment renders.
     *
     * @var array
     */
    protected array $fragmentStack = [];

    /**
     * Start injecting content into a fragment.
     *
     * @param  string  $fragment
     * @return void
     */
    public function startFragment(string $fragment): void
    {
        if (ob_start()) {
            $this->fragmentStack[] = $fragment;
        }
    }

    /**
     * Stop injecting content into a fragment.
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public function stopFragment(): string
    {
        if (empty($this->fragmentStack)) {
            throw new InvalidArgumentException('Cannot end a fragment without first starting one.');
        }

        $last = array_pop($this->fragmentStack);

        $this->fragments[$last] = ob_get_clean();

        return $this->fragments[$last];
    }

    /**
     * Get the contents of a fragment.
     *
     * @param  string  $name
     * @param  string|null  $default
     * @return mixed
     */
    public function getFragment(string $name, ?string $default = null): mixed
    {
        return $this->getFragments()[$name] ?? $default;
    }

    /**
     * Get the entire array of rendered fragments.
     *
     * @return array
     */
    public function getFragments(): array
    {
        return $this->fragments;
    }

    /**
     * Flush all of the fragments.
     *
     * @return void
     */
    public function flushFragments(): void
    {
        $this->fragments = [];
        $this->fragmentStack = [];
    }
}
