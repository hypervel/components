<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers\GateWatcherTest;

use Exception;
use Hypervel\Auth\Access\Response;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Auth\Access\AuthorizesRequests;
use Hypervel\Support\Facades\Gate;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\GateWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use ReflectionMethod;

#[WithConfig('telescope.watchers', [
    GateWatcher::class => [
        'enabled' => true,
        'ignore_paths' => ['/src/'],
    ],
])]
class GateWatcherTest extends FeatureTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $gate = $app->make(GateContract::class);

        $gate->define('potato', function (User $user) {
            return $user->email === 'allow';
        });

        $gate->define('guest potato', function (?User $user) {
            return true;
        });

        $gate->define('deny potato', function (?User $user) {
            return false;
        });

        $gate->define('potato message', function (User $user) {
            return $user->email === 'allow' ? Response::allow('allow potato') : Response::deny('allow potato');
        });

        $gate->define('guest potato message', function (?User $user) {
            return Response::allow();
        });

        $gate->define('deny potato message', function (?User $user) {
            return Response::deny('deny potato');
        });
    }

    public function testGateWatcherRegistersAllowedEntries()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        $expectedLine = __LINE__ + 1;
        $check = Gate::forUser(new User('allow'))->check('potato');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertTrue($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame($expectedLine, $entry->content['line']);
        $this->assertSame('potato', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertEmpty($entry->content['arguments']);
    }

    public function testGateWatcherRegistersDeniedEntries()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        $expectedLine = __LINE__ + 1;
        $check = Gate::forUser(new User('deny'))->check('potato', ['banana']);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertFalse($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame($expectedLine, $entry->content['line']);
        $this->assertSame('potato', $entry->content['ability']);
        $this->assertSame('denied', $entry->content['result']);
        $this->assertSame(['banana'], $entry->content['arguments']);
    }

    public function testGateWatcherRegistersAllowedGuestEntries()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        $expectedLine = __LINE__ + 1;
        $check = Gate::check('guest potato');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertTrue($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame($expectedLine, $entry->content['line']);
        $this->assertSame('guest potato', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertEmpty($entry->content['arguments']);
    }

    public function testGateWatcherRegistersAllowedEntriesWithMessage()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        $expectedLine = __LINE__ + 1;
        $check = Gate::forUser(new User('allow'))->check('potato message');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertTrue($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame($expectedLine, $entry->content['line']);
        $this->assertSame('potato message', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertEmpty($entry->content['arguments']);
    }

    public function testGateWatcherRegistersDeniedEntriesWithMessage()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        $expectedLine = __LINE__ + 1;
        $check = Gate::forUser(new User('deny'))->check('potato message', ['banana']);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertFalse($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame($expectedLine, $entry->content['line']);
        $this->assertSame('potato message', $entry->content['ability']);
        $this->assertSame('denied', $entry->content['result']);
        $this->assertSame(['banana'], $entry->content['arguments']);
    }

    public function testGateWatcherRegistersAllowedGuestEntriesWithMessage()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        $expectedLine = __LINE__ + 1;
        $check = Gate::check('guest potato message');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertTrue($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame($expectedLine, $entry->content['line']);
        $this->assertSame('guest potato message', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertEmpty($entry->content['arguments']);
    }

    public function testGateWatcherRegistersDeniedGuestEntries()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        $expectedLine = __LINE__ + 1;
        $check = Gate::check('deny potato', ['gelato']);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertFalse($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame($expectedLine, $entry->content['line']);
        $this->assertSame('deny potato', $entry->content['ability']);
        $this->assertSame('denied', $entry->content['result']);
        $this->assertSame(['gelato'], $entry->content['arguments']);
    }

    public function testGateWatcherRegistersAllowedPolicyEntries()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        Gate::policy(TestResource::class, TestPolicy::class);

        (new TestController)->create(new TestResource);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame((new ReflectionMethod(TestController::class, 'create'))->getStartLine() + 2, $entry->content['line']);
        $this->assertSame('create', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertSame([[]], $entry->content['arguments']);
        $this->assertNull($entry->content['message']);
    }

    public function testGateWatcherRegistersAfterChecks()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        Gate::after(function (?User $user) {
            return true;
        });

        $expectedLine = __LINE__ + 1;
        $check = Gate::check('foo-bar');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertTrue($check);
        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame($expectedLine, $entry->content['line']);
        $this->assertSame('foo-bar', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertEmpty($entry->content['arguments']);
    }

    public function testGateWatcherRegistersDeniedPolicyEntries()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        Gate::policy(TestResource::class, TestPolicy::class);

        try {
            (new TestController)->update(new TestResource);
        } catch (Exception $ex) {
            // ignore
        }

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame((new ReflectionMethod(TestController::class, 'update'))->getStartLine() + 2, $entry->content['line']);
        $this->assertSame('update', $entry->content['ability']);
        $this->assertSame('denied', $entry->content['result']);
        $this->assertSame([[]], $entry->content['arguments']);
        $this->assertNull($entry->content['message']);
    }

    public function testGateWatcherCallsFormatForTelescopeMethodIfItExists()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        Gate::policy(TestResourceWithFormatForTelescope::class, TestPolicy::class);

        try {
            (new TestController)->update(new TestResourceWithFormatForTelescope);
        } catch (Exception $ex) {
            // ignore
        }

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame((new ReflectionMethod(TestController::class, 'update'))->getStartLine() + 2, $entry->content['line']);
        $this->assertSame('update', $entry->content['ability']);
        $this->assertSame('denied', $entry->content['result']);
        $this->assertSame([['Telescope', 'Laravel', 'PHP']], $entry->content['arguments']);
    }

    public function testGateWatcherRegistersAllowedResponsePolicyEntries()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        Gate::policy(TestResource::class, TestPolicy::class);

        try {
            (new TestController)->view(new TestResource);
        } catch (Exception $ex) {
            // ignore
        }

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame((new ReflectionMethod(TestController::class, 'view'))->getStartLine() + 2, $entry->content['line']);
        $this->assertSame('view', $entry->content['ability']);
        $this->assertSame('allowed', $entry->content['result']);
        $this->assertSame([[]], $entry->content['arguments']);
        $this->assertSame('this action is allowed', $entry->content['message']);
    }

    public function testGateWatcherRegistersDeniedResponsePolicyEntries()
    {
        $this->app->setBasePath(dirname(__FILE__, 4));

        Gate::policy(TestResource::class, TestPolicy::class);

        try {
            (new TestController)->delete(new TestResource);
        } catch (Exception $ex) {
            // ignore
        }

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::GATE, $entry->type);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame((new ReflectionMethod(TestController::class, 'delete'))->getStartLine() + 2, $entry->content['line']);
        $this->assertSame('delete', $entry->content['ability']);
        $this->assertSame('denied', $entry->content['result']);
        $this->assertSame([[]], $entry->content['arguments']);
        $this->assertSame('this action is denied', $entry->content['message']);
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

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return 'i-am-telescope';
    }

    public function setRememberToken(string $value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
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
