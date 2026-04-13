<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Testing;

use Closure;
use Hypervel\Inertia\Support\SessionKey;
use Hypervel\Support\Arr;

class TestResponseMacros
{
    /**
     * Register the 'assertInertia' macro for TestResponse.
     */
    public function assertInertia(): Closure
    {
        return function (?Closure $callback = null) {
            /** @phpstan-ignore-next-line */
            $assert = AssertableInertia::fromTestResponse($this);

            if (is_null($callback)) {
                return $this;
            }

            $callback($assert);

            return $this;
        };
    }

    /**
     * Register the 'inertiaPage' macro for TestResponse.
     */
    public function inertiaPage(): Closure
    {
        return function () {
            /* @phpstan-ignore-next-line */
            return AssertableInertia::fromTestResponse($this)->toArray();
        };
    }

    /**
     * Register the 'inertiaProps' macro for TestResponse.
     */
    public function inertiaProps(): Closure
    {
        return function (?string $propName = null) {
            /** @phpstan-ignore-next-line */
            $page = AssertableInertia::fromTestResponse($this)->toArray();

            return Arr::get($page['props'], $propName);
        };
    }

    /**
     * Register the 'assertInertiaFlash' macro for TestResponse.
     */
    public function assertInertiaFlash(): Closure
    {
        return function (string $key, mixed $expected = null) {
            /** @phpstan-ignore-next-line */
            $flash = $this->session()->get(SessionKey::FLASH_DATA, []);

            func_num_args() > 1
                ? AssertableInertia::assertFlashHas($flash, $key, $expected)
                : AssertableInertia::assertFlashHas($flash, $key);

            return $this;
        };
    }

    /**
     * Register the 'assertInertiaFlashMissing' macro for TestResponse.
     */
    public function assertInertiaFlashMissing(): Closure
    {
        return function (string $key) {
            /** @phpstan-ignore-next-line */
            $flash = $this->session()->get(SessionKey::FLASH_DATA, []);

            AssertableInertia::assertFlashMissing($flash, $key);

            return $this;
        };
    }
}
