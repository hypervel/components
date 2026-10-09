<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Concerns\RemembersConversations;

class RememberingAssistantAgent extends AssistantAgent
{
    use RemembersConversations;
}
