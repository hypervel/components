<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Hypervel\Http\Request;
use Hypervel\Socialite\Two\SlackProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class SlackProviderTest extends TestCase
{
    public function testDefaultScopeKeyIsUserScope()
    {
        $request = m::mock(Request::class);

        $provider = new SlackProvider(
            $request,
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->stateless();

        $response = $provider->redirect();
        $url = $response->getTargetUrl();

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('', $query['scope']);
        $this->assertArrayHasKey('user_scope', $query);
    }

    public function testAsBotUserChangesScopeKey()
    {
        $request = m::mock(Request::class);

        $provider = new SlackProvider(
            $request,
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->stateless();
        $provider->asBotUser();

        $response = $provider->redirect();
        $url = $response->getTargetUrl();

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        // Bot user uses 'scope' key directly — no 'user_scope' override
        $this->assertNotEmpty($query['scope']);
        $this->assertArrayNotHasKey('user_scope', $query);
    }
}
