<?php

namespace App\Enums;

enum MoyenPaiementAbonnement: string
{
    case EnLigne = 'en_ligne';
    case MobileMoney = 'mobile_money';
    case Especes = 'especes';
    case Virement = 'virement';
}
