<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Exception;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Auth\Access\AuthorizesRequests;
use Hypervel\Auth\Access\Gate;
use Hypervel\Auth\Access\Response;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Auth\Contracts\Gate as GateContract;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\GateWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
class GateWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get(ConfigInterface::class)
            ->set('telescope.watchers', [
                GateWatcher::class => true,
            ]);

        $this->mockGate();

        $this->startTelescope();
    }

    public function testGateWatcherRegistersAllowedEntries()
    {
        $check = $this->app->get(GateContract::class)
            ->forUser(new User('allow'))
            ->check('potato');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertTrue($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame('potato', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertEmpty($entry->content['arguments']);
    }

    public function testGateWatcherRegistersDeniedEntries()
    {
        $check = $this->app->get(GateContract::class)
            ->forUser(new User('deny'))
            ->check('potato', ['banana']);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertFalse($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame('potato', $entry->content['ability']);
        $this->assertSame('denied', $entry->content['result']);
        $this->assertSame(['banana'], $entry->content['arguments']);
    }

    public function testGateWatcherRegistersAllowedGuestEntries()
    {
        $check = $this->app->get(GateContract::class)
            ->check('guest potato');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertTrue($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame('guest potato', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertEmpty($entry->content['arguments']);
    }

    public function testGateWatcherRegistersDeniedGuestEntries()
    {
        $check = $this->app->get(GateContract::class)
            ->check('deny potato', ['gelato']);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertFalse($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame('deny potato', $entry->content['ability']);
        $this->assertSame('denied', $entry->content['result']);
        $this->assertSame(['gelato'], $entry->content['arguments']);
    }

    public function testGateWatcherRegistersAllowedPolicyEntries()
    {
        $this->app->get(GateContract::class)
            ->policy(TestResource::class, TestPolicy::class);

        (new TestController())->create(new TestResource());

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame('create', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertSame([[]], $entry->content['arguments']);
    }

    public function testGateWatcherRegistersAfterChecks()
    {
        $this->app->get(GateContract::class)
            ->after(function (?User $user) {
                return true;
            });

        $check = $this->app->get(GateContract::class)
            ->check('foo-bar');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertTrue($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame('foo-bar', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertEmpty($entry->content['arguments']);
    }

    public function testGateWatcherRegistersDeniedPolicyEntries()
    {
        $this->app->get(GateContract::class)
            ->policy(TestResource::class, TestPolicy::class);

        try {
            (new TestController())->update(new TestResource());
        } catch (Exception $ex) {
            // ignore
        }

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame('update', $entry->content['ability']);
        $this->assertSame('denied', $entry->content['result']);
        $this->assertSame([[]], $entry->content['arguments']);
    }

    public function testGateWatcherCallsFormatForTelescopeMethodIfItExists()
    {
        $this->app->get(GateContract::class)
            ->policy(TestResourceWithFormatForTelescope::class, TestPolicy::class);

        try {
            (new TestController())->update(new TestResourceWithFormatForTelescope());
        } catch (Exception $ex) {
            // ignore
        }

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame('update', $entry->content['ability']);
        $this->assertSame('denied', $entry->content['result']);
        $this->assertSame([['Telescope', 'Laravel', 'PHP']], $entry->content['arguments']);
    }

    public function testGateWatcherRegistersAllowedResponsePolicyEntries()
    {
        $this->app->get(GateContract::class)
            ->policy(TestResource::class, TestPolicy::class);

        try {
            (new TestController())->view(new TestResource());
        } catch (Exception $ex) {
            // ignore
        }

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame('view', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertSame([[]], $entry->content['arguments']);
    }

    public function testGateWatcherRegistersDeniedResponsePolicyEntries()
    {
        $this->app->get(GateContract::class)
            ->policy(TestResource::class, TestPolicy::class);

        try {
            (new TestController())->delete(new TestResource());
        } catch (Exception $ex) {
            // ignore
        }

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame('delete', $entry->content['ability']);
        $this->assertSame('denied', $entry->content['result']);
        $this->assertSame([[]], $entry->content['arguments']);
    }

    protected function mockGate(): void
    {
        $gate = new Gate($this->app, function () {
            return new User('email@foo.bar');
        });

        $gate->define('potato', function (User $user) {
            return $user->email === 'allow';
        });

        $gate->define('guest potato', function (?User $user) {
            return true;
        });

        $gate->define('deny potato', function (?User $user) {
            return false;
        });

        $this->app->set(GateContract::class, $gate);
    }
}

class User implements Authenticatable
{
    public $email;

    public function __construct($email)
    {
        $this->email = $email;
    }

    public function getAuthIdentifierName(): string
    {
        return 'Telescope Test';
    }

    public function getAuthIdentifier(): string
    {
        return 'telescope-test';
    }

    public function getAuthPassword(): string
    {
        return 'secret';
    }

    public function getAuthPasswordName()
    {
        return 'passord name';
    }

    public function getRememberToken()
    {
        return 'i-am-telescope';
    }

    public function setRememberToken($value)
    {
    }

    public function getRememberTokenName()
    {
    }
}

class TestResource
{
}

class TestResourceWithFormatForTelescope
{
    public function formatForTelescope(): array
    {
        return [
            'Telescope',
            'Laravel',
            'PHP',
        ];
    }
}

class TestController
{
    use AuthorizesRequests;

    public function view($object)
    {
        $this->authorize($object);
    }

    public function create($object)
    {
        $this->authorize($object);
    }

    public function update($object)
    {
        $this->authorize($object);
    }

    public function delete($object)
    {
        $this->authorize($object);
    }
}

class TestPolicy
{
    public function view(?User $user)
    {
        return Response::allow('this action is allowed');
    }

    public function create(?User $user)
    {
        return true;
    }

    public function update(?User $user)
    {
        return false;
    }

    public function delete(?User $user)
    {
        return Response::deny('this action is denied');
    }
}
