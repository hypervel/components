<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel\Slack\Unit\Blocks;

use Hypervel\Notifications\Slack\BlockKit\Blocks\ActionsBlock;
use Hypervel\Tests\TestCase;
use LogicException;

class ActionsBlockTest extends TestCase
{
    public function testItIsArrayable(): void
    {
        $block = new ActionsBlock;
        $block->button('Example Button');

        $this->assertSame([
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Example Button',
                    ],
                    'action_id' => 'button_example-button',
                ],
            ],
        ], $block->toArray());
    }

    public function testItRequiresAtLeastOneElement(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('There must be at least one element in each actions block.');

        $block = new ActionsBlock;
        $block->toArray();
    }

    public function testItDoesNotAllowMoreThanTwentyFiveElements(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('There is a maximum of 25 elements in each actions block.');

        $block = new ActionsBlock;
        for ($i = 0; $i < 26; ++$i) {
            $block->button('Button');
        }

        $block->toArray();
    }

    public function testItCanManuallySpecifyTheBlockIdField(): void
    {
        $block = new ActionsBlock;
        $block->button('Example Button');
        $block->id('actions1');

        $this->assertSame([
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Example Button',
                    ],
                    'action_id' => 'button_example-button',
                ],
            ],
            'block_id' => 'actions1',
        ], $block->toArray());
    }

    public function testZeroBlockIdIsPreserved(): void
    {
        $block = new ActionsBlock;
        $block->button('Button');
        $block->id('0');

        $this->assertSame('0', $block->toArray()['block_id']);
    }

    public function testTheBlockIdFieldCannotExceed255Characters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Maximum length for the block_id field is 255 characters.');

        $block = new ActionsBlock;
        $block->button('Button');
        $block->id(str_repeat('a', 256));

        $block->toArray();
    }

    public function testBlockIdUsesTheSlackCharacterLimit(): void
    {
        $id = str_repeat('你', 255);
        $block = new ActionsBlock;
        $block->button('Button');
        $block->id($id);

        $this->assertSame($id, $block->toArray()['block_id']);
    }

    public function testItCanAddButtons(): void
    {
        $block = new ActionsBlock;
        $block->button('Example Button');
        $block->button('Scary Button')->danger();

        $this->assertSame([
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Example Button',
                    ],
                    'action_id' => 'button_example-button',
                ],
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Scary Button',
                    ],
                    'action_id' => 'button_scary-button',
                    'style' => 'danger',
                ],
            ],
        ], $block->toArray());
    }

    public function testItCanAddSelects(): void
    {
        $block = new ActionsBlock;
        $block->staticSelect('Example Select')
            ->addOption('Option A', 'option_a')
            ->id('static_select_id');
        $block->usersSelect('Example User')->id('users_select_id');

        $this->assertSame([
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'static_select',
                    'options' => [[
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'Option A',
                        ],
                        'value' => 'option_a',
                    ]],
                    'action_id' => 'static_select_id',
                ],
                [
                    'type' => 'users_select',
                    'action_id' => 'users_select_id',
                ],
            ],
        ], $block->toArray());
    }
}
