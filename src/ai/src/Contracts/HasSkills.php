<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Closure;
use Hypervel\Ai\Skills\Skill;

interface HasSkills
{
    /**
     * Get the skills available to the agent.
     *
     * @return iterable<Closure|Skill|string>
     */
    public function skills(): iterable;
}
