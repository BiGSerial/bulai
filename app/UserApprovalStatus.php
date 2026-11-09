<?php

namespace App;

/** Situação do cadastro: quem se cadastra pelo site aguarda a aprovação de um administrador antes de entrar. */
enum UserApprovalStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Rejected = 'rejected';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Approved->value => 'Aprovado',
            self::Pending->value => 'Aguardando aprovação',
            self::Rejected->value => 'Cadastro recusado',
        ];
    }

    public function label(): string
    {
        return self::options()[$this->value];
    }
}
