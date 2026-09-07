<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\Fixtures\Controllers;

class DisallowedMethodNameController
{
    public function delete(): void
    {
    }

    public function deleteMethod(): void
    {
    }

    public function queryParams(): void
    {
    }

    public function applyUrlDefaults(): void
    {
    }

    public function validateParameters(): void
    {
    }

    public function formatRouteParameter(): void
    {
    }

    public function show(): void
    {
    }

    public function showForm(): void
    {
    }

    public function eval(): void
    {
    }

    public function arguments(): void
    {
    }

    public function DisallowedMethodNameController(): void
    {
    }

    public function DisallowedMethodNameControllerForm(): void
    {
    }
}
