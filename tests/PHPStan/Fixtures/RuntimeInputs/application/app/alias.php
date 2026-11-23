<?php

declare(strict_types=1);

/**
 * Render the alias view through the given renderer.
 *
 * @param RenderView $render
 */
function run_alias(Closure $render): void
{
    $render('alias-view');
}
