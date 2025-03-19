<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Blocks;

use Hypervel\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Hypervel\Notifications\Slack\BlockKit\Elements\ImageElement;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SectionBlockTest extends TestCase
{
    public function testArrayable(): void
    {
        $block = new SectionBlock();
        $block->text('Location: 123 Main Street, New York, NY 10010');

        $this->assertSame([
            'type' => 'section',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Location: 123 Main Street, New York, NY 10010',
            ],
        ], $block->toArray());
    }

    public function testExceptionWithoutTextAndField(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A section requires at least one block, or the text to be set.');

        $block = new SectionBlock();

        $block->toArray();
    }

    public function testTextHasAtLeastOneCharacter(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Text must be at least 1 character(s) long.');

        $block = new SectionBlock();
        $block->text('');

        $block->toArray();
    }

    public function testTextCantExceedThreeThousandCharacters(): void
    {
        $block = new SectionBlock();
        $block->text(str_repeat('a', 3001));

        $this->assertSame([
            'type' => 'section',
            'text' => [
                'type' => 'plain_text',
                'text' => str_repeat('a', 2997) . '...',
            ],
        ], $block->toArray());
    }

    public function testTextCanBeCustomized(): void
    {
        $block = new SectionBlock();
        $block->text('Location: 123 Main Street, New York, NY 10010')->markdown();

        $this->assertSame([
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => 'Location: 123 Main Street, New York, NY 10010',
            ],
        ], $block->toArray());
    }

    public function testNotAllowMoreThanTenFields(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('There is a maximum of 10 fields in each section block.');

        $block = new SectionBlock();
        for ($i = 0; $i < 11; ++$i) {
            $block->field('Location: 123 Main Street, New York, NY 10010');
        }

        $block->toArray();
    }

    public function testFieldCantExceedTwoThousandCharacters(): void
    {
        $block = new SectionBlock();
        $block->field(str_repeat('a', 2001));

        $this->assertSame([
            'type' => 'section',
            'fields' => [
                [
                    'type' => 'plain_text',
                    'text' => str_repeat('a', 1997) . '...',
                ],
            ],
        ], $block->toArray());
    }

    public function testFieldCanBeCustomized(): void
    {
        $block = new SectionBlock();
        $block->field('Location: 123 Main Street, New York, NY 10010')->markdown();

        $this->assertSame([
            'type' => 'section',
            'fields' => [
                [
                    'type' => 'mrkdwn',
                    'text' => 'Location: 123 Main Street, New York, NY 10010',
                ],
            ],
        ], $block->toArray());
    }

    public function testCanManuallySpecifyBlockIdField(): void
    {
        $block = new SectionBlock();
        $block->text('Location: 123 Main Street, New York, NY 10010');
        $block->id('section1');

        $this->assertSame([
            'type' => 'section',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Location: 123 Main Street, New York, NY 10010',
            ],
            'block_id' => 'section1',
        ], $block->toArray());
    }

    public function testBlockIdCantExceedTwoFiveFiveCharacters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Maximum length for the block_id field is 255 characters.');

        $block = new SectionBlock();
        $block->text('Location: 123 Main Street, New York, NY 10010');
        $block->id(str_repeat('a', 256));

        $block->toArray();
    }

    public function testCanSpecifyAccesoryElement(): void
    {
        $block = new SectionBlock();
        $block->text('Location: 123 Main Street, New York, NY 10010');
        $block->accessory(new ImageElement('https://example.com/image.png', 'Image'));

        $this->assertSame([
            'type' => 'section',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Location: 123 Main Street, New York, NY 10010',
            ],
            'accessory' => [
                'type' => 'image',
                'image_url' => 'https://example.com/image.png',
                'alt_text' => 'Image',
            ],
        ], $block->toArray());
    }
}
