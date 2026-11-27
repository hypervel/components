<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Mcp\Server\Skill;

class HouseStyleSkill extends Skill
{
    /**
     * Get the skill directory path.
     */
    public function path(): string
    {
        return __DIR__ . '/Skills/house-style';
    }

    /**
     * Determine whether the skill should register.
     */
    public function shouldRegister(Repository $config): bool
    {
        return $config->boolean('mcp.testing.house-style', true);
    }
}
