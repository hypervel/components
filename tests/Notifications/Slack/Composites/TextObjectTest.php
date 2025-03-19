<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Composites;

use Hypervel\Notifications\Slack\BlockKit\Composites\TextObject;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class TextObjectTest extends TestCase
{
    public function testArrayable(): void
    {
        $object = new TextObject('A message *with some bold text* and _some italicized text_.');

        $this->assertSame([
            'type' => 'plain_text',
            'text' => 'A message *with some bold text* and _some italicized text_.',
        ], $object->toArray());
    }

    public function testMarkdownTextField(): void
    {
        $object = new TextObject('A message *with some bold text* and _some italicized text_.');
        $object->markdown();

        $this->assertSame([
            'type' => 'mrkdwn',
            'text' => 'A message *with some bold text* and _some italicized text_.',
        ], $object->toArray());
    }

    public function testTextHasAtLeastOneCharacter(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Text must be at least 1 character(s) long.');

        new TextObject('');
    }

    public function testTextTruncatedOverThreeThousandCharacters(): void
    {
        $object = new TextObject(str_repeat('a', 3001));

        $this->assertSame([
            'type' => 'plain_text',
            'text' => str_repeat('a', 2997) . '...',
        ], $object->toArray());
    }

    public function testEscapeEmojiColonFormat(): void
    {
        $object = new TextObject('Spooky time! 👻');
        $object->emoji();

        $this->assertSame([
            'type' => 'plain_text',
            'text' => 'Spooky time! 👻',
            'emoji' => true,
        ], $object->toArray());
    }

    public function testEscapeEmojiColonFormatWhenMarkdown(): void
    {
        $object = new TextObject('Spooky time! 👻');
        $object->markdown()->emoji();

        $this->assertSame([
            'type' => 'mrkdwn',
            'text' => 'Spooky time! 👻',
        ], $object->toArray());
    }

    public function testSkipClickableAnchors(): void
    {
        $object = new TextObject('A message *with some bold text* and _some italicized text_.');
        $object->markdown()->verbatim();

        $this->assertSame([
            'type' => 'mrkdwn',
            'text' => 'A message *with some bold text* and _some italicized text_.',
            'verbatim' => true,
        ], $object->toArray());
    }

    public function testSkipClickableAnchorsWhenPlaintext(): void
    {
        $object = new TextObject('A message *with some bold text* and _some italicized text_.');
        $object->verbatim();

        $this->assertSame([
            'type' => 'plain_text',
            'text' => 'A message *with some bold text* and _some italicized text_.',
        ], $object->toArray());
    }
}
