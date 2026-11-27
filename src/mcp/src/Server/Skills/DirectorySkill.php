<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Skills;

use Hypervel\Mcp\Server\Skill;

class DirectorySkill extends Skill
{
    /**
     * Create a skill backed directly by a directory path.
     */
    public function __construct(protected string $path)
    {
    }

    /**
     * Get the skill directory path.
     */
    public function path(): string
    {
        return $this->path;
    }
}
