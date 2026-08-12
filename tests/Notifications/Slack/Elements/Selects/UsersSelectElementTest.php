<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Elements\Selects;

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

    public function testGeneratedActionIdRespectsTheSlackLimit(): void
    {
        $select = new UsersSelectElement(str_repeat('a', 248));

        $this->assertSame(255, strlen($select->toArray()['action_id']));
    }

    public function testDirectConstructionWithoutTextGeneratesAnActionId(): void
    {
        $actionId = (new UsersSelectElement)->toArray()['action_id'];

        $this->assertStringStartsWith('users_select_', $actionId);
        $this->assertNotSame('users_select_', $actionId);
    }
}
