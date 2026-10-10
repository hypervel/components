<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel\Slack\Unit\Composites;

use Hypervel\Notifications\Slack\BlockKit\Composites\TextObject;
use Hypervel\Tests\TestCase;
use LogicException;

class TextObjectTest extends TestCase
{
    public function testItIsArrayable(): void
    {
        $object = new TextObject('A message *with some bold text* and _some italicized text_.');

        $this->assertSame([
            'type' => 'plain_text',
            'text' => 'A message *with some bold text* and _some italicized text_.',
        ], $object->toArray());
    }

    public function testItCanBeAMarkdownTextField(): void
    {
        $object = new TextObject('A message *with some bold text* and _some italicized text_.');
        $object->markdown();

        $this->assertSame([
            'type' => 'mrkdwn',
            'text' => 'A message *with some bold text* and _some italicized text_.',
        ], $object->toArray());
    }

    public function testTheTextHasAMinimumLengthOf1Character(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Text must be at least 1 character(s) long.');

        new TextObject('');
    }

    public function testTheTextGetsTruncatedWhenItExceeds3000Characters(): void
    {
        $object = new TextObject(str_repeat('a', 3001));

        $this->assertSame([
            'type' => 'plain_text',
            'text' => str_repeat('a', 2997) . '...',
        ], $object->toArray());
    }

    public function testItCanIndicateThatEmojisShouldBeEscapedIntoTheColonEmojiFormat(): void
    {
        $object = new TextObject('Spooky time! 👻');
        $object->emoji();

        $this->assertSame([
            'type' => 'plain_text',
            'text' => 'Spooky time! 👻',
            'emoji' => true,
        ], $object->toArray());
    }

    public function testItCannotIndicateThatEmojisShouldBeEscapedIntoTheColonEmojiFormatWhenUsingMarkdown(): void
    {
        $object = new TextObject('Spooky time! 👻');
        $object->markdown()->emoji();

        $this->assertSame([
            'type' => 'mrkdwn',
            'text' => 'Spooky time! 👻',
        ], $object->toArray());
    }

    public function testItCanIndicateThatAutoConversionIntoClickableAnchorsShouldBeSkipped(): void
    {
        $object = new TextObject('A message *with some bold text* and _some italicized text_.');
        $object->markdown()->verbatim();

        $this->assertSame([
            'type' => 'mrkdwn',
            'text' => 'A message *with some bold text* and _some italicized text_.',
            'verbatim' => true,
        ], $object->toArray());
    }

    public function testItCannotIndicateThatAutoConversionIntoClickableAnchorsShouldBeSkippedWhenUsingPlaintext(): void
    {
        $object = new TextObject('A message *with some bold text* and _some italicized text_.');
        $object->verbatim();

        $this->assertSame([
            'type' => 'plain_text',
            'text' => 'A message *with some bold text* and _some italicized text_.',
        ], $object->toArray());
    }
}
