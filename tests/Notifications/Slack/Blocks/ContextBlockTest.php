<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Blocks;

use Hypervel\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ContextBlockTest extends TestCase
{
    public function testArrayable(): void
    {
        $block = new ContextBlock();
        $block->text('Location: 123 Main Street, New York, NY 10010');

        $this->assertSame([
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'plain_text',
                    'text' => 'Location: 123 Main Street, New York, NY 10010',
                ],
            ],
        ], $block->toArray());
    }

    public function testRequiresAtLeastOneElement(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('There must be at least one element in each context block.');

        $block = new ContextBlock();
        $block->toArray();
    }

    public function testNotAllowMoreThanTenElements(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('There is a maximum of 10 elements in each context block.');

        $block = new ContextBlock();
        for ($i = 0; $i < 11; ++$i) {
            $block->text('Location: 123 Main Street, New York, NY 10010');
        }

        $block->toArray();
    }

    public function testCanManuallySpecifyBlockIdField(): void
    {
        $block = new ContextBlock();
        $block->text('Location: 123 Main Street, New York, NY 10010');
        $block->id('actions1');

        $this->assertSame([
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'plain_text',
                    'text' => 'Location: 123 Main Street, New York, NY 10010',
                ],
            ],
            'block_id' => 'actions1',
        ], $block->toArray());
    }

    public function testBlockIdCantExceedTwoFiveFiveCharacters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Maximum length for the block_id field is 255 characters.');

        $block = new ContextBlock();
        $block->text('Location: 123 Main Street, New York, NY 10010');
        $block->id(str_repeat('a', 256));

        $block->toArray();
    }

    public function testCanAddImageBlocks(): void
    {
        $block = new ContextBlock();
        $block->image('https://image.freepik.com/free-photo/red-drawing-pin_1156-445.jpg')->alt('images');
        $block->image('http://placekitten.com/500/500', 'An incredibly cute kitten.');

        $this->assertSame([
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'image',
                    'image_url' => 'https://image.freepik.com/free-photo/red-drawing-pin_1156-445.jpg',
                    'alt_text' => 'images',
                ],
                [
                    'type' => 'image',
                    'image_url' => 'http://placekitten.com/500/500',
                    'alt_text' => 'An incredibly cute kitten.',
                ],
            ],
        ], $block->toArray());
    }

    public function testCanAddTextBlocks(): void
    {
        $block = new ContextBlock();
        $block->text('Location: 123 Main Street, New York, NY 10010');
        $block->text('Description: **Bring your dog!**')->markdown();

        $this->assertSame([
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'plain_text',
                    'text' => 'Location: 123 Main Street, New York, NY 10010',
                ],
                [
                    'type' => 'mrkdwn',
                    'text' => 'Description: **Bring your dog!**',
                ],
            ],
        ], $block->toArray());
    }
}
