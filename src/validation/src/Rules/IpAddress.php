<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use InvalidArgumentException;
use Stringable;

class IpAddress implements Stringable
{
    /**
     * @var null|4|6
     */
    protected ?int $version = null;

    /** @var list<4|6> */
    private array $allowedVersions = [4, 6];

    /**
     * Set the IP version.
     *
     * @template TVersion of int
     *
     * @param TVersion $version
     *
     * @phpstan-return  ($version is 4|6 ? static : never)
     *
     * @throws InvalidArgumentException
     */
    public function version(int $version): static
    {
        if (! in_array($version, $this->allowedVersions, true)) {
            throw new InvalidArgumentException(sprintf(
                'The provided IP version %d is invalid. Please use one of these: %s',
                $version,
                implode(', ', $this->allowedVersions),
            ));
        }

        $this->version = $version;

        return $this;
    }

    /**
     * Convert the rule to a validation string.
     *
     * @return 'ip'|'ipv4'|'ipv6'
     */
    public function __toString(): string
    {
        return $this->version === null ? 'ip' : "ipv{$this->version}";
    }
}
