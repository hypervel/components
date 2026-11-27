<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Server\Attributes\Uri;

#[Uri('skill://team/release-checklist/SKILL.md')]
class TeamReleaseChecklistSkill extends ReleaseChecklistSkill
{
}
