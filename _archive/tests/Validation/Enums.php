<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

enum StringStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
}

enum IntegerStatus: int
{
    case Pending = 1;
    case Done = 2;
}

enum PureEnum
{
    case one;
    case two;
}

enum ArrayKeys
{
    case key_1;
    case key_2;
    case key_3;
}

enum ArrayKeysBacked: string
{
    case Key1 = 'key_1';
    case Key2 = 'key_2';
    case Key3 = 'key_3';
}
