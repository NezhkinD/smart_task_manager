<?php

namespace App\Enum\User;

enum RoleEnum: string
{
    case ROLE_ADMIN = 'admin';
    case ROLE_USER = 'user';
}
