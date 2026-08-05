<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Elements;

use Hypervel\Notifications\Slack\BlockKit\Elements\ImageElement;
use Hypervel\Tests\TestCase;
use LogicException;

class ImageElementTest extends TestCase
{
    public function testItIsArrayable(): void
    {
        $element = new ImageElement('http://placekitten.com/700/500', 'Multiple cute kittens');

        $this->assertSame([
            'type' => 'image',
            'image_url' => 'http://placekitten.com/700/500',
            'alt_text' => 'Multiple cute kittens',
        ], $element->toArray());
    }

    public function testTheAltTextIsRequired(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Alt text is required for an image element.');

        $element = new ImageElement('http://placekitten.com/700/500');

        $element->toArray();
    }

    public function testTheAltTextIsOptionalDuringObjectInstantiation(): void
    {
        $element = new ImageElement('http://placekitten.com/700/500');
        $element->alt('Some alt text');

        $this->assertSame([
            'type' => 'image',
            'image_url' => 'http://placekitten.com/700/500',
            'alt_text' => 'Some alt text',
        ], $element->toArray());
    }
}
