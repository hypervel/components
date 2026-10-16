<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use UnitEnum;

class CustomPassportClient extends PassportClient
{
    protected UnitEnum|string|null $connection = 'clients';

    protected ?string $table = 'registered_clients';

    protected array $guarded = ['*'];

    /**
     * Normalize the logo URI before storage.
     */
    public function setLogoUriAttribute(?string $value): void
    {
        $this->attributes['logo_uri'] = $value === null ? null : strtolower($value);
    }
}
