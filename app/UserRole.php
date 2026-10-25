<?php

namespace App;

enum UserRole: string
{
    case Admin = 'ADMIN';
    case User = 'USER';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Admin->value => 'Administrador',
            self::User->value => 'Usuário',
        ];
    }

    public function label(): string
    {
        return self::options()[$this->value];
    }
}
