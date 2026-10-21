<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel\Slack\Unit\Blocks;

use Hypervel\Notifications\Slack\BlockKit\Blocks\HeaderBlock;
use Hypervel\Tests\TestCase;
use LogicException;

class HeaderBlockTest extends TestCase
{
    public function testItIsArrayable(): void
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

    public function testTheTextHeadingCannotExceed150Characters(): void
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

    public function testItCanManuallySpecifyTheBlockIdField(): void
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

    public function testZeroBlockIdIsPreserved(): void
    {
        $this->assertSame('0', (new HeaderBlock('Header'))->id('0')->toArray()['block_id']);
    }

    public function testTheBlockIdFieldCannotExceed255Characters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Maximum length for the block_id field is 255 characters.');

        $block = new HeaderBlock('Budget Performance');
        $block->id(str_repeat('a', 256));

        $block->toArray();
    }

    public function testBlockIdUsesTheSlackCharacterLimit(): void
    {
        $id = str_repeat('你', 255);
        $block = new HeaderBlock('Budget Performance');
        $block->id($id);

        $this->assertSame($id, $block->toArray()['block_id']);
    }
}
