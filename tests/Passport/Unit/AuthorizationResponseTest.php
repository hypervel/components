<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use DOMDocument;
use Hypervel\Passport\Http\Responses\AuthorizationResponse;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerSettings;
use Hypervel\Tests\TestCase;
use League\OAuth2\Server\Exception\OAuthServerException;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;

class AuthorizationResponseTest extends TestCase
{
    #[TestWith([null, false])]
    #[TestWith(['HTTPS://Auth.Example:443/issuer/', false])]
    #[TestWith(['https://auth.example', true])]
    public function testQueryRenderingUsesTheSavedCallbackAndPreservesItsQuery(?string $issuer, bool $error): void
    {
        $issued = new Response('', 302, ['Location' => 'https://new.example/callback?code=encrypted-code']);
        $renderer = $this->renderer($issuer);
        $response = $error
            ? $renderer->error(OAuthServerException::accessDenied(), 'https://client.example/callback?tenant=a', 'original state', 'query')
            : $renderer->success($issued, 'https://client.example/callback?tenant=a', 'original state', 'query');

        $this->assertSame(302, $response->getStatusCode());
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertStringStartsWith('https://client.example/callback?', $response->headers->get('Location'));
        $this->assertSame('a', $parameters['tenant']);
        $this->assertSame('original state', $parameters['state']);
        $this->assertSame($error ? 'access_denied' : 'encrypted-code', $parameters[$error ? 'error' : 'code']);
        $this->assertSame($issuer, $parameters['iss'] ?? null);
        if ($issuer === null) {
            $this->assertSame('https://client.example/callback?tenant=a&code=encrypted-code&state=original+state', $response->headers->get('Location'));
        }
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testFormPostEscapesProtocolFieldsWithoutRestrictingTheClientsSubsequentNavigation(bool $error): void
    {
        $state = '"><script>alert("state")</script>';
        $redirectUri = 'https://client.example/callback?tenant=a&label=%22';
        $renderer = $this->renderer('https://auth.example');
        $response = $error
            ? $renderer->error(OAuthServerException::accessDenied(), $redirectUri, $state, 'form_post')
            : $renderer->success(new Response('', 302, ['Location' => 'https://client.example/callback?code=encrypted-code']), $redirectUri, $state, 'form_post');

        $document = new DOMDocument;
        $document->loadHTML($response->getContent());
        $form = $document->getElementsByTagName('form')->item(0);
        $fields = [];

        foreach ($document->getElementsByTagName('input') as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Location'));
        $this->assertSame('post', $form->getAttribute('method'));
        $this->assertSame($redirectUri, $form->getAttribute('action'));
        $this->assertSame($state, $fields['state']);
        $this->assertSame('https://auth.example', $fields['iss']);
        $this->assertSame($error ? 'access_denied' : 'encrypted-code', $fields[$error ? 'error' : 'code']);
        $this->assertSame(1, $document->getElementsByTagName('script')->length);
        $nonce = $document->getElementsByTagName('script')->item(0)->getAttribute('nonce');
        $this->assertNotSame('', $nonce);
        $this->assertStringContainsString("script-src 'nonce-{$nonce}'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('form-action', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame(1, $document->getElementsByTagName('button')->length);
    }

    public function testLocalErrorsDoNotResolveOrExposeAnIssuer(): void
    {
        $servers = m::mock(ServerFactory::class);
        $servers->shouldNotReceive('settings');
        $response = (new AuthorizationResponse($servers))->error(OAuthServerException::invalidRequest('client_id'), null, null, 'query');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayNotHasKey('iss', json_decode($response->getContent(), true));
    }

    /**
     * Build a renderer for an explicitly selected issuer.
     */
    protected function renderer(?string $issuer): AuthorizationResponse
    {
        $servers = m::mock(ServerFactory::class);
        $servers->shouldReceive('settings')->andReturn(new ServerSettings([], issuer: $issuer));

        return new AuthorizationResponse($servers);
    }
}
