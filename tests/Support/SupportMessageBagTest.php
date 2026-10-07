<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Collection;
use Hypervel\Support\MessageBag;
use Hypervel\Tests\TestCase;
use JsonException;

class SupportMessageBagTest extends TestCase
{
    public function testUniqueness(): void
    {
        $container = new MessageBag;
        $container->add('foo', 'bar');
        $container->add('foo', 'bar');
        $messages = $container->getMessages();
        $this->assertEquals(['bar'], $messages['foo']);
    }

    public function testMessagesAreAdded(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $container->add('foo', 'baz');
        $container->add('boom', 'bust');
        $messages = $container->getMessages();
        $this->assertEquals(['bar', 'baz'], $messages['foo']);
        $this->assertEquals(['bust'], $messages['boom']);
    }

    public function testKeys(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $container->add('foo', 'baz');
        $container->add('boom', 'bust');
        $this->assertEquals(['foo', 'boom'], $container->keys());
    }

    public function testMessagesMayBeMerged(): void
    {
        $container = new MessageBag(['username' => ['foo']]);
        $container->merge(['username' => ['bar']]);
        $this->assertEquals(['username' => ['foo', 'bar']], $container->getMessages());
    }

    public function testMessageBagsCanBeMerged(): void
    {
        $container = new MessageBag(['foo' => ['bar']]);
        $otherContainer = new MessageBag(['foo' => ['baz'], 'bar' => ['foo']]);
        $container->merge($otherContainer);
        $this->assertEquals(['foo' => ['bar', 'baz'], 'bar' => ['foo']], $container->getMessages());
    }

    public function testMergingKeyedMessagesAppendsCollidingKeys(): void
    {
        $container = new MessageBag(['email' => ['unique' => 'Taken']]);
        $container->merge(new MessageBag(['email' => ['unique' => 'Reserved', 'max' => 'Too long']]));

        $this->assertSame(['email' => ['unique' => 'Taken', 0 => 'Reserved', 'max' => 'Too long']], $container->getMessages());
        $this->assertSame(['email: Taken', 'email: Reserved', 'email: Too long'], $container->all(':key: :message'));
    }

    public function testMergingPreservesIntegerFieldKeys(): void
    {
        $container = new MessageBag([0 => ['First']]);
        $container->merge([0 => ['Second']]);

        $this->assertSame([0 => ['First', 'Second']], $container->getMessages());
        $this->assertSame(['0: First', '0: Second'], $container->all(':key: :message'));
    }

    public function testMergingAStringMessageCreatesAMessageList(): void
    {
        $container = new MessageBag(['email' => ['Taken']]);
        $container->merge(['name' => 'Required']);

        $this->assertSame(['email' => ['Taken'], 'name' => ['Required']], $container->getMessages());
        $this->assertSame(['email: Taken', 'name: Required'], $container->all(':key: :message'));
    }

    public function testMessageBagsCanConvertToArrays(): void
    {
        $container = new MessageBag([
            new Collection(['foo', 'bar']),
            new Collection(['baz', 'qux']),
        ]);
        $this->assertSame([['foo', 'bar'], ['baz', 'qux']], $container->getMessages());
    }

    public function testGetReturnsArrayOfMessagesByKey(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $container->add('foo', 'baz');
        $this->assertEquals(['bar', 'baz'], $container->get('foo'));
    }

    public function testGetReturnsArrayOfMessagesByImplicitKey(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo.1', 'bar');
        $container->add('foo.2', 'baz');
        $this->assertEquals(['foo.1' => ['bar'], 'foo.2' => ['baz']], $container->get('foo.*'));
    }

    public function testFirstReturnsSingleMessage(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $container->add('foo', 'baz');
        $this->assertSame('bar', $container->first('foo'));
    }

    public function testFirstReturnsEmptyStringIfNoMessagesFound(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $this->assertSame('', $container->first('foo'));
    }

    public function testFirstReturnsSingleMessageFromDotKeys(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('name.first', 'jon');
        $container->add('name.last', 'snow');
        $this->assertSame('jon', $container->first('name.*'));
    }

    public function testHasIndicatesExistence(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $this->assertTrue($container->has('foo'));
        $this->assertFalse($container->has('bar'));
    }

    public function testMissingIndicatesNonExistence(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $this->assertFalse($container->missing('foo'));
        $this->assertFalse($container->missing(['foo', 'baz']));
        $this->assertFalse($container->missing('foo', 'baz'));
        $this->assertTrue($container->missing('baz'));
        $this->assertTrue($container->missing(['baz', 'biz']));
        $this->assertTrue($container->missing('baz', 'biz'));
    }

    public function testAddIf(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->addIf(true, 'foo', 'bar');
        $this->assertTrue($container->has('foo'));

        $container->addIf(false, 'bar', 'biz');
        $this->assertFalse($container->has('bar'));
    }

    public function testForget(): void
    {
        $container = new MessageBag(['foo' => 'bar']);
        $container->forget('foo');
        $this->assertFalse($container->has('foo'));
    }

    public function testHasWithKeyNull(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $this->assertTrue($container->has(null));
    }

    public function testHasAnyIndicatesExistence(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $this->assertFalse($container->hasAny());
        $container->add('foo', 'bar');
        $container->add('bar', 'foo');
        $container->add('boom', 'baz');
        $this->assertTrue($container->hasAny(['foo', 'bar']));
        $this->assertTrue($container->hasAny('foo', 'bar'));
        $this->assertTrue($container->hasAny(['boom', 'baz']));
        $this->assertTrue($container->hasAny('boom', 'baz'));
        $this->assertFalse($container->hasAny(['baz']));
        $this->assertFalse($container->hasAny('baz'));
        $this->assertFalse($container->hasAny('baz', 'biz'));
    }

    public function testHasAnyWithKeyNull(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $this->assertTrue($container->hasAny(null));
    }

    public function testHasIndicatesExistenceOfAllKeys(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $container->add('bar', 'foo');
        $container->add('boom', 'baz');
        $this->assertTrue($container->has(['foo', 'bar', 'boom']));
        $this->assertFalse($container->has(['foo', 'bar', 'boom', 'baz']));
        $this->assertFalse($container->has(['foo', 'baz']));
    }

    public function testHasIndicatesNoneExistence(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');

        $this->assertFalse($container->has('foo'));
    }

    public function testAllReturnsAllMessages(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $container->add('boom', 'baz');
        $this->assertEquals(['bar', 'baz'], $container->all());
    }

    public function testAllReturnsKeyedMessagesFromEveryField(): void
    {
        $container = new MessageBag([
            'email' => ['unique' => 'Taken'],
            'username' => ['unique' => 'Reserved'],
        ]);

        $this->assertSame(['Taken', 'Reserved'], $container->all());
        $this->assertSame(['email: Taken', 'username: Reserved'], $container->all(':key: :message'));
    }

    public function testFormatIsRespected(): void
    {
        $container = new MessageBag;
        $container->setFormat('<p>:message</p>');
        $container->add('foo', 'bar');
        $container->add('boom', 'baz');
        $this->assertSame('<p>bar</p>', $container->first('foo'));
        $this->assertEquals(['<p>bar</p>'], $container->get('foo'));
        $this->assertEquals(['<p>bar</p>', '<p>baz</p>'], $container->all());
        $this->assertSame('bar', $container->first('foo', ':message'));
        $this->assertEquals(['bar'], $container->get('foo', ':message'));
        $this->assertEquals(['bar', 'baz'], $container->all(':message'));

        $container->setFormat(':key :message');
        $this->assertSame('foo bar', $container->first('foo'));
    }

    public function testUnique(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $container->add('foo2', 'bar');
        $container->add('boom', 'baz');
        $this->assertEquals([0 => 'bar', 2 => 'baz'], $container->unique());
    }

    public function testMessageBagReturnsCorrectArray(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $container->add('boom', 'baz');

        $this->assertEquals(['foo' => ['bar'], 'boom' => ['baz']], $container->toArray());
    }

    public function testMessageBagReturnsExpectedJson(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $container->add('boom', 'baz');

        $this->assertSame('{"foo":["bar"],"boom":["baz"]}', $container->toJson());
    }

    public function testMessageBagReturnsExpectedPrettyJson(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo', 'bar');
        $container->add('boom', 'baz');
        $container->add('baz', '123');
        $results = $container->toPrettyJson();
        $expected = $container->toJson(JSON_PRETTY_PRINT);

        $this->assertJsonStringEqualsJsonString($expected, $results);
        $this->assertSame($expected, $results);
        $this->assertStringContainsString("\n", $results);
        $this->assertStringContainsString('    ', $results);
        $this->assertStringContainsString('"123"', $results);

        $results = $container->toPrettyJson(JSON_NUMERIC_CHECK);
        $this->assertStringContainsString("\n", $results);
        $this->assertStringContainsString('    ', $results);
        $this->assertStringContainsString('123', $results);
        $this->assertStringNotContainsString('"123"', $results);
    }

    public function testMessageBagJsonThrowsForInvalidUtf8(): void
    {
        $container = new MessageBag(['value' => "\xB1\x31"]);

        $this->expectException(JsonException::class);

        $container->toJson();
    }

    public function testMessageBagPrettyJsonPropagatesInvalidUtf8Failure(): void
    {
        $container = new MessageBag(['value' => "\xB1\x31"]);

        $this->expectException(JsonException::class);

        $container->toPrettyJson();
    }

    public function testMessageBagJsonHonorsInvalidUtf8Substitution(): void
    {
        $container = new MessageBag(['value' => "\xB1\x31"]);

        $this->assertSame('{"value":["\ufffd1"]}', $container->toJson(JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function testCountReturnsCorrectValue(): void
    {
        $container = new MessageBag;
        $this->assertCount(0, $container);

        $container->add('foo', 'bar');
        $container->add('foo', 'baz');
        $container->add('boom', 'baz');

        $this->assertCount(3, $container);
    }

    public function testCountable(): void
    {
        $container = new MessageBag;
        $container->add('foo', 'bar');
        $container->add('boom', 'baz');

        $this->assertCount(2, $container);
    }

    public function testConstructor(): void
    {
        $messageBag = new MessageBag(['country' => 'Azerbaijan', 'capital' => 'Baku']);
        $this->assertEquals(['country' => ['Azerbaijan'], 'capital' => ['Baku']], $messageBag->getMessages());
    }

    public function testFirstFindsMessageForWildcardKey(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $container->add('foo.bar', 'baz');
        $this->assertSame('baz', $container->first('foo.*'));
    }

    public function testIsEmptyTrue(): void
    {
        $container = new MessageBag;
        $this->assertTrue($container->isEmpty());
    }

    public function testIsEmptyFalse(): void
    {
        $container = new MessageBag;
        $container->add('foo.bar', 'baz');
        $this->assertFalse($container->isEmpty());
    }

    public function testIsNotEmptyTrue(): void
    {
        $container = new MessageBag;
        $container->add('foo.bar', 'baz');
        $this->assertTrue($container->isNotEmpty());
    }

    public function testIsNotEmptyFalse(): void
    {
        $container = new MessageBag;
        $this->assertFalse($container->isNotEmpty());
    }

    public function testToString(): void
    {
        $container = new MessageBag;
        $container->add('foo.bar', 'baz');
        $this->assertSame('{"foo.bar":["baz"]}', (string) $container);
    }

    public function testGetFormat(): void
    {
        $container = new MessageBag;
        $container->setFormat(':message');
        $this->assertSame(':message', $container->getFormat());
    }

    public function testConstructorUniquenessConsistency(): void
    {
        $messageBag = new MessageBag(['messages' => ['first', 'second', 'third', 'third']]);
        $messages = $messageBag->getMessages();
        $this->assertEquals(['first', 'second', 'third'], $messages['messages']);

        $messageBag = new MessageBag;
        $messageBag->add('messages', 'first');
        $messageBag->add('messages', 'second');
        $messageBag->add('messages', 'third');
        $messageBag->add('messages', 'third');
        $messages = $messageBag->getMessages();
        $this->assertEquals(['first', 'second', 'third'], $messages['messages']);
    }
}
