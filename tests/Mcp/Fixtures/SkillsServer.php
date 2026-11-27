<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

class SkillsServer extends ExampleServer
{
    public array $skills = [ReleaseChecklistSkill::class];
}
