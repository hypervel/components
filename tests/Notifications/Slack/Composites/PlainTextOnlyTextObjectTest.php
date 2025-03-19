<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Composites;

use Hypervel\Notifications\Slack\BlockKit\Composites\PlainTextOnlyTextObject;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
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
