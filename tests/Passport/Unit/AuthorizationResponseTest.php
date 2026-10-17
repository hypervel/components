<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use DOMDocument;
use Hypervel\Passport\Http\Responses\AuthorizationResponse;
use Hypervel\Tests\TestCase;
use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;

class AuthorizationResponseTest extends TestCase
{
    public function testQueryRenderingUsesTheSavedCallbackAndPreservesItsQuery(): void
    {
        $issued = new Response('', 302, ['Location' => 'https://new.example/callback?code=encrypted-code']);
        $response = (new AuthorizationResponse)->success($issued, 'https://client.example/callback?tenant=a', 'original state', 'query');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://client.example/callback?tenant=a&code=encrypted-code&state=original+state', $response->headers->get('Location'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testFormPostEscapesProtocolFieldsAndLimitsTheSubmissionTarget(bool $error): void
    {
        $state = '"><script>alert("state")</script>';
        $redirectUri = 'https://client.example/callback?tenant=a&label=%22';
        $renderer = new AuthorizationResponse;
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
        $this->assertSame($error ? 'access_denied' : 'encrypted-code', $fields[$error ? 'error' : 'code']);
        $this->assertSame(1, $document->getElementsByTagName('script')->length);
        $nonce = $document->getElementsByTagName('script')->item(0)->getAttribute('nonce');
        $this->assertNotSame('', $nonce);
        $this->assertStringContainsString("script-src 'nonce-{$nonce}'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('form-action https://client.example;', $response->headers->get('Content-Security-Policy'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame(1, $document->getElementsByTagName('button')->length);
    }
}
