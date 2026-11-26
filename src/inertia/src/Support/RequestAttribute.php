<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Support;

class RequestAttribute
{
    /**
     * Request attribute for the flash data pulled from the session.
     */
    public const string PULLED_FLASH_DATA = 'inertia.pulled_flash_data';
}
