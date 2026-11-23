<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Error;
use Hypervel\Support\Traits\Tappable;
use Hypervel\Tests\TestCase;

class SupportTappableTest extends TestCase
{
    public function testTappableClassWithCallback(): void
    {
        $name = TappableClass::make()->tap(function ($tappable) {
            $tappable->setName('MyName');
        })->getName();

        $this->assertSame('MyName', $name);
    }

    public function testTappableClassWithInvokableClass(): void
    {
        $name = TappableClass::make()->tap(new class {
            public function __invoke($tappable)
            {
                $tappable->setName('MyName');
            }
        })->getName();

        $this->assertSame('MyName', $name);
    }

    public function testTappableClassWithNoneInvokableClass(): void
    {
        $this->expectException(Error::class);

        TappableClass::make()->tap(new class {
            /**
             * Set the tapped object's name.
             */
            public function setName(TappableClass $tappable): void
            {
                $tappable->setName('MyName');
            }
        })->getName();
    }

    public function testTappableClassWithoutCallback(): void
    {
        $name = TappableClass::make()->tap()->setName('MyName')->getName();

        $this->assertSame('MyName', $name);
    }
}

class TappableClass
{
    use Tappable;

    private string $name = '';

    public static function make()
    {
        return new static;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
