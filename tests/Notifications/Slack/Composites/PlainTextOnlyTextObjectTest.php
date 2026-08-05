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

    public function testTruncatingDoesNotSplitMultibyteCharacters(): void
    {
        // 🪓 is 4 bytes in UTF-8, so the byte-based truncation point lands
        // mid-character; truncating there must not produce invalid UTF-8.
        $object = new PlainTextOnlyTextObject(str_repeat('🪓', 751));

        $text = $object->toArray()['text'];

        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertLessThanOrEqual(3000, strlen($text));
        $this->assertStringEndsWith('...', $text);
        $this->assertNotFalse(json_encode($object->toArray()));
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
