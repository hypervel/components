<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Concerns;

trait RendersAnimations
{
    /**
     * Determine whether the prompt should be rendered statically.
     *
     * Animating redraws the prompt with cursor movements, which only make
     * sense on a terminal. Decorated output alone is not enough, because
     * `--ansi` forces it in CI and build logs where each frame would be
     * written on its own line.
     */
    protected function shouldRenderStatically(): bool
    {
        // Unlike Laravel, pcntl is not checked here: spinners animate in a coroutine, and tasks check it only when they fork.
        return ! static::canControlTerminal();
    }
}
