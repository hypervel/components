<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Blocks;

use Hypervel\Notifications\Slack\BlockKit\Blocks\HeaderBlock;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HeaderBlockTest extends TestCase
{
    public function testArrayable(): void
    {
        $block = new HeaderBlock('Budget Performance');

        $this->assertSame([
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Budget Performance',
            ],
        ], $block->toArray());
    }

    public function testBlockIdCantExceedOneFiveZeroCharacters(): void
    {
        $blockA = new HeaderBlock(str_repeat('a', 151));
        $blockB = new HeaderBlock(str_repeat('b', 150));

        $this->assertSame([
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => str_repeat('a', 147) . '...',
            ],
        ], $blockA->toArray());

        $this->assertSame([
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => str_repeat('b', 150),
            ],
        ], $blockB->toArray());
    }

    public function testCanManuallySpecifyBlockIdField(): void
    {
        $block = new HeaderBlock('Budget Performance');
        $block->id('header1');

        $this->assertSame([
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Budget Performance',
            ],
            'block_id' => 'header1',
        ], $block->toArray());
    }

    public function testBlockIdCantExceedTwoFiveFiveCharacters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Maximum length for the block_id field is 255 characters.');

        $block = new HeaderBlock('Budget Performance');
        $block->id(str_repeat('a', 256));

        $block->toArray();
    }
}
