<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Hypervel\Testing\TestResponseAssert as PHPUnit;

trait AssertsStatusCodes
{
    /**
     * Assert that the response has a 200 "OK" status code.
     *
     * @return $this
     */
    public function assertOk(): static
    {
        return $this->assertStatus(200);
    }

    /**
     * Assert that the response has a 201 "Created" status code.
     *
     * @return $this
     */
    public function assertCreated(): static
    {
        return $this->assertStatus(201);
    }

    /**
     * Assert that the response has a 202 "Accepted" status code.
     *
     * @return $this
     */
    public function assertAccepted(): static
    {
        return $this->assertStatus(202);
    }

    /**
     * Assert that the response has the given status code and no content.
     *
     * @return $this
     */
    public function assertNoContent(int $status = 204): static
    {
        $this->assertStatus($status);

        PHPUnit::assertEmpty($this->getContent(), 'Response content is not empty.');

        return $this;
    }

    /**
     * Assert that the response has a 301 "Moved Permanently" status code.
     *
     * @return $this
     */
    public function assertMovedPermanently(): static
    {
        return $this->assertStatus(301);
    }

    /**
     * Assert that the response has a 302 "Found" status code.
     *
     * @return $this
     */
    public function assertFound(): static
    {
        return $this->assertStatus(302);
    }

    /**
     * Assert that the response has a 304 "Not Modified" status code.
     *
     * @return $this
     */
    public function assertNotModified(): static
    {
        return $this->assertStatus(304);
    }

    /**
     * Assert that the response has a 307 "Temporary Redirect" status code.
     *
     * @return $this
     */
    public function assertTemporaryRedirect(): static
    {
        return $this->assertStatus(307);
    }

    /**
     * Assert that the response has a 308 "Permanent Redirect" status code.
     *
     * @return $this
     */
    public function assertPermanentRedirect(): static
    {
        return $this->assertStatus(308);
    }

    /**
     * Assert that the response has a 400 "Bad Request" status code.
     *
     * @return $this
     */
    public function assertBadRequest(): static
    {
        return $this->assertStatus(400);
    }

    /**
     * Assert that the response has a 401 "Unauthorized" status code.
     *
     * @return $this
     */
    public function assertUnauthorized(): static
    {
        return $this->assertStatus(401);
    }

    /**
     * Assert that the response has a 402 "Payment Required" status code.
     *
     * @return $this
     */
    public function assertPaymentRequired(): static
    {
        return $this->assertStatus(402);
    }

    /**
     * Assert that the response has a 403 "Forbidden" status code.
     *
     * @return $this
     */
    public function assertForbidden(): static
    {
        return $this->assertStatus(403);
    }

    /**
     * Assert that the response has a 404 "Not Found" status code.
     *
     * @return $this
     */
    public function assertNotFound(): static
    {
        return $this->assertStatus(404);
    }

    /**
     * Assert that the response has a 405 "Method Not Allowed" status code.
     *
     * @return $this
     */
    public function assertMethodNotAllowed(): static
    {
        return $this->assertStatus(405);
    }

    /**
     * Assert that the response has a 406 "Not Acceptable" status code.
     *
     * @return $this
     */
    public function assertNotAcceptable(): static
    {
        return $this->assertStatus(406);
    }

    /**
     * Assert that the response has a 408 "Request Timeout" status code.
     *
     * @return $this
     */
    public function assertRequestTimeout(): static
    {
        return $this->assertStatus(408);
    }

    /**
     * Assert that the response has a 409 "Conflict" status code.
     *
     * @return $this
     */
    public function assertConflict(): static
    {
        return $this->assertStatus(409);
    }

    /**
     * Assert that the response has a 410 "Gone" status code.
     *
     * @return $this
     */
    public function assertGone(): static
    {
        return $this->assertStatus(410);
    }

    /**
     * Assert that the response has a 415 "Unsupported Media Type" status code.
     *
     * @return $this
     */
    public function assertUnsupportedMediaType(): static
    {
        return $this->assertStatus(415);
    }

    /**
     * Assert that the response has a 422 "Unprocessable Content" status code.
     *
     * @return $this
     */
    public function assertUnprocessable(): static
    {
        return $this->assertStatus(422);
    }

    /**
     * Assert that the response has a 424 "Failed Dependency" status code.
     *
     * @return $this
     */
    public function assertFailedDependency(): static
    {
        return $this->assertStatus(424);
    }

    /**
     * Assert that the response has a 429 "Too Many Requests" status code.
     *
     * @return $this
     */
    public function assertTooManyRequests(): static
    {
        return $this->assertStatus(429);
    }

    /**
     * Assert that the response has a 500 "Internal Server Error" status code.
     *
     * @return $this
     */
    public function assertInternalServerError(): static
    {
        return $this->assertStatus(500);
    }

    /**
     * Assert that the response has a 503 "Service Unavailable" status code.
     *
     * @return $this
     */
    public function assertServiceUnavailable(): static
    {
        return $this->assertStatus(503);
    }
}
