<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Hypervel\Testing\TestResponseAssert as PHPUnit;

trait AssertsStatusCodes
{
    /**
     * Assert that the response has a 200 "OK" status code.
     */
    public function assertOk(): static
    {
        return $this->assertStatus(200);
    }

    /**
     * Assert that the response has a 201 "Created" status code.
     */
    public function assertCreated(): static
    {
        return $this->assertStatus(201);
    }

    /**
     * Assert that the response has a 202 "Accepted" status code.
     */
    public function assertAccepted(): static
    {
        return $this->assertStatus(202);
    }

    /**
     * Assert that the response has the given status code and no content.
     */
    public function assertNoContent(int $status = 204): static
    {
        $this->assertStatus($status);

        PHPUnit::assertEmpty($this->getContent(), 'Response content is not empty.');

        return $this;
    }

    /**
     * Assert that the response has a 301 "Moved Permanently" status code.
     */
    public function assertMovedPermanently(): static
    {
        return $this->assertStatus(301);
    }

    /**
     * Assert that the response has a 302 "Found" status code.
     */
    public function assertFound(): static
    {
        return $this->assertStatus(302);
    }

    /**
     * Assert that the response has a 304 "Not Modified" status code.
     */
    public function assertNotModified(): static
    {
        return $this->assertStatus(304);
    }

    /**
     * Assert that the response has a 307 "Temporary Redirect" status code.
     */
    public function assertTemporaryRedirect(): static
    {
        return $this->assertStatus(307);
    }

    /**
     * Assert that the response has a 308 "Permanent Redirect" status code.
     */
    public function assertPermanentRedirect(): static
    {
        return $this->assertStatus(308);
    }

    /**
     * Assert that the response has a 400 "Bad Request" status code.
     */
    public function assertBadRequest(): static
    {
        return $this->assertStatus(400);
    }

    /**
     * Assert that the response has a 401 "Unauthorized" status code.
     */
    public function assertUnauthorized(): static
    {
        return $this->assertStatus(401);
    }

    /**
     * Assert that the response has a 402 "Payment Required" status code.
     */
    public function assertPaymentRequired(): static
    {
        return $this->assertStatus(402);
    }

    /**
     * Assert that the response has a 403 "Forbidden" status code.
     */
    public function assertForbidden(): static
    {
        return $this->assertStatus(403);
    }

    /**
     * Assert that the response has a 404 "Not Found" status code.
     */
    public function assertNotFound(): static
    {
        return $this->assertStatus(404);
    }

    /**
     * Assert that the response has a 405 "Method Not Allowed" status code.
     */
    public function assertMethodNotAllowed(): static
    {
        return $this->assertStatus(405);
    }

    /**
     * Assert that the response has a 406 "Not Acceptable" status code.
     */
    public function assertNotAcceptable(): static
    {
        return $this->assertStatus(406);
    }

    /**
     * Assert that the response has a 408 "Request Timeout" status code.
     */
    public function assertRequestTimeout(): static
    {
        return $this->assertStatus(408);
    }

    /**
     * Assert that the response has a 409 "Conflict" status code.
     */
    public function assertConflict(): static
    {
        return $this->assertStatus(409);
    }

    /**
     * Assert that the response has a 410 "Gone" status code.
     */
    public function assertGone(): static
    {
        return $this->assertStatus(410);
    }

    /**
     * Assert that the response has a 415 "Unsupported Media Type" status code.
     */
    public function assertUnsupportedMediaType(): static
    {
        return $this->assertStatus(415);
    }

    /**
     * Assert that the response has a 422 "Unprocessable Content" status code.
     */
    public function assertUnprocessable(): static
    {
        return $this->assertStatus(422);
    }

    /**
     * Assert that the response has a 424 "Failed Dependency" status code.
     */
    public function assertFailedDependency(): static
    {
        return $this->assertStatus(424);
    }

    /**
     * Assert that the response has a 429 "Too Many Requests" status code.
     */
    public function assertTooManyRequests(): static
    {
        return $this->assertStatus(429);
    }

    /**
     * Assert that the response has a 500 "Internal Server Error" status code.
     */
    public function assertInternalServerError(): static
    {
        return $this->assertStatus(500);
    }

    /**
     * Assert that the response has a 503 "Service Unavailable" status code.
     */
    public function assertServiceUnavailable(): static
    {
        return $this->assertStatus(503);
    }
}
