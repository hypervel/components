<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\PersonalAccessTokenResult;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;

#[WithConfig('app.url', 'https://hypervel.test')]
class PersonalAccessGrantWithTrustedHostsTest extends PassportTestCase
{
    use WithHypervelMigrations;

    /**
     * Restrict requests to the configured host.
     */
    protected function setUpInCoroutine(): void
    {
        RequestContext::set(Request::create('https://hypervel.test'));
        Request::setTrustedHosts(['hypervel.test']);
    }

    public function testIssueToken(): void
    {
        $user = UserFactory::new()->create();

        ClientFactory::new()->asPersonalAccessTokenClient()->create();

        $result = $user->createToken('test');

        $this->assertInstanceOf(PersonalAccessTokenResult::class, $result);
        $this->assertArrayHasKey('accessToken', $result->toArray());
    }
}
