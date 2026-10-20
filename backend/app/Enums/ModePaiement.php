<?php

namespace App\Enums;

/**
 * Comment une commande est réglée. Distinct de StatutCommande : le mode dit
 * COMMENT le client paie, paiement_confirme_le dit SI l'argent est arrivé.
 */
enum ModePaiement: string
{
    case Cinetpay = 'cinetpay';
    case MobileMoney = 'mobile_money';
    case Especes = 'especes';
}
