<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\NativeRequestApis;

function session_flash(): void
{
}

class LegacyController
{
    public function show(): string
    {
        header('X-Legacy: 1');
        http_response_code(201);
        session_start();
        session_create_id();
        session_flash();

        if (connection_aborted()) {
            return '';
        }

        $_POST['name'] = 'Taylor';
        $_SESSION['seen'] = true;

        return $_GET['page'] . $_SERVER['REQUEST_URI'] . $_ENV['APP_NAME'];
    }
}
