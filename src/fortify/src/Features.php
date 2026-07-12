<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository as Config;

class Features
{
    /**
     * Determine if the given feature is enabled.
     */
    public static function enabled(string $feature): bool
    {
        return in_array($feature, self::config()->array('fortify.features', []), true);
    }

    /**
     * Determine if the feature is enabled and has a given option enabled.
     */
    public static function optionEnabled(string $feature, string $option): bool
    {
        return static::enabled($feature)
            && static::option($feature, $option) === true;
    }

    /**
     * Get an option for the given feature.
     */
    public static function option(string $feature, string $option, mixed $default = null): mixed
    {
        return static::options($feature)[$option] ?? $default;
    }

    /**
     * Get all options for the given feature.
     *
     * @return array<string, mixed>
     */
    public static function options(string $feature): array
    {
        return self::config()->array("fortify-options.{$feature}", []);
    }

    /**
     * Determine if the application is using any features that require "profile" management.
     */
    public static function hasProfileFeatures(): bool
    {
        return static::enabled(static::updateProfileInformation())
            || static::enabled(static::updatePasswords())
            || static::enabled(static::twoFactorAuthentication())
            || static::enabled(static::passkeys());
    }

    /**
     * Determine if the application can update a user's profile information.
     */
    public static function canUpdateProfileInformation(): bool
    {
        return static::enabled(static::updateProfileInformation());
    }

    /**
     * Determine if the application is using any security profile features.
     */
    public static function hasSecurityFeatures(): bool
    {
        return static::enabled(static::updatePasswords())
            || static::canManageTwoFactorAuthentication()
            || static::canManagePasskeys();
    }

    /**
     * Determine if the application can update user passwords.
     */
    public static function canUpdatePasswords(): bool
    {
        return static::enabled(static::updatePasswords());
    }

    /**
     * Determine if the application can manage two factor authentication.
     */
    public static function canManageTwoFactorAuthentication(): bool
    {
        return static::enabled(static::twoFactorAuthentication());
    }

    /**
     * Determine if the application can manage passkeys.
     */
    public static function canManagePasskeys(): bool
    {
        return static::enabled(static::passkeys());
    }

    /**
     * Enable the registration feature.
     */
    public static function registration(): string
    {
        return 'registration';
    }

    /**
     * Enable the password reset feature.
     */
    public static function resetPasswords(): string
    {
        return 'reset-passwords';
    }

    /**
     * Enable the email verification feature.
     */
    public static function emailVerification(): string
    {
        return 'email-verification';
    }

    /**
     * Enable the update profile information feature.
     */
    public static function updateProfileInformation(): string
    {
        return 'update-profile-information';
    }

    /**
     * Enable the update password feature.
     */
    public static function updatePasswords(): string
    {
        return 'update-passwords';
    }

    /**
     * Enable the two factor authentication feature.
     *
     * @param array<string, mixed> $options
     */
    public static function twoFactorAuthentication(array $options = []): string
    {
        self::setOptions('two-factor-authentication', $options);

        return 'two-factor-authentication';
    }

    /**
     * Enable the passkeys feature.
     *
     * @param array<string, mixed> $options
     */
    public static function passkeys(array $options = []): string
    {
        self::setOptions('passkeys', $options);

        return 'passkeys';
    }

    /**
     * Set options for the given feature.
     *
     * Boot/config/test only. The config repository is process-global and must not be mutated from request handlers.
     *
     * @param array<string, mixed> $options
     */
    private static function setOptions(string $feature, array $options): void
    {
        if ($options !== []) {
            self::config()->set("fortify-options.{$feature}", $options);
        }
    }

    /**
     * Get the config repository.
     */
    private static function config(): Config
    {
        return Container::getInstance()->make(Config::class);
    }
}
