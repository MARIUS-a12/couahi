<?php

namespace App\Enums;

enum OperateurMobileMoney: string
{
    case Wave = 'wave';
    case OrangeMoney = 'orange_money';
    case Mtn = 'mtn';
    case Moov = 'moov';

    /** Le nom tel que le client le connaît, jamais l'identifiant technique. */
    public function libelle(): string
    {
        return match ($this) {
            self::Wave => 'Wave',
            self::OrangeMoney => 'Orange Money',
            self::Mtn => 'MTN MoMo',
            self::Moov => 'Moov Money',
        };
    }
}
