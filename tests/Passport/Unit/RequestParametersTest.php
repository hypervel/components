<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Http\Request;
use Hypervel\Passport\Http\RequestParameters;
use Hypervel\Tests\TestCase;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\TestWith;

class RequestParametersTest extends TestCase
{
    #[TestWith(['GET'])]
    #[TestWith(['POST'])]
    public function testRepeatedResourcesAndEncodedScalarsSurviveParsing(string $method): void
    {
        $encoded = 'client_id=client&state=a%26b%3Dc&resource=https%3A%2F%2Fapi.example%2Fa&resource=https%3A%2F%2Fapi.example%2Fb';
        $request = $method === 'GET'
            ? Request::create('/oauth/authorize?' . $encoded)
            : Request::create('/oauth/authorize', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $encoded);

        $this->assertSame([
            'client_id' => 'client', 'state' => 'a&b=c',
            'resource' => ['https://api.example/a', 'https://api.example/b'],
        ], RequestParameters::fromRequest($request));
    }

    #[TestWith(['client_id=a&client%5fid=b'])]
    #[TestWith(['scope=read&scope=write'])]
    #[TestWith(['client_id[]=a'])]
    #[TestWith(['state=&state=x'])]
    #[TestWith(['state=%FF'])]
    #[TestWith(['%FF=value'])]
    public function testInvalidParameterShapesAreRejected(string $encoded): void
    {
        $this->expectException(OAuthServerException::class);

        RequestParameters::parse($encoded);
    }

    public function testLargeEncodedRequestsAreRejected(): void
    {
        $this->expectException(OAuthServerException::class);

        RequestParameters::parse('state=' . str_repeat('a', 65536));
    }

    public function testNumericParameterNamesSurviveEncoding(): void
    {
        $encoded = '123=value&client_id=client';

        $this->assertSame($encoded, RequestParameters::toQuery(RequestParameters::parse($encoded)));
    }

    public function testConstructedRequestsRetainParsedInput(): void
    {
        $request = Request::create('/oauth/authorize', 'POST', [
            'client_id' => 'client', 'resource' => ['https://api.example/a'], 'scope' => null,
        ]);

        $this->assertSame([
            'client_id' => 'client', 'resource' => ['https://api.example/a'],
        ], RequestParameters::fromRequest($request));
    }

    public function testEmptyParametersAreOmittedWithoutTrimmingStateOrDroppingZero(): void
    {
        $parameters = ['response_mode' => '', 'request_uri' => '', 'dpop_jkt' => '', 'state' => ' 0 ', 'zero' => '0', 'resource' => ['', 'https://api.example/a', '']];
        $expected = ['state' => ' 0 ', 'zero' => '0', 'resource' => ['https://api.example/a']];

        $this->assertSame($expected, RequestParameters::parse(RequestParameters::toQuery($parameters)));
        $this->assertSame($expected, RequestParameters::fromRequest(Request::create('/oauth/authorize', 'POST', $parameters)));
        $this->assertSame($expected, RequestParameters::fromPsrRequest((new ServerRequest('POST', '/oauth/token'))->withParsedBody($parameters)));
        $this->assertSame([], RequestParameters::parse('resource=&resource='));
    }

    #[TestWith([['state' => "\xff"]])]
    #[TestWith([["\xff" => 'value']])]
    #[TestWith([['resource' => ["\xff"]]])]
    public function testParsedParametersRejectInvalidUtf8WithASerializableError(array $parameters): void
    {
        foreach ([
            static fn (): array => RequestParameters::fromRequest(Request::create('/oauth/authorize', 'POST', $parameters)),
            static fn (): array => RequestParameters::fromPsrRequest((new ServerRequest('POST', '/oauth/token'))->withParsedBody($parameters)),
        ] as $parse) {
            try {
                $parse();
                $this->fail('Invalid protocol text must be rejected.');
            } catch (OAuthServerException $exception) {
                $this->assertSame('invalid_request', $exception->getErrorType());
                $this->assertIsString(json_encode($exception->getPayload(), JSON_THROW_ON_ERROR));
            }
        }
    }
}
