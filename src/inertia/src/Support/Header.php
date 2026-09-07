<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Support;

class Header
{
    /**
     * The main Inertia request header.
     */
    public const string INERTIA = 'X-Inertia';

    /**
     * Header for specifying which error bag to use for validation errors.
     */
    public const string ERROR_BAG = 'X-Inertia-Error-Bag';

    /**
     * Header for external redirects.
     */
    public const string LOCATION = 'X-Inertia-Location';

    /**
     * Header for hash fragment redirects.
     */
    public const string REDIRECT = 'X-Inertia-Redirect';

    /**
     * Header for the current asset version.
     */
    public const string VERSION = 'X-Inertia-Version';

    /**
     * Header specifying the component for partial reloads.
     */
    public const string PARTIAL_COMPONENT = 'X-Inertia-Partial-Component';

    /**
     * Header specifying which props to include in partial reloads.
     */
    public const string PARTIAL_ONLY = 'X-Inertia-Partial-Data';

    /**
     * Header specifying which props to exclude from partial reloads.
     */
    public const string PARTIAL_EXCEPT = 'X-Inertia-Partial-Except';

    /**
     * Header for resetting the page state.
     */
    public const string RESET = 'X-Inertia-Reset';

    /**
     * Header for specifying the merge intent when paginating on infinite scroll.
     */
    public const string INFINITE_SCROLL_MERGE_INTENT = 'X-Inertia-Infinite-Scroll-Merge-Intent';

    /**
     * Header specifying which once props to exclude from the response.
     */
    public const string EXCEPT_ONCE_PROPS = 'X-Inertia-Except-Once-Props';
}
