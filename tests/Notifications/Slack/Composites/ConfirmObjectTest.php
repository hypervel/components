<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Composites;

use Hypervel\Notifications\Slack\BlockKit\Composites\ConfirmObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ConfirmObjectTest extends TestCase
{
    public function testArrayable(): void
    {
        $object = new ConfirmObject();

        $this->assertSame([
            'title' => [
                'type' => 'plain_text',
                'text' => 'Are you sure?',
            ],
            'text' => [
                'type' => 'plain_text',
                'text' => 'Please confirm this action.',
            ],
            'confirm' => [
                'type' => 'plain_text',
                'text' => 'Yes',
            ],
            'deny' => [
                'type' => 'plain_text',
                'text' => 'No',
            ],
        ], $object->toArray());
    }

    public function testTitleIsCustomizable(): void
    {
        $object = new ConfirmObject();
        $object->title('This is a custom title.');

        $this->assertSame([
            'title' => [
                'type' => 'plain_text',
                'text' => 'This is a custom title.',
            ],
            'text' => [
                'type' => 'plain_text',
                'text' => 'Please confirm this action.',
            ],
            'confirm' => [
                'type' => 'plain_text',
                'text' => 'Yes',
            ],
            'deny' => [
                'type' => 'plain_text',
                'text' => 'No',
            ],
        ], $object->toArray());
    }

    public function testTitleTruncatedOverOneHundredCharacters(): void
    {
        $object = new ConfirmObject();
        $object->title(str_repeat('a', 101));

        $this->assertSame([
            'title' => [
                'type' => 'plain_text',
                'text' => str_repeat('a', 97) . '...',
            ],
            'text' => [
                'type' => 'plain_text',
                'text' => 'Please confirm this action.',
            ],
            'confirm' => [
                'type' => 'plain_text',
                'text' => 'Yes',
            ],
            'deny' => [
                'type' => 'plain_text',
                'text' => 'No',
            ],
        ], $object->toArray());
    }

    public function testTextIsCustomizable(): void
    {
        $object = new ConfirmObject();
        $object->text('This is some custom text.');

        $this->assertSame([
            'title' => [
                'type' => 'plain_text',
                'text' => 'Are you sure?',
            ],
            'text' => [
                'type' => 'plain_text',
                'text' => 'This is some custom text.',
            ],
            'confirm' => [
                'type' => 'plain_text',
                'text' => 'Yes',
            ],
            'deny' => [
                'type' => 'plain_text',
                'text' => 'No',
            ],
        ], $object->toArray());
    }

    public function testTextTruncatedOverThreeHundredCharacters(): void
    {
        $objectA = new ConfirmObject(str_repeat('a', 301));

        $this->assertSame([
            'title' => [
                'type' => 'plain_text',
                'text' => 'Are you sure?',
            ],
            'text' => [
                'type' => 'plain_text',
                'text' => str_repeat('a', 297) . '...',
            ],
            'confirm' => [
                'type' => 'plain_text',
                'text' => 'Yes',
            ],
            'deny' => [
                'type' => 'plain_text',
                'text' => 'No',
            ],
        ], $objectA->toArray());

        $objectB = new ConfirmObject();
        $objectB->text(str_repeat('b', 301));

        $this->assertSame([
            'title' => [
                'type' => 'plain_text',
                'text' => 'Are you sure?',
            ],
            'text' => [
                'type' => 'plain_text',
                'text' => str_repeat('b', 297) . '...',
            ],
            'confirm' => [
                'type' => 'plain_text',
                'text' => 'Yes',
            ],
            'deny' => [
                'type' => 'plain_text',
                'text' => 'No',
            ],
        ], $objectB->toArray());
    }

    public function testConfirmIsCustomizable(): void
    {
        $object = new ConfirmObject();
        $object->confirm('Custom confirmation button.');

        $this->assertSame([
            'title' => [
                'type' => 'plain_text',
                'text' => 'Are you sure?',
            ],
            'text' => [
                'type' => 'plain_text',
                'text' => 'Please confirm this action.',
            ],
            'confirm' => [
                'type' => 'plain_text',
                'text' => 'Custom confirmation button.',
            ],
            'deny' => [
                'type' => 'plain_text',
                'text' => 'No',
            ],
        ], $object->toArray());
    }

    public function testConfirmTruncatedOverThirtyCharacters(): void
    {
        $object = new ConfirmObject();
        $object->confirm(str_repeat('a', 31));

        $this->assertSame([
            'title' => [
                'type' => 'plain_text',
                'text' => 'Are you sure?',
            ],
            'text' => [
                'type' => 'plain_text',
                'text' => 'Please confirm this action.',
            ],
            'confirm' => [
                'type' => 'plain_text',
                'text' => str_repeat('a', 27) . '...',
            ],
            'deny' => [
                'type' => 'plain_text',
                'text' => 'No',
            ],
        ], $object->toArray());
    }

    public function testColorSchemeWithDanger(): void
    {
        $object = new ConfirmObject();
        $object->danger();

        $this->assertSame([
            'title' => [
                'type' => 'plain_text',
                'text' => 'Are you sure?',
            ],
            'text' => [
                'type' => 'plain_text',
                'text' => 'Please confirm this action.',
            ],
            'confirm' => [
                'type' => 'plain_text',
                'text' => 'Yes',
            ],
            'deny' => [
                'type' => 'plain_text',
                'text' => 'No',
            ],
            'style' => 'danger',
        ], $object->toArray());
    }

    public function testDenyIsCustomizable(): void
    {
        $object = new ConfirmObject();
        $object->deny('Custom deny button.');

        $this->assertSame([
            'title' => [
                'type' => 'plain_text',
                'text' => 'Are you sure?',
            ],
            'text' => [
                'type' => 'plain_text',
                'text' => 'Please confirm this action.',
            ],
            'confirm' => [
                'type' => 'plain_text',
                'text' => 'Yes',
            ],
            'deny' => [
                'type' => 'plain_text',
                'text' => 'Custom deny button.',
            ],
        ], $object->toArray());
    }

    public function testDenyTruncatedOverThirtyCharacters(): void
    {
        $object = new ConfirmObject();
        $object->deny(str_repeat('a', 31));

        $this->assertSame([
            'title' => [
                'type' => 'plain_text',
                'text' => 'Are you sure?',
            ],
            'text' => [
                'type' => 'plain_text',
                'text' => 'Please confirm this action.',
            ],
            'confirm' => [
                'type' => 'plain_text',
                'text' => 'Yes',
            ],
            'deny' => [
                'type' => 'plain_text',
                'text' => str_repeat('a', 27) . '...',
            ],
        ], $object->toArray());
    }
}
