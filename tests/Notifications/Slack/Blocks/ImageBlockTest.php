<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Blocks;

use Hypervel\Notifications\Slack\BlockKit\Blocks\ImageBlock;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ImageBlockTest extends TestCase
{
    public function testArrayable(): void
    {
        $block = new ImageBlock('http://placekitten.com/500/500', 'An incredibly cute kitten.');

        $this->assertSame([
            'type' => 'image',
            'image_url' => 'http://placekitten.com/500/500',
            'alt_text' => 'An incredibly cute kitten.',
        ], $block->toArray());
    }

    public function testUrlCantExceedThreeThousandCharacters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Maximum length for the url field is 3000 characters.');

        new ImageBlock(str_repeat('a', 3001));
    }

    public function testAltTextIsRequired(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Alt text is required for an image block.');

        $block = new ImageBlock('http://placekitten.com/500/500');

        $block->toArray();
    }

    public function testAltTextCantExceedTwoThousandCharacters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Maximum length for the alt text field is 2000 characters.');

        $block = new ImageBlock('http://placekitten.com/500/500');
        $block->alt(str_repeat('a', 2001));

        $block->toArray();
    }

    public function testCanHaveTitle(): void
    {
        $block = new ImageBlock('http://placekitten.com/500/500', 'An incredibly cute kitten.');
        $block->title('This one is a cutesy kitten in a box.');

        $this->assertSame([
            'type' => 'image',
            'image_url' => 'http://placekitten.com/500/500',
            'alt_text' => 'An incredibly cute kitten.',
            'title' => [
                'type' => 'plain_text',
                'text' => 'This one is a cutesy kitten in a box.',
            ],
        ], $block->toArray());
    }

    public function testTitleCantExceedTwoThousandCharacters(): void
    {
        $block = new ImageBlock('http://placekitten.com/500/500', 'An incredibly cute kitten.');
        $block->title(str_repeat('a', 2001));

        $this->assertSame([
            'type' => 'image',
            'image_url' => 'http://placekitten.com/500/500',
            'alt_text' => 'An incredibly cute kitten.',
            'title' => [
                'type' => 'plain_text',
                'text' => str_repeat('a', 1997) . '...',
            ],
        ], $block->toArray());
    }

    public function testCanManuallySpecifyBlockIdField(): void
    {
        $block = new ImageBlock('http://placekitten.com/500/500');
        $block->alt('An incredibly cute kitten.');
        $block->id('actions1');

        $this->assertSame([
            'type' => 'image',
            'image_url' => 'http://placekitten.com/500/500',
            'alt_text' => 'An incredibly cute kitten.',
            'block_id' => 'actions1',
        ], $block->toArray());
    }

    public function testBlockIdCantExceedTwoFiveFiveCharacters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Maximum length for the block_id field is 255 characters.');

        $block = new ImageBlock('http://placekitten.com/500/500');
        $block->id(str_repeat('a', 256));

        $block->toArray();
    }
}
