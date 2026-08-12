<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Support;

class SessionKey
{
    /**
     * Session key for clearing the Inertia history.
     */
    public const string CLEAR_HISTORY = 'inertia.clear_history';

    /**
     * Session key for flash data.
     */
    public const string FLASH_DATA = 'inertia.flash_data';

    /**
     * Session key for preserving the URL fragment.
     */
    public const string PRESERVE_FRAGMENT = 'inertia.preserve_fragment';
}
