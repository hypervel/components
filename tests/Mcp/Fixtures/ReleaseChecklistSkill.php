<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Server\Skill;

class ReleaseChecklistSkill extends Skill
{
    /**
     * Get the skill directory path.
     */
    public function path(): string
    {
        return __DIR__ . '/Skills/release-checklist';
    }
}
