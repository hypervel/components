<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Responses;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Response as HttpResponse;
use Hypervel\Support\Uri;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpFoundation\Response;

class AuthorizationResponse
{
    /**
     * Render an issued code without generating or encrypting it again.
     */
    public function success(Response $response, string $redirectUri, ?string $state, string $mode): Response
    {
        // League appends its code after existing callback query parameters; parse_str keeps the last value.
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY) ?? '', $parameters);

        return $this->render($redirectUri, [
            'code' => $parameters['code'],
            'state' => $state,
        ], $mode, $response->headers->all());
    }

    /**
     * Render a protocol error locally or to an already validated callback.
     */
    public function error(OAuthServerException $exception, ?string $redirectUri, ?string $state, string $mode): Response
    {
        if ($redirectUri === null) {
            return new JsonResponse($exception->getPayload(), $exception->getHttpStatusCode(), [
                ...$exception->getHttpHeaders(),
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ]);
        }

        return $this->render($redirectUri, [...$exception->getPayload(), 'state' => $state], $mode);
    }

    /**
     * Send protocol fields through the selected authorization response mode.
     *
     * @param array<string, null|string> $parameters
     * @param array<string, list<string>> $headers
     */
    protected function render(string $redirectUri, array $parameters, string $mode, array $headers = []): Response
    {
        $headers['Cache-Control'] = ['no-store'];
        $headers['Pragma'] = ['no-cache'];
        $headers['Referrer-Policy'] = ['no-referrer'];

        if ($mode === 'query') {
            $headers['Location'] = [$redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($parameters)];

            return new HttpResponse('', 302, $headers);
        }

        $nonce = base64_encode(random_bytes(16));
        $target = Uri::of($redirectUri)->getUri()
            ->withUserInfo(null)->withPath('')->withQuery(null)->withFragment(null)->toString();
        $headers['Content-Type'] = ['text/html; charset=UTF-8'];
        $headers['Content-Security-Policy'] = [
            "default-src 'none'; script-src 'nonce-{$nonce}'; form-action " . str_replace(';', '%3B', $target)
            . "; base-uri 'none'; frame-ancestors 'none'",
        ];
        unset($headers['location'], $headers['Location']);

        $fields = '';

        foreach ($parameters as $name => $value) {
            if ($value !== null) {
                $fields .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '" value="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            }
        }

        $action = htmlspecialchars($redirectUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new HttpResponse(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Continue to application</title></head><body>'
            . '<form method="post" action="' . $action . '">' . $fields
            . '<button type="submit">Continue</button></form><script nonce="' . $nonce
            . '">document.forms[0].submit();</script></body></html>',
            200,
            $headers,
        );
    }
}
