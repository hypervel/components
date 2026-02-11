<?php

declare(strict_types=1);

namespace Hypervel\Mail;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file of mail.',
                    'source' => __DIR__ . '/../publish/mail.php',
                    'destination' => BASE_PATH . '/config/autoload/mail.php',
                ],
                [
                    'id' => 'resources',
                    'description' => 'The resources for mail.',
                    'source' => __DIR__ . '/../publish/resources/views/',
                    'destination' => BASE_PATH . '/storage/view/mail/',
                ],
            ],
        ];
    }
}
