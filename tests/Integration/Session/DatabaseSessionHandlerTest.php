<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Session;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Http\Request;
use Hypervel\Session\DatabaseSessionHandler;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

#[WithMigration('session')]
class DatabaseSessionHandlerTest extends DatabaseTestCase
{
    public function testBasicReadWriteFunctionality(): void
    {
        RequestContext::set(Request::create('/', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'Test/1.0',
        ]));

        $resolver = $this->app->make('db');
        $connection = $this->app['db']->connection();
        $handler = new DatabaseSessionHandler($resolver, null, 'sessions', 1);
        $handler->setContainer($this->app);

        // read non-existing session id:
        $this->assertEquals('', $handler->read('invalid_session_id'));

        // open and close:
        $this->assertTrue($handler->open('', ''));
        $this->assertTrue($handler->close());

        // write and read:
        $this->assertTrue($handler->write('valid_session_id_2425', json_encode(['foo' => 'bar'])));
        $this->assertEquals(['foo' => 'bar'], json_decode($handler->read('valid_session_id_2425'), true));
        $this->assertEquals(1, $connection->table('sessions')->count());

        $session = $connection->table('sessions')->first();
        $this->assertNotNull($session->user_agent);
        $this->assertNotNull($session->ip_address);

        // re-write and read:
        $this->assertTrue($handler->write('valid_session_id_2425', json_encode(['over' => 'ride'])));
        $this->assertEquals(['over' => 'ride'], json_decode($handler->read('valid_session_id_2425'), true));
        $this->assertEquals(1, $connection->table('sessions')->count());

        // handler object writes only one session id:
        $this->assertTrue($handler->write('other_id', 'data'));
        $this->assertEquals(1, $connection->table('sessions')->count());

        $handler->setExists(false);
        $this->assertTrue($handler->write('other_id', 'data'));
        $this->assertEquals(2, $connection->table('sessions')->count());

        // read expired:
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
        $this->assertEquals('', $handler->read('valid_session_id_2425'));

        // rewriting an expired session-id, makes it live:
        $this->assertTrue($handler->write('valid_session_id_2425', json_encode(['come' => 'alive'])));
        $this->assertEquals(['come' => 'alive'], json_decode($handler->read('valid_session_id_2425'), true));
    }

    public function testGarbageCollector(): void
    {
        $resolver = $this->app->make('db');
        $connection = $this->app['db']->connection();

        $handler = new DatabaseSessionHandler($resolver, null, 'sessions', 1, $this->app);
        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $handler->write('simple_id_1', 'abcd');
        $this->assertEquals(0, $handler->gc(1));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

        $handler = new DatabaseSessionHandler($resolver, null, 'sessions', 1, $this->app);
        $handler->write('simple_id_2', 'abcd');
        $this->assertEquals(1, $handler->gc(2));
        $this->assertEquals(1, $connection->table('sessions')->count());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

        $this->assertEquals(1, $handler->gc(1));
        $this->assertEquals(0, $connection->table('sessions')->count());
    }

    public function testDestroy(): void
    {
        $resolver = $this->app->make('db');
        $connection = $this->app['db']->connection();
        $handler1 = new DatabaseSessionHandler($resolver, null, 'sessions', 1, $this->app);
        $handler2 = clone $handler1;

        $handler1->write('id_1', 'some data');
        $handler2->write('id_2', 'some data');

        // destroy invalid session-id:
        $this->assertTrue($handler1->destroy('invalid_session_id'));
        // nothing deleted:
        $this->assertEquals(2, $connection->table('sessions')->count());

        // destroy valid session-id:
        $this->assertTrue($handler2->destroy('id_1'));
        // only one row is deleted:
        $this->assertEquals(1, $connection->table('sessions')->where('id', 'id_2')->count());
    }

    public function testItCanWorkWithoutContainer(): void
    {
        $resolver = $this->app->make('db');
        $connection = $this->app['db']->connection();
        $handler = new DatabaseSessionHandler($resolver, null, 'sessions', 1);

        // write and read:
        $this->assertTrue($handler->write('session_id', 'some data'));
        $this->assertEquals('some data', $handler->read('session_id'));
        $this->assertEquals(1, $connection->table('sessions')->count());

        $session = $connection->table('sessions')->first();
        $this->assertNull($session->user_agent);
        $this->assertNull($session->ip_address);
        $this->assertNull($session->user_id);
    }

    public function testDirectWriteUpdatesAnExistingSessionWithoutAttemptingDuplicateInsert(): void
    {
        $resolver = $this->app->make('db');
        $connection = $resolver->connection();
        $connection->table('sessions')->insert([
            'id' => 'existing-session',
            'payload' => base64_encode('old data'),
            'last_activity' => time(),
        ]);
        $handler = new TrackingDatabaseSessionHandler($resolver, null, 'sessions', 120);

        $this->assertTrue($handler->write('existing-session', 'new data'));
        $this->assertSame(0, $handler->insertCount);
        $this->assertSame(1, $handler->updateCount);
        $this->assertSame('new data', $handler->read('existing-session'));
    }

    public function testConstructionClearsStaleObjectSpecificExistenceState(): void
    {
        $resolver = $this->app->make('db');
        $handler = new class($resolver) extends TrackingDatabaseSessionHandler {
            public function __construct(ConnectionResolverInterface $resolver)
            {
                CoroutineContext::set(
                    self::DATABASE_EXISTS_CONTEXT_KEY_PREFIX . spl_object_id($this),
                    true
                );

                parent::__construct($resolver, null, 'sessions', 120);
            }
        };

        $this->assertFalse($handler->getExists());
        $this->assertTrue($handler->write('new-session', 'new data'));
        $this->assertSame(1, $handler->insertCount);
        $this->assertSame(0, $handler->updateCount);
        $this->assertSame('new data', $handler->read('new-session'));
    }

    public function testCloningClearsStaleObjectSpecificExistenceStateWithoutChangingSource(): void
    {
        $resolver = $this->app->make('db');
        $source = new class($resolver) extends TrackingDatabaseSessionHandler {
            public function __construct(ConnectionResolverInterface $resolver)
            {
                parent::__construct($resolver, null, 'sessions', 120);
            }

            public function __clone(): void
            {
                CoroutineContext::set(
                    self::DATABASE_EXISTS_CONTEXT_KEY_PREFIX . spl_object_id($this),
                    true
                );

                parent::__clone();
            }
        };
        $source->setExists(true);

        $clone = clone $source;

        $this->assertTrue($source->getExists());
        $this->assertFalse($clone->getExists());
        $this->assertTrue($clone->write('cloned-session', 'cloned data'));
        $this->assertSame(1, $clone->insertCount);
        $this->assertSame(0, $clone->updateCount);
        $this->assertSame('cloned data', $clone->read('cloned-session'));
        $this->assertTrue($source->getExists());
    }
}

class TrackingDatabaseSessionHandler extends DatabaseSessionHandler
{
    public int $insertCount = 0;

    public int $updateCount = 0;

    protected function performInsert(string $sessionId, array $payload): ?bool
    {
        ++$this->insertCount;

        return parent::performInsert($sessionId, $payload);
    }

    protected function performUpdate(string $sessionId, array $payload): int
    {
        ++$this->updateCount;

        return parent::performUpdate($sessionId, $payload);
    }
}
