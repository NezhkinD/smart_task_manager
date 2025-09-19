<?php

namespace App\Enum\Event;

enum TypeEnum: string
{
    case CREATED = 'created';
    case FAILED = 'failed';
}
