<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Composites;

use Hypervel\Notifications\Slack\BlockKit\Composites\PlainTextOnlyTextObject;
use Hypervel\Tests\TestCase;
use LogicException;

class PlainTextOnlyTextObjectTest extends TestCase
{
    public function testArrayable(): void
    {
        $object = new PlainTextOnlyTextObject('A message *with some bold text* and _some italicized text_.');

        $this->assertSame([
            'type' => 'plain_text',
            'text' => 'A message *with some bold text* and _some italicized text_.',
        ], $object->toArray());
    }

    public function testTextHasAtLeastOneCharacter(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Text must be at least 1 character(s) long.');

        new PlainTextOnlyTextObject('');
    }

    public function testTextTruncatedOverThreeThousandCharacters(): void
    {
        $object = new PlainTextOnlyTextObject(str_repeat('a', 3001));

        $this->assertSame([
            'type' => 'plain_text',
            'text' => str_repeat('a', 2997) . '...',
        ], $object->toArray());
    }

    public function testMultibyteTextUsesTheCharacterLimit(): void
    {
        $text = str_repeat('🪓', 3000);

        $this->assertSame($text, (new PlainTextOnlyTextObject($text))->toArray()['text']);
    }

    public function testTruncatingDoesNotSplitMultibyteCharacters(): void
    {
        $object = new PlainTextOnlyTextObject(str_repeat('🪓', 3001));

        $text = $object->toArray()['text'];

        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertSame(3000, mb_strlen($text, 'UTF-8'));
        $this->assertSame(str_repeat('🪓', 2997) . '...', $text);
        $this->assertNotFalse(json_encode($object->toArray()));
    }

    public function testMalformedOverLimitTextIsRejectedBeforeTruncation(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Text must be valid UTF-8.');

        new PlainTextOnlyTextObject(str_repeat('a', 3001) . "\xFF");
    }

    public function testEscapeEmojiColonFormat(): void
    {
        $object = new PlainTextOnlyTextObject('Spooky time! 👻');
        $object->emoji();

        $this->assertSame([
            'type' => 'plain_text',
            'text' => 'Spooky time! 👻',
            'emoji' => true,
        ], $object->toArray());
    }
}
