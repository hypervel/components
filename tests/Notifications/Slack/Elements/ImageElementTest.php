<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Elements;

use Hypervel\Notifications\Slack\BlockKit\Elements\ImageElement;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
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

    public function testAltTextIsNotSubjectToTheImageBlockLimit(): void
    {
        $altText = str_repeat('a', 5000);

        $this->assertSame(
            $altText,
            (new ImageElement('http://placekitten.com/700/500', $altText))->toArray()['alt_text'],
        );
    }

    public function testImageUrlCannotExceedThreeThousandCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum length for the url field is 3000 characters.');

        new ImageElement(str_repeat('a', 3001), 'Alternative text');
    }

    public function testImageUrlUsesTheSlackCharacterLimit(): void
    {
        $url = str_repeat('你', 3000);

        $this->assertSame($url, (new ImageElement($url, 'Alternative text'))->toArray()['image_url']);
    }
}
