<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

/**
 * The driver that a creator or an override supplies in place of a manager's CSV driver.
 */
class JsonDriver
{
    /**
     * Encode the rows.
     */
    public function toJson(): string
    {
        return '';
    }
}
