<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Passport\AuthorizationRequestResolver;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Bridge\User;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Http\Responses\AuthorizationResponse;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;

class AuthorizationRequestResolverTest extends PassportTestCase
{
    #[TestWith([null])]
    #[TestWith(['   '])]
    #[TestWith(['https://client.example/callback?source=calendar'])]
    public function testRestoringKeepsTheDestinationAndOriginalRedirectRequirement(?string $redirect): void
    {
        $destination = 'https://client.example/callback?source=calendar';
        $client = ClientFactory::new()->create(['redirect_uris' => [$destination]]);
        $verifier = str_repeat('a', 64);
        $parameters = [
            'client_id' => $client->id, 'response_type' => 'code', 'state' => 'original-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];

        if ($redirect !== null) {
            $parameters['redirect_uri'] = $redirect;
        }

        $servers = $this->app->make(ServerFactory::class);
        $servers->authorizationServer()->validateAuthorizationRequest(
            (new ServerRequest('GET', '/oauth/authorize'))->withQueryParams($parameters),
        );
        $pending = ['parameters' => $parameters, 'scopes' => [], 'redirect_uri' => $destination, 'pushed' => false];
        $client->update(['redirect_uris' => ['https://client.example/new-default', $destination]]);
        $restored = $this->app->make(AuthorizationRequestResolver::class)->resolve(
            $pending,
            (new ServerRequest('POST', '/oauth/authorize/consent'))->withQueryParams(['state' => 'override']),
        );
        $restored->setUser(new User('42'));
        $restored->setAuthorizationApproved(true);
        $response = TokenIssuance::run($servers, fn () => $servers->authorizationServer()->completeAuthorizationRequest($restored, new PsrResponse));
        $rendered = $this->app->make(AuthorizationResponse::class)->success(
            new Response((string) $response->getBody(), $response->getStatusCode(), $response->getHeaders()),
            $destination,
            $parameters['state'],
            'query',
        );
        $this->assertStringStartsWith($destination . '&', $rendered->headers->get('Location'));
        parse_str(parse_url($rendered->headers->get('Location'), PHP_URL_QUERY), $result);
        $this->assertSame('calendar', $result['source']);
        $this->assertSame('original-state', $result['state']);
        $exchange = [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'code' => $result['code'], 'code_verifier' => $verifier,
        ];

        if ($redirect !== null && trim($redirect) !== '') {
            $this->post('/oauth/token', $exchange)->assertBadRequest()->assertJsonPath('error', 'invalid_request');
            $exchange['redirect_uri'] = $redirect;
        }

        $this->post('/oauth/token', $exchange)->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function testRestoringEmptyScopesDoesNotApplyNewDefaults(): void
    {
        $client = ClientFactory::new()->create();
        Passport::tokensCan(['read' => 'Read']);
        $servers = $this->app->make(ServerFactory::class);
        $servers->authorizationServer()->setDefaultScope('read');
        $restored = $this->app->make(AuthorizationRequestResolver::class)->resolve([
            'parameters' => ['client_id' => $client->id, 'response_type' => 'code'],
            'scopes' => [], 'redirect_uri' => $client->redirect_uris[0], 'pushed' => false,
        ], new ServerRequest('GET', '/oauth/authorize/resume/pending'));

        $this->assertSame([], $restored->getScopes());
    }

    public function testRestoringRejectsARemovedRedirectDestination(): void
    {
        $client = ClientFactory::new()->create(['redirect_uris' => ['https://client.example/new']]);

        try {
            $this->app->make(AuthorizationRequestResolver::class)->resolve([
                'parameters' => ['client_id' => $client->id, 'response_type' => 'code'],
                'scopes' => [], 'redirect_uri' => 'https://client.example/old', 'pushed' => false,
            ], new ServerRequest('GET', '/oauth/authorize/resume/pending'));
            $this->fail('The removed callback was accepted.');
        } catch (HttpResponseException $exception) {
            $this->assertFalse($exception->getResponse()->headers->has('Location'));
            $this->assertSame('invalid_client', json_decode($exception->getResponse()->getContent(), true)['error']);
        }
    }

    #[DataProvider('authorizationErrors')]
    public function testAuthorizationErrorsUseOnlyValidatedCallbacks(array $parameters, array $attributes, string $error, bool $redirect): void
    {
        Passport::authorizationView(fn (array $parameters): array => $parameters);
        $destination = 'https://client.example/callback?source=calendar';
        $client = ClientFactory::new()->create(['redirect_uris' => [$destination], ...$attributes]);
        $parameters = [
            'client_id' => $client->id,
            'response_type' => 'code',
            'state' => 'state<&',
            ...$parameters,
        ];
        $response = $this->get('/oauth/authorize?' . http_build_query($parameters));

        if (! $redirect) {
            $response->assertStatus($error === 'invalid_client' ? 401 : 400)
                ->assertHeaderMissing('Location')->assertJsonPath('error', $error);
        } elseif (($parameters['response_mode'] ?? null) === 'form_post') {
            $response->assertOk()->assertHeaderMissing('Location');
            $response->assertSee('action="' . $destination . '"', false)
                ->assertSee('name="error" value="' . $error . '"', false)
                ->assertSee('name="state" value="state&lt;&amp;"', false);
        } else {
            $response->assertRedirect();
            $this->assertStringStartsWith($destination . '&', $response->headers->get('Location'));
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $result);
            $this->assertSame('calendar', $result['source']);
            $this->assertSame($error, $result['error']);
            $this->assertSame('state<&', $result['state']);
        }

        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    /**
     * Provide authorization failures before League returns an authorization object.
     */
    public static function authorizationErrors(): iterable
    {
        foreach (['query', 'form_post'] as $mode) {
            yield $mode . ' unsupported response' => [['response_mode' => $mode, 'response_type' => 'token'], [], 'unsupported_response_type', true];
            yield $mode . ' missing response' => [['response_mode' => $mode, 'response_type' => null], [], 'invalid_request', true];
            yield $mode . ' unauthorized client' => [['response_mode' => $mode], ['grant_types' => []], 'unauthorized_client', true];
            yield $mode . ' unknown scope' => [['response_mode' => $mode, 'scope' => 'unknown'], [], 'invalid_scope', true];
            yield $mode . ' plain PKCE' => [['response_mode' => $mode, 'code_challenge' => str_repeat('a', 43)], [], 'invalid_request', true];
        }

        yield 'missing client' => [['client_id' => null], [], 'invalid_request', false];
        yield 'revoked client' => [[], ['revoked' => true], 'invalid_client', false];
        yield 'unregistered callback' => [['redirect_uri' => 'https://other.example'], [], 'invalid_client', false];
        yield 'ambiguous omitted callback' => [[], ['redirect_uris' => ['https://one.example', 'https://two.example']], 'invalid_client', false];
    }
}
