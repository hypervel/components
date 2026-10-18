<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Ui\Enums;

enum Permission: string
{
    case Camera = 'camera';
    case Microphone = 'microphone';
    case Geolocation = 'geolocation';
    case ClipboardWrite = 'clipboardWrite';
}
