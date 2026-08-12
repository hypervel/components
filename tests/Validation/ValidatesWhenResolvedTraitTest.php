<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Validation\UnauthorizedException;
use Hypervel\Validation\ValidatesWhenResolvedTrait;

class ValidatesWhenResolvedTraitTest extends TestCase
{
    /**
     * Honor a boolean authorization denial without Foundation's FormRequest.
     */
    public function testRawTraitHonorsBooleanAuthorizationDenial(): void
    {
        $request = new BooleanAuthorizationRequest;

        $this->assertFalse($request->authorizationPasses());

        $this->expectException(UnauthorizedException::class);

        $request->validateResolved();
    }
}

class BooleanAuthorizationRequest
{
    use ValidatesWhenResolvedTrait;

    /**
     * Determine if the request is authorized.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Expose the trait result for testing.
     */
    public function authorizationPasses(): bool
    {
        return $this->passesAuthorization();
    }
}
