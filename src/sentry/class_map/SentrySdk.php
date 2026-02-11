<?php

declare(strict_types=1);

namespace Sentry;

use Hypervel\Context\ApplicationContext;
use Sentry\State\HubInterface;

/**
 * @see SentrySdk
 */
class SentrySdk
{
    protected static ?HubInterface $hub = null;

    /**
     * Constructor.
     */
    private function __construct()
    {
    }

    /**
     * Initializes the SDK with singleton hub.
     */
    public static function init(): HubInterface
    {
        if (is_null(static::$hub)) {
            static::$hub = ApplicationContext::getContainer()->make(HubInterface::class);
        }

        return static::$hub;
    }

    /**
     * Gets the current hub. Returns the singleton hub instance.
     */
    public static function getCurrentHub(): HubInterface
    {
        if (is_null(static::$hub)) {
            static::init();
        }

        return static::$hub;
    }

    /**
     * Sets the current hub in context but maintains singleton.
     */
    public static function setCurrentHub(HubInterface $hub): HubInterface
    {
        static::$hub = $hub;

        return static::$hub;
    }

    /**
     * Get the singleton hub instance directly.
     */
    public static function getHub(): ?HubInterface
    {
        return static::$hub;
    }
}
