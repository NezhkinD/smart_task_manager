<?php

namespace App\Enum\InputMessage;

enum StatusEnum: string
{
    case NEW = 'new';
    case DONE = 'done';
    case FAIL = 'fail';
}
