<?php

declare(strict_types=1);

namespace Hypervel\Http\Concerns;

use Hypervel\Database\Eloquent\Model;

trait InteractsWithFlashData
{
    /**
     * Retrieve an old input item.
     */
    public function old(?string $key = null, Model|string|array|null $default = null): string|array|null
    {
        $default = $default instanceof Model ? $default->getAttribute($key) : $default;

        return $this->hasSession() ? $this->session()->getOldInput($key, $default) : $default;
    }

    /**
     * Flash the input for the current request to the session.
     */
    public function flash(): void
    {
        $this->session()->flashInput($this->input());
    }

    /**
     * Flash only some of the input to the session.
     */
    public function flashOnly(mixed $keys): void
    {
        $this->session()->flashInput(
            $this->only(is_array($keys) ? $keys : func_get_args())
        );
    }

    /**
     * Flash only some of the input to the session.
     */
    public function flashExcept(mixed $keys): void
    {
        $this->session()->flashInput(
            $this->except(is_array($keys) ? $keys : func_get_args())
        );
    }

    /**
     * Flush all of the old input from the session.
     */
    public function flush(): void
    {
        $this->session()->flashInput([]);
    }
}
