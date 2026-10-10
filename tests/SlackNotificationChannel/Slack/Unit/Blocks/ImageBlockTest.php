<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel\Slack\Unit\Blocks;

use Hypervel\Notifications\Slack\BlockKit\Blocks\ImageBlock;
use Hypervel\Tests\TestCase;
use LogicException;

class ImageBlockTest extends TestCase
{
    public function testItIsArrayable(): void
    {
        $block = new ImageBlock('http://placekitten.com/500/500', 'An incredibly cute kitten.');

        $this->assertSame([
            'type' => 'image',
            'image_url' => 'http://placekitten.com/500/500',
            'alt_text' => 'An incredibly cute kitten.',
        ], $block->toArray());
    }

    public function testTheUrlCannotExceed3000Characters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Maximum length for the url field is 3000 characters.');

        new ImageBlock(str_repeat('a', 3001));
    }

    public function testTheAltTextIsRequired(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Alt text is required for an image block.');

        $block = new ImageBlock('http://placekitten.com/500/500');

        $block->toArray();
    }

    public function testTheAltTextCannotExceed2000Characters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Maximum length for the alt text field is 2000 characters.');

        $block = new ImageBlock('http://placekitten.com/500/500');
        $block->alt(str_repeat('a', 2001));

        $block->toArray();
    }

    public function testConstructorAltTextCantExceedTwoThousandCharacters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Maximum length for the alt text field is 2000 characters.');

        new ImageBlock('http://placekitten.com/500/500', str_repeat('a', 2001));
    }

    public function testItCanHaveATitle(): void
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

    public function testTheTitleFieldCannotExceed2000Characters(): void
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

    public function testItCanManuallySpecifyTheBlockIdField(): void
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

    public function testZeroBlockIdIsPreserved(): void
    {
        $block = (new ImageBlock('https://example.com/image.png', 'Image'))->id('0');

        $this->assertSame('0', $block->toArray()['block_id']);
    }

    public function testTheBlockIdFieldCannotExceed255Characters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Maximum length for the block_id field is 255 characters.');

        $block = new ImageBlock('http://placekitten.com/500/500');
        $block->id(str_repeat('a', 256));

        $block->toArray();
    }

    public function testCharacterLimitedFieldsAcceptMultibyteValuesAtTheirLimits(): void
    {
        $url = str_repeat('你', 3000);
        $altText = str_repeat('你', 2000);
        $id = str_repeat('你', 255);
        $block = new ImageBlock($url, $altText);
        $block->id($id);

        $this->assertSame([
            'type' => 'image',
            'image_url' => $url,
            'alt_text' => $altText,
            'block_id' => $id,
        ], $block->toArray());
    }
}
