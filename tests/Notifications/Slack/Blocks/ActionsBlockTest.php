<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Blocks;

use Hypervel\Notifications\Slack\BlockKit\Blocks\ActionsBlock;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ActionsBlockTest extends TestCase
{
    public function testArrayable(): void
    {
        $block = new ActionsBlock();
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

    public function testRequiresAtLeastOneElement(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('There must be at least one element in each actions block.');

        $block = new ActionsBlock();
        $block->toArray();
    }

    public function testDoesNotAllowMoreTwentyFiveElements(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('There is a maximum of 25 elements in each actions block.');

        $block = new ActionsBlock();
        for ($i = 0; $i < 26; ++$i) {
            $block->button('Button');
        }

        $block->toArray();
    }

    public function testCanManuallySpecifyBlockIdField(): void
    {
        $block = new ActionsBlock();
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

    public function testBlockIdCantExceedTwoFiveFiveCharacters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Maximum length for the block_id field is 255 characters.');

        $block = new ActionsBlock();
        $block->button('Button');
        $block->id(str_repeat('a', 256));

        $block->toArray();
    }

    public function testCanAddButtons(): void
    {
        $block = new ActionsBlock();
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
}
