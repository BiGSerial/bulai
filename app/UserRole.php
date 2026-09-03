<?php

namespace App;

enum UserRole: string
{
    case Admin = 'ADMIN';
    case User = 'USER';
}
