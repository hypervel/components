<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class DatabaseProcessorTest extends TestCase
{
    use VerifiesDoubles;

    public function testInsertGetIdProcessing(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('insert')->with('sql', ['foo']);
        $connection->expects('getLastInsertId')->with('id')->returns('1');
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('getConnection')->times(2)->returns($connection);
        $processor = new Processor;
        $result = $processor->processInsertGetId($builder, 'sql', ['foo'], 'id');
        $this->assertSame(1, $result);
    }

    public function testInsertGetIdPreservesTheConnectionFailureAfterTheInsertCompletes(): void
    {
        $failure = new RuntimeException('The database driver could not retrieve the last insert ID.');
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('insert')->with('sql', ['foo'])->returns(true);
        $connection->expects('getLastInsertId')->with('id')->throws($failure);
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('getConnection')->times(2)->returns($connection);

        $exception = null;

        try {
            (new Processor)->processInsertGetId($builder, 'sql', ['foo'], 'id');
        } catch (RuntimeException $thrown) {
            $exception = $thrown;
        }

        $this->assertSame($failure, $exception);
    }
}
