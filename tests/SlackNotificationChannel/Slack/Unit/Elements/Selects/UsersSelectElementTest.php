<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel\Slack\Unit\Elements\Selects;

use Hypervel\Notifications\Slack\BlockKit\Elements\Selects\UsersSelectElement;
use Hypervel\Tests\TestCase;

class UsersSelectElementTest extends TestCase
{
    public function testItSerializesUserSelectionOptions(): void
    {
        $select = new UsersSelectElement;
        $select->id('users_select');
        $select->placeholder('Choose a user');
        $select->initialUser('U123');
        $select->focus();

        $this->assertSame([
            'type' => 'users_select',
            'initial_user' => 'U123',
            'action_id' => 'users_select',
            'placeholder' => [
                'type' => 'plain_text',
                'text' => 'Choose a user',
            ],
            'focus_on_load' => true,
        ], $select->toArray());
    }

    public function testItGeneratesADeterministicActionIdFromText(): void
    {
        $select = new UsersSelectElement('Example User');

        $this->assertSame('users_select_example-user', $select->toArray()['action_id']);
    }
}
