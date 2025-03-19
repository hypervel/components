<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Blocks;

use Hypervel\Notifications\Slack\BlockKit\Blocks\DividerBlock;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DividerBlockTest extends TestCase
{
    public function testArrayable(): void
    {
        $block = new DividerBlock();

        $this->assertSame([
            'type' => 'divider',
        ], $block->toArray());
    }

    public function testCanManuallySpecifyBlockIdField(): void
    {
        $block = new DividerBlock();
        $block->id('divider1');

        $this->assertSame([
            'type' => 'divider',
            'block_id' => 'divider1',
        ], $block->toArray());
    }

    public function testBlockIdCantExceedTwoFiveFiveCharacters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Maximum length for the block_id field is 255 characters.');

        $block = new DividerBlock();
        $block->id(str_repeat('a', 256));

        $block->toArray();
    }
}
