<?php

declare(strict_types=1);

namespace Hypervel\Doctor;

use InvalidArgumentException;

enum EnvironmentMode: string
{
    case Local = 'local';
    case Production = 'production';

    /**
     * Resolve the current Doctor environment mode.
     */
    public static function current(): self
    {
        return self::fromHypervelEnvironment((string) app()->environment());
    }

    /**
     * Resolve a Doctor environment mode from a Hypervel environment name.
     */
    public static function fromHypervelEnvironment(string $environment): self
    {
        return self::configuredEnvironments()[$environment] ?? self::Production;
    }

    /**
     * Get the Doctor mode for each configured Hypervel environment name.
     *
     * @return array<string, self>
     */
    private static function configuredEnvironments(): array
    {
        $configured = [];

        foreach (config()->array('doctor.environments') as $mode => $environments) {
            $resolved = is_string($mode) ? self::tryFrom($mode) : null;

            if ($resolved === null) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid Doctor environment mode [%s] configured.',
                    is_string($mode) ? $mode : get_debug_type($mode),
                ));
            }

            if (! is_array($environments)) {
                throw new InvalidArgumentException(sprintf(
                    'Hypervel environment names for Doctor mode [%s] must be an array, [%s] given.',
                    $mode,
                    get_debug_type($environments),
                ));
            }

            foreach ($environments as $environment) {
                if (isset($configured[$environment]) && $configured[$environment] !== $resolved) {
                    throw new InvalidArgumentException(sprintf(
                        'Hypervel environment [%s] is configured for multiple Doctor modes.',
                        $environment,
                    ));
                }

                $configured[$environment] = $resolved;
            }
        }

        return $configured;
    }

    /**
     * Determine whether this mode should use production expectations.
     */
    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
