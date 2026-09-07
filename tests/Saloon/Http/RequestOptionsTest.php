<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use ErrorException;
use Hypervel\Saloon\Traits\RequestProperties\HasOptions;
use Hypervel\Tests\TestCase;

class RequestOptionsTest extends TestCase
{
    public function testMaxRedirectsPreservesArraySettings(): void
    {
        $request = $this->request()
            ->withOptions(['allow_redirects' => ['strict' => true]])
            ->maxRedirects(3);

        $this->assertSame(['strict' => true, 'max' => 3], $request->options()['allow_redirects']);
    }

    public function testMaxRedirectsReenablesRedirectsWithoutDeprecation(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }, E_DEPRECATED);

        try {
            $request = $this->request()
                ->withoutRedirecting()
                ->maxRedirects(3);

            $this->assertSame(['max' => 3], $request->options()['allow_redirects']);
        } finally {
            restore_error_handler();
        }
    }

    public function testWithoutRedirectingDisablesAConfiguredRedirectLimit(): void
    {
        $request = $this->request()
            ->maxRedirects(3)
            ->withoutRedirecting();

        $this->assertFalse($request->options()['allow_redirects']);
    }

    public function testMaxRedirectsReplacesTheBooleanEnabledForm(): void
    {
        $request = $this->request()
            ->withOptions(['allow_redirects' => true])
            ->maxRedirects(3);

        $this->assertSame(['max' => 3], $request->options()['allow_redirects']);
    }

    /**
     * Create an object with operation-owned request options.
     */
    protected function request(): object
    {
        return new class {
            use HasOptions;
        };
    }
}
