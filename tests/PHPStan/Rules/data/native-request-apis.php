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

    /**
     * @param array<mixed> $arguments
     */
    public function read(int $type, array $arguments): void
    {
        getallheaders();
        apache_request_headers();
        filter_input(INPUT_GET, 'page');
        filter_input_array(type: INPUT_POST);
        filter_has_var(INPUT_COOKIE, 'seen');
        filter_has_var(input_type: INPUT_GET, var_name: 'page');

        filter_input(INPUT_SERVER, 'REQUEST_URI');
        filter_input(INPUT_ENV, 'APP_NAME');
        filter_input($type, 'page');
        filter_input(...$arguments);
    }
}
