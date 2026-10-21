<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Slack\BlockKit\Elements\Traits;

use Hypervel\Support\Str;

trait GeneratesDefaultIds
{
    /**
     * Resolve a default unique identifier based on the given text and optional prefix.
     */
    private function resolveDefaultId(string $prefix = '', ?string $text = null): string
    {
        $slug = $text === null ? '' : Str::lower(Str::slug(substr($text, 0, 248)));

        // Str::slug may expand or erase the bounded seed, so substitute an empty
        // result and cap the final Slack action ID separately.
        if ($slug === '') {
            $slug = uniqid();
        }

        return substr($prefix . $slug, 0, 255);
    }
}
