<?php

namespace App\Enum\Task;

enum TypeEnum: string
{
    case CREATED = 'created';
    case FAILED = 'failed';
    case DONE = 'done';
}
