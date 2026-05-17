<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Exception;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CollectionStaticStateTest extends TestCase
{
    #[DataProvider('collectionClassProvider')]
    public function testFlushStateClearsMacrosAndProxies(string $collection)
    {
        $collection::macro('adults', function (callable $callback) {
            return $this->filter(fn (array $item): bool => $callback($item) >= 18);
        });

        $collection::proxy('adults');

        $this->assertTrue($collection::hasMacro('adults'));

        $instance = new $collection([['age' => 17], ['age' => 30]]);
        $this->assertSame([['age' => 30]], $instance->adults->age->values()->all());

        $collection::flushState();

        $this->assertFalse($collection::hasMacro('adults'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Property [adults] does not exist on this collection instance.');

        $instance->adults;
    }

    /**
     * Provides each collection class.
     */
    public static function collectionClassProvider(): array
    {
        return [
            [Collection::class],
            [LazyCollection::class],
        ];
    }
}
