<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Closure;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\DatabaseCondition;
use Hypervel\Validation\DatabaseConditionOperator;
use Hypervel\Validation\DatabaseConditions;
use Hypervel\Validation\DatabasePresenceVerifier;
use Hypervel\Validation\PresenceQuery;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Validator;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;

class ValidationDatabasePresenceVerifierTest extends TestCase
{
    use VerifiesDoubles;

    public function testBasicCount(): void
    {
        $db = Double::for(ConnectionResolverInterface::class)->strict();
        $verifier = new DatabasePresenceVerifier($db);
        $conn = Double::for(Connection::class)->strict();
        $db->expects('connection')->with('connection')->returns($conn);
        $builder = Double::for(Builder::class)->strict();
        $conn->expects('table')->with('table')->returns($builder);
        $builder->expects('useWritePdo')->returns($builder);
        $builder->expects('where')->with('column', '=', 'value')->returns($builder);
        $extra = new DatabaseConditions(
            new DatabaseCondition('foo', DatabaseConditionOperator::IsNull),
            new DatabaseCondition('bar', DatabaseConditionOperator::IsNotNull),
            new DatabaseCondition('baz', DatabaseConditionOperator::Equals, 'taylor'),
            new DatabaseCondition('faz', DatabaseConditionOperator::Equals, true),
            new DatabaseCondition('not', DatabaseConditionOperator::NotEquals, 'admin'),
            new DatabaseCondition('role', DatabaseConditionOperator::In, ['reader', 'editor']),
            new DatabaseCondition('role', DatabaseConditionOperator::NotIn, ['admin']),
        );
        $builder->expects('whereNull')->with('foo');
        $builder->expects('whereNotNull')->with('bar');
        $builder->expects('where')->with('baz', '=', 'taylor');
        $builder->expects('where')->with('faz', '=', '1');
        $builder->expects('where')->with('not', '!=', 'admin');
        $builder->expects('whereIn')->with('role', ['reader', 'editor']);
        $builder->expects('whereNotIn')->with('role', ['admin']);
        $builder->expects('count')->returns(100);

        $this->assertSame(100, $verifier->getCount(new PresenceQuery('connection', 'table', 'column', $extra), 'value'));
    }

    public function testBasicCountWithClosures(): void
    {
        $db = Double::for(ConnectionResolverInterface::class)->strict();
        $verifier = new DatabasePresenceVerifier($db);
        $conn = Double::for(Connection::class)->strict();
        $db->expects('connection')->with('connection')->returns($conn);
        $builder = Double::for(Builder::class)->strict();
        $conn->expects('table')->with('table')->returns($builder);
        $builder->expects('useWritePdo')->returns($builder);
        $builder->expects('where')->with('column', '=', 'value')->returns($builder);
        $closure = function (Builder $query): void {
            $query->where('closure', 1);
        };
        $extra = new DatabaseConditions(
            new DatabaseCondition('foo', DatabaseConditionOperator::IsNull),
            new DatabaseCondition('bar', DatabaseConditionOperator::IsNotNull),
            new DatabaseCondition('baz', DatabaseConditionOperator::Equals, 'taylor'),
            new DatabaseCondition('faz', DatabaseConditionOperator::Equals, true),
            new DatabaseCondition('not', DatabaseConditionOperator::NotEquals, 'admin'),
            $closure,
        );
        $builder->expects('whereNull')->with('foo');
        $builder->expects('whereNotNull')->with('bar');
        $builder->expects('where')->with('baz', '=', 'taylor');
        $builder->expects('where')->with('faz', '=', '1');
        $builder->expects('where')->with('not', '!=', 'admin');
        $builder->expects('where')->with(Argument::type(Closure::class))->resolves(function (Closure $callback) use ($builder): Builder {
            $callback($builder);

            return $builder;
        });
        $builder->expects('where')->with('closure', 1);
        $builder->expects('count')->returns(100);

        $this->assertSame(100, $verifier->getCount(new PresenceQuery('connection', 'table', 'column', $extra), 'value'));
    }

    public function testGetCountWithValidExcludeId(): void
    {
        $db = Double::for(ConnectionResolverInterface::class)->strict();
        $verifier = new DatabasePresenceVerifier($db);
        $conn = Double::for(Connection::class)->strict();
        $db->expects('connection')->with('connection')->returns($conn);
        $builder = Double::for(Builder::class)->strict();
        $conn->expects('table')->with('table')->returns($builder);
        $builder->expects('useWritePdo')->returns($builder);
        $builder->expects('where')->with('column', '=', 'value')->returns($builder);
        $builder->expects('where')->with('id', '<>', 123)->returns($builder);
        $builder->expects('count')->returns(100);

        $this->assertSame(100, $verifier->getCount(new PresenceQuery('connection', 'table', 'column', new DatabaseConditions, 123, 'id'), 'value'));
    }

    public function testIntegersAndBooleansAreComparedAsStrings(): void
    {
        $db = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();
        $db->expects('connection')->with('connection')->times(3)->returns($connection);
        $verifier = new DatabasePresenceVerifier($db);
        $builder = Double::for(Builder::class)->strict();
        $connection->expects('table')->with('table')->times(3)->returns($builder);
        $builder->expects('useWritePdo')->times(3)->returns($builder);
        $builder->expects('where')->with('column', '=', '0')->returns($builder);
        $builder->expects('count')->with()->returns(0);

        $this->assertSame(0, $verifier->getCount(new PresenceQuery('connection', 'table', 'column', new DatabaseConditions), 0));

        $builder->expects('whereIn')->with('column', ['0', '1', '7', 'value'])->times(2)->returns($builder);
        $builder->expects('distinct')->times(2)->returns($builder);
        $builder->expects('count')->with('column')->returns(0);

        $this->assertSame(0, $verifier->getMultiCount(new PresenceQuery('connection', 'table', 'column', new DatabaseConditions), [false, true, 7, 'value']));

        $builder->expects('pluck')->with('column')->returns(new Collection(['0', '1', '7', 'value']));

        $verifier->getExistingValues(new PresenceQuery('connection', 'table', 'column', new DatabaseConditions), [false, true, 7, 'value']);
    }

    #[DataProvider('connections')]
    public function testGetExistingValuesUsesRequestedConnectionAndReturnsDistinctValues(?string $connection): void
    {
        $verifier = new DatabasePresenceVerifier($db = Double::for(ConnectionResolverInterface::class)->strict());
        $db->expects('connection')->with($connection)->returns($database = Double::for(Connection::class)->strict());
        $database->expects('table')->with('table')->returns($builder = Double::for(Builder::class)->strict());
        $builder->expects('useWritePdo')->returns($builder);
        $builder->expects('whereIn')->with('column', ['first', 'second'])->returns($builder);
        $builder->expects('where')->with('uuid', '<>', 'ignored')->returns($builder);
        $builder->expects('whereNull')->with('deleted_at');
        $builder->expects('where')->with('status', '=', 'active');
        $builder->expects('distinct')->returns($builder);
        $builder->expects('pluck')->with('column')->returns(new Collection(['first']));

        $this->assertSame(['first'], $verifier->getExistingValues(
            new PresenceQuery($connection, 'table', 'column', new DatabaseConditions(
                new DatabaseCondition('deleted_at', DatabaseConditionOperator::IsNull),
                new DatabaseCondition('status', DatabaseConditionOperator::Equals, 'active'),
            ), 'ignored', 'uuid'),
            ['first', 'second'],
        ));
    }

    /**
     * Provide database connections.
     */
    public static function connections(): array
    {
        return [['named'], [null]];
    }

    public function testNestedValidationCannotChangeTheOuterConnection(): void
    {
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $verifier = new DatabasePresenceVerifier($resolver);
        $translator = new Translator(new ArrayLoader, 'en');
        $inner = new Validator($translator, ['email' => 'inner@example.com'], [
            'email' => new Exists('inner.users', 'email'),
        ]);
        $inner->setPresenceVerifier($verifier);
        $rule = new class($inner) extends Exists {
            /**
             * Retain the nested validation used while resolving conditions.
             */
            public function __construct(private Validator $inner)
            {
                parent::__construct('outer.users', 'email');
            }

            /**
             * Resolve conditions after another validation uses the shared verifier.
             */
            public function queryConditions(): DatabaseConditions
            {
                ValidationDatabasePresenceVerifierTest::assertTrue($this->inner->passes());

                return parent::queryConditions();
            }
        };
        // Exercise late condition resolution through the delegated validator path.
        $outer = new class($translator, ['email' => 'outer@example.com'], ['email' => $rule]) extends Validator {
        };
        $outer->setPresenceVerifier($verifier);

        foreach (['inner' => 1, 'outer' => 0] as $name => $count) {
            $connection = Double::for(Connection::class)->strict();
            $builder = Double::for(Builder::class)->strict();
            $resolver->expects('connection')->with($name)->returns($connection);
            $connection->expects('table')->with('users')->returns($builder);
            $builder->expects('useWritePdo')->returns($builder);
            $builder->expects('where')->with('email', '=', $name . '@example.com')->returns($builder);
            $builder->expects('count')->returns($count);
        }

        $this->assertFalse($outer->passes());
    }
}
